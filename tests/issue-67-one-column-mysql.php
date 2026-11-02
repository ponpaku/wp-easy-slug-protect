<?php
/**
 * #67 one-column feasibility probe. DISPOSABLE MYSQL ONLY; NOT production code.
 *
 * Existing state row + one active_mutations TEXT column. Exercise actual
 * MySQL conditional UPDATE replay, independent connections, READY publication,
 * crash residue, and a caller-owned transaction connection-loss counterexample.
 *
 * This test deliberately does not assert that the design is production-safe.
 */
declare(strict_types=1);

if (getenv('ESP_TEST_DB_NAME') !== 'esp_test') {
    fwrite(STDERR, "Refusing non-disposable database\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function db67(): mysqli {
    $db = new mysqli(
        getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('ESP_TEST_DB_USER') ?: 'root',
        getenv('ESP_TEST_DB_PASSWORD') ?: '',
        'esp_test'
    );
    $db->set_charset('utf8mb4');
    return $db;
}
function check67(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('FAIL #67: ' . $message); }
    echo 'PASS #67: ' . $message . "\n";
}
function row67(mysqli $db): array {
    $r = $db->query('SELECT status,revision,pending_count,active_mutations FROM esp67_state_probe WHERE attachment_id=123')->fetch_assoc();
    if (!$r) { throw new RuntimeException('missing state row'); }
    $ids = json_decode($r['active_mutations'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($ids) || !array_is_list($ids) || count($ids) !== count(array_unique($ids)) ||
        count(array_filter($ids, 'is_string')) !== count($ids) ||
        count($ids) !== (int)$r['pending_count']) {
        throw new RuntimeException('corrupt or inconsistent mutation set');
    }
    return $r;
}
/** Apply a transition with a frozen expected snapshot: exactly the same SQL
 * and parameters can be executed twice after the first ACK is lost.
 */
function write67(mysqli $db, array $before, array $ids): int {
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_STRING);
    $encoded = json_encode($ids, JSON_THROW_ON_ERROR);
    $count = count($ids);
    $old = $before['active_mutations'];
    $rev = (int)$before['revision'];
    $oldCount = (int)$before['pending_count'];
    $stmt = $db->prepare(
        "UPDATE esp67_state_probe
         SET active_mutations=?, pending_count=?, status='dirty', revision=revision+1
         WHERE attachment_id=123 AND revision=?
           AND BINARY active_mutations=BINARY ? AND pending_count=?"
    );
    $stmt->bind_param('siisi', $encoded, $count, $rev, $old, $oldCount);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed;
}
function change67(mysqli $db, string $id, bool $start): void {
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $before = row67($db);
        $ids = json_decode($before['active_mutations'], true, 512, JSON_THROW_ON_ERROR);
        $has = in_array($id, $ids, true);
        if ($has === $start) { return; }
        if ($start) { $ids[] = $id; }
        else { $ids = array_values(array_diff($ids, [$id])); }
        if (write67($db, $before, $ids) === 1) { return; }
    }
    throw new RuntimeException('CAS could not converge');
}
function publish67(mysqli $db): int {
    $before = row67($db);
    $rev = (int)$before['revision'];
    $stmt = $db->prepare(
        "UPDATE esp67_state_probe SET status='ready'
         WHERE attachment_id=123 AND status='dirty'
           AND revision=? AND pending_count=0 AND active_mutations='[]'"
    );
    $stmt->bind_param('i', $rev);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed;
}

$a = db67();
$b = db67();
try {
    foreach (['esp67_state_probe', 'esp67_meta_probe'] as $table) {
        $a->query('DROP TABLE IF EXISTS ' . $table);
    }
    $a->query("CREATE TABLE esp67_state_probe (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        pending_count INT UNSIGNED NOT NULL,
        active_mutations LONGTEXT NOT NULL
    ) ENGINE=InnoDB");
    $a->query("CREATE TABLE esp67_meta_probe (
        attachment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        canonical_value VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB");
    $a->query("INSERT INTO esp67_state_probe VALUES(123,'ready',1,0,'[]')");
    $a->query("INSERT INTO esp67_meta_probe VALUES(123,'public')");

    // SQL replay after a successful but unacknowledged start.
    $r = row67($a);
    check67(write67($a, $r, ['A']) === 1, 'first start applies');
    check67(write67($a, $r, ['A']) === 0, 'same start SQL replay cannot double-add');
    check67((int)row67($b)['pending_count'] === 1, 'second connection observes one A');

    // Two independent connections, plus a stale snapshot CAS.
    $stale = row67($a);
    change67($b, 'B', true);
    check67(write67($a, $stale, ['A','C']) === 0, 'stale writer cannot overwrite B');
    change67($a, 'C', true);
    check67((int)row67($b)['pending_count'] === 3, 'CAS retry retains A B C');

    $beforeA = row67($a);
    check67(write67($a, $beforeA, ['B','C']) === 1, 'A completes');
    check67(write67($a, $beforeA, ['B','C']) === 0, 'same completion SQL replay cannot consume B or C');
    $r = row67($b);
    check67((int)$r['pending_count'] === 2 &&
        $r['active_mutations'] === '["B","C"]', 'other workers stay active after replay');
    check67(publish67($a) === 0, 'READY publication is blocked with other writers');

    change67($b, 'B', false);
    change67($a, 'C', false);
    check67(publish67($b) === 1, 'READY only after last active ID removed');
    check67(row67($a)['status'] === 'ready', 'published state is visible');

    // A new mutation after READY must revoke it in a single CAS update.
    change67($a, 'AFTER_READY', true);
    check67(row67($b)['status'] === 'dirty' && publish67($b) === 0,
        'new pre-write mutation revokes READY');
    // Simulated request disappearance: the ID is deliberately retained.
    $a->close();
    $a = db67();
    check67(row67($a)['active_mutations'] === '["AFTER_READY"]' &&
        publish67($b) === 0, 'connection loss leaves durable ID and blocks rebuild');
    echo "EXPECTED LIMITATION #67: a dead process leaves a durable ID; no automatic safe reclaim\n";

    // Independent transaction-loss case: caller-owned transaction holds
    // state fencing and WordPress canonical write together, then disconnects.
    // A Core-style retry of only the canonical SQL can commit without the fence.
    change67($a, 'AFTER_READY', false);
    check67(publish67($b) === 1, 'reset to READY before transaction probe');
    $a->begin_transaction();
    change67($a, 'TX_A', true);
    $a->query("UPDATE esp67_meta_probe SET canonical_value='private' WHERE attachment_id=123");
    // Both changes are uncommitted. Closing this connection rolls both back.
    $a->close();
    $a = db67();
    check67(row67($a)['status'] === 'ready' &&
        row67($a)['active_mutations'] === '[]', 'uncommitted state fence rolled back');
    // This SQL is a modeled post-reconnection replay, not actual wpdb.
    $b->query("UPDATE esp67_meta_probe SET canonical_value='private' WHERE attachment_id=123");
    $value = $b->query('SELECT canonical_value FROM esp67_meta_probe WHERE attachment_id=123')->fetch_assoc();
    check67($value['canonical_value'] === 'private' && row67($a)['status'] === 'ready',
        'COUNTEREXAMPLE: replay of canonical write alone leaves READY without fence');
    echo "EXPECTED LIMITATION #67: one-column set does NOT solve caller transaction lost on reconnect\n";
    // Two-connection durable fencing has an important lock-order constraint:
    // if the caller has already locked the state row, an independent guard
    // cannot commit a durable fence on that same row before the caller commits.
    $a->begin_transaction();
    $a->query('SELECT revision FROM esp67_state_probe WHERE attachment_id=123 FOR UPDATE');
    $b->query('SET SESSION innodb_lock_wait_timeout=1');
    $blocked = false;
    try {
        $b->query("UPDATE esp67_state_probe SET status='dirty' WHERE attachment_id=123");
    } catch (mysqli_sql_exception $e) {
        $blocked = (int)$e->getCode() === 1205;
    } finally {
        $a->rollback();
    }
    check67($blocked, 'independent durable fence blocks if caller transaction already owns state row');
    echo "EXPECTED LIMITATION #67: out-of-band fencing must run before caller locks state (or fail closed)\n";


    // Proposed PRODUCTION-safe global coverage publication uses a conditional
    // CAS rather than a DB trigger. Test on both MySQL 8 and MariaDB 10.11.
    // Audit starts with a snapshot of epoch 11, then an independent WP writer
    // registers a global ID, bumping epoch and keeping coverage dirty.
    $a->query("INSERT INTO esp67_state_probe
        (attachment_id,status,revision,pending_count,active_mutations)
        VALUES(0,'dirty',11,0,'[]')");
    $baseline = $a->query("SELECT revision FROM esp67_state_probe
        WHERE attachment_id=0")->fetch_assoc();
    $audit_epoch = (int)$baseline['revision'];
    $pub = $a->prepare("UPDATE esp67_state_probe SET status='ready'
        WHERE attachment_id=0 AND revision=? AND status='dirty'
          AND pending_count=0 AND BINARY active_mutations=BINARY '[]'");
    $pub->bind_param('i',$audit_epoch);

    $b->begin_transaction();
    $registered = $b->query("UPDATE esp67_state_probe SET
        status='dirty',revision=revision+1,pending_count=1,
        active_mutations='[\"G1\"]'
        WHERE attachment_id=0 AND revision=11
          AND pending_count=0 AND active_mutations='[]'");
    check67($registered && $b->affected_rows===1,
        'independent global writer registered ID before canonical operation');
    $b->commit();
    $pub->execute();
    check67($pub->affected_rows===0,
        'stale pre-write audit epoch cannot publish READY after global ID registration');
    $audit_epoch=12;
    $pub->execute();
    check67($pub->affected_rows===0,
        'even fresh audit epoch refuses READY while a global ticket is pending');

    // A malformed READY row must NOT grant access just because status looks
    // correct. Product resolver should check status+count+set consistently.
    $b->query("UPDATE esp67_state_probe SET status='ready'
        WHERE attachment_id=0 AND revision=12");
    $coverage = $a->query("SELECT (status='ready' AND
        pending_count=0 AND BINARY active_mutations=BINARY '[]') AS allowed
        FROM esp67_state_probe WHERE attachment_id=0")->fetch_assoc();
    check67((int)$coverage['allowed']===0,
        'resolver-side complete predicate refuses malformed READY with active global ticket');
    $b->query("UPDATE esp67_state_probe SET status='dirty',
        revision=revision+1,pending_count=0,active_mutations='[]'
        WHERE attachment_id=0 AND revision=12");
    $audit_epoch=13;
    $pub->execute();
    check67($pub->affected_rows===1,
        'after final global ID removal, fresh audit epoch can publish READY');
    $pub->execute();
    check67($pub->affected_rows===0,
        'same audited publication replay has no duplicate side effect');
    $pub->close();
    echo "PASS #67 GLOBAL SQL: READY CAS and resolver predicate block audit/write race on real DB\n";

    // Alternative architecture for clusters: an actual canonical UPDATE
    // must include a server-side ticket predicate AND lock that state row.
    // Without such enforcement, a time-expired lease cannot stop a paused
    // PHP writer / Core SQL replay. This is NOT presently done by WordPress.
    change67($a,'SQL_FENCE',true);
    $guarded = row67($a);
    check67($guarded['active_mutations']==='["SQL_FENCE"]',
        'SQL-level write gate token registered');
    $guarded_sql = "UPDATE esp67_meta_probe AS m
        INNER JOIN esp67_state_probe AS s
          ON s.attachment_id=m.attachment_id
        SET m.canonical_value='fenced_change'
        WHERE m.attachment_id=123
          AND s.status='dirty' AND s.pending_count=1
          AND BINARY s.active_mutations=BINARY '[\"SQL_FENCE\"]'";
    $a->begin_transaction();
    check67($a->query($guarded_sql)===true && $a->affected_rows===1,
        'canonical UPDATE uses server-side state-ticket JOIN');
    $b->query('SET SESSION innodb_lock_wait_timeout=1');
    $blocked_by_canonical = false;
    try {
        $b->query("UPDATE esp67_state_probe SET
            active_mutations='[]',pending_count=0,revision=revision+1
            WHERE attachment_id=123");
    } catch (mysqli_sql_exception $e) {
        $blocked_by_canonical = (int)$e->getCode()===1205;
    }
    check67($blocked_by_canonical,
        'server-side canonical UPDATE holds state lock against premature GC');
    $a->commit();
    change67($b,'SQL_FENCE',false);
    $replay = $b->query($guarded_sql);
    check67($replay===true && $b->affected_rows===0,
        'SQL replay after token retirement cannot modify canonical owner');

    echo "RESULT #67: SQL idempotence YES; overlap YES; crash recovery NO; external transaction retry NO\n";
} finally {
    $clean = db67();
    foreach (['esp67_meta_probe', 'esp67_state_probe'] as $table) {
        $clean->query('DROP TABLE IF EXISTS ' . $table);
    }
    $clean->close();
    $a->close();
    $b->close();
}
