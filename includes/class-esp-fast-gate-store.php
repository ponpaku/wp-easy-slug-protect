<?php
/**
 * Fast Gate v2 local ledger. This class has no WordPress dependencies and
 * never treats a missing media row as public. SQLite is an opt-in dependency.
 */
final class ESP_Fast_Gate_Store {
    public const SCHEMA = '3';
    private $db;
    private $writable;

    private function __construct($db, $writable) {
        $this->db = $db;
        $this->writable = $writable;
    }

    public static function open($file, $write = false) {
        if (!class_exists('SQLite3') || !is_string($file) || $file === '' ||
            (!is_file($file) && !$write) || is_link($file)) {
            return null;
        }
        try {
            $flags = $write ? SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE :
                SQLITE3_OPEN_READONLY;
            $db = new SQLite3($file, $flags);
            $db->enableExceptions(true);
            $db->busyTimeout(1500);
            if ($write) {
                $db->exec('PRAGMA journal_mode=WAL');
                $db->exec('PRAGMA synchronous=FULL');
            } else {
                $db->exec('PRAGMA query_only=ON');
            }
            return new self($db, $write);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tx($callback) {
        if (!$this->writable) { return false; }
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            $callback();
            $this->db->exec('COMMIT');
            return true;
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (\Throwable $ignored) {}
            return false;
        }
    }

    public function install() {
        return $this->tx(function () {
            $this->db->exec("CREATE TABLE IF NOT EXISTS meta (
                k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS media (
                path_hash TEXT PRIMARY KEY NOT NULL,
                file_path TEXT NOT NULL,
                policy TEXT NOT NULL CHECK(policy IN ('public','protected','deny')),
                path_id TEXT NOT NULL DEFAULT '')");
            $this->db->exec("CREATE TABLE IF NOT EXISTS owner_media (
                attachment_id INTEGER NOT NULL, path_hash TEXT NOT NULL,
                file_path TEXT NOT NULL, PRIMARY KEY(attachment_id,path_hash))");
            $this->db->exec("CREATE TABLE IF NOT EXISTS owner_epoch (
                attachment_id INTEGER PRIMARY KEY NOT NULL,
                epoch INTEGER NOT NULL DEFAULT 0)");
            // Path-level CAS prevents different owners from overwriting one
            // another's newly published authorization for the same file.
            $this->db->exec("CREATE TABLE IF NOT EXISTS path_epoch (
                path_hash TEXT PRIMARY KEY NOT NULL,
                epoch INTEGER NOT NULL DEFAULT 0)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS fenced (
                attachment_id INTEGER NOT NULL, path_hash TEXT NOT NULL,
                file_path TEXT NOT NULL, PRIMARY KEY(attachment_id,path_hash))");
            $this->db->exec("CREATE INDEX IF NOT EXISTS fenced_hash ON fenced(path_hash)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS paths (
                path_id TEXT PRIMARY KEY NOT NULL,
                password_version INTEGER NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS revoked (
                session_ref TEXT PRIMARY KEY NOT NULL,
                retain_until INTEGER NOT NULL,
                db_deleted INTEGER NOT NULL DEFAULT 0)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS revoked_gc
                ON revoked(db_deleted,retain_until)");
            $this->db->exec("INSERT OR IGNORE INTO meta(k,v) VALUES
                ('schema','3'), ('ready','0'), ('serving','0')");
        }) && $this->schema_ok();
    }

    public function schema_ok() {
        try {
            $schema = $this->db->querySingle(
                "SELECT v FROM meta WHERE k='schema'");
            return $schema === self::SCHEMA;
        } catch (\Throwable $e) { return false; }
    }

    public function ready() {
        try {
            return $this->schema_ok() &&
                $this->db->querySingle("SELECT v FROM meta WHERE k='ready'") === '1';
        } catch (\Throwable $e) { return false; }
    }

    public function set_ready($ready) {
        return $this->tx(function () use ($ready) {
            if (!$ready) { $this->db->exec("UPDATE meta SET v='0' WHERE k='serving'"); }
            $stmt = $this->db->prepare("UPDATE meta SET v=:v WHERE k='ready'");
            $stmt->bindValue(':v', $ready ? '1' : '0', SQLITE3_TEXT);
            if (!$stmt->execute()) { throw new \RuntimeException('state'); }
        });
    }

    /**
     * Physical routing and the decision to serve are separate from a
     * successfully built catalog. Provision and build never enable serving.
     */
    public function serving() {
        try {
            return $this->ready() &&
                $this->db->querySingle("SELECT v FROM meta WHERE k='serving'") === '1';
        } catch (\Throwable $e) { return false; }
    }

    public function set_serving($on) {
        if ($on && !$this->ready()) { return false; }
        return $this->tx(function () use ($on) {
            $stmt = $this->db->prepare("UPDATE meta SET v=:value WHERE k='serving'");
            $stmt->bindValue(':value', $on ? '1' : '0', SQLITE3_TEXT);
            if (!$stmt->execute() || $this->db->changes() !== 1) {
                throw new \RuntimeException('serving marker missing');
            }
        });
    }

    /**
     * Derive the signing key from a fresh per-activation nonce. An operator
     * can temporarily disable Fast Gate while users log out using legacy
     * MySQL sessions (without SQLite revocations). Rotating on EVERY
     * re-activation invalidates those old copied signed tickets.
     * The nonce is not itself secret; the site key remains in keys.php.
     */
    public function ticket_signing_key($site_key) {
        if (!is_string($site_key) || strlen($site_key) < 32 ||
            !$this->schema_ok()) { return null; }
        try {
            $nonce = $this->db->querySingle(
                "SELECT v FROM meta WHERE k='ticket_nonce'");
            if ($nonce === null) {
                // Compatible with PR #81's not-yet-activated disposable
                // benchmarks and direct authorization unit tests.
                return $site_key;
            }
            if (!is_string($nonce) ||
                !preg_match('/^[a-f0-9]{64}$/D', $nonce)) { return null; }
            return hash_hmac('sha256',
                "ESP_FAST_GATE_SIGNING_EPOCH_V1\0" . $nonce, $site_key);
        } catch (\Throwable $e) { return null; }
    }

    /** Must run with the HTTP reader STOPPED, after final catalog validation. */
    public function rotate_ticket_signing_key() {
        if (!$this->ready() || $this->serving()) { return false; }
        try {
            $nonce = bin2hex(random_bytes(32));
        } catch (\Throwable $e) { return false; }
        return $this->tx(function () use ($nonce) {
            if ($this->db->querySingle(
                "SELECT v FROM meta WHERE k='serving'") !== '0') {
                throw new \RuntimeException('cannot rotate while serving');
            }
            $stmt = $this->db->prepare(
                "INSERT INTO meta(k,v) VALUES('ticket_nonce', :nonce)
                 ON CONFLICT(k) DO UPDATE SET v=excluded.v");
            $stmt->bindValue(':nonce', $nonce, SQLITE3_TEXT);
            if (!$stmt->execute()) {
                throw new \RuntimeException('ticket nonce generation');
            }
        });
    }

    public function set_path($path, $version, $blocked = false) {
        if (!is_string($path) || $path === '' || !is_int($version) || $version < 0) {
            return false;
        }
        return $this->tx(function () use ($path, $version, $blocked) {
            $stmt = $this->db->prepare("INSERT INTO paths(path_id,password_version,blocked)
                VALUES(:id,:version,:blocked)
                ON CONFLICT(path_id) DO UPDATE SET
                  password_version=MAX(paths.password_version,excluded.password_version),
                  blocked=CASE WHEN excluded.password_version>paths.password_version
                    THEN excluded.blocked ELSE paths.blocked END");
            $stmt->bindValue(':id', $path, SQLITE3_TEXT);
            $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
            $stmt->bindValue(':blocked', $blocked ? 1 : 0, SQLITE3_INTEGER);
            if (!$stmt->execute()) { throw new \RuntimeException('path'); }
        });
    }

    /**
     * One SQLite transaction for a multi-path pre-save password revocation.
     * Partial failures never block only the first path in a settings batch.
     */
    public function block_paths($paths) {
        if (!is_array($paths) || !$paths) { return false; }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') { return false; }
        }
        return $this->tx(function () use ($paths) {
            $stmt = $this->db->prepare(
                'UPDATE paths SET blocked=1 WHERE path_id=:id');
            foreach (array_unique($paths) as $path) {
                $stmt->bindValue(':id', $path, SQLITE3_TEXT);
                if (!$stmt->execute() || $this->db->changes() !== 1) {
                    throw new \RuntimeException('protected path missing');
                }
                $stmt->reset();
            }
        });
    }

    /** An existing password version may never be unblocked by a delayed write. */
    public function block_path($path) {
        if (!is_string($path) || $path === '') { return false; }
        return $this->tx(function () use ($path) {
            $stmt = $this->db->prepare(
                "UPDATE paths SET blocked=1 WHERE path_id=:id");
            $stmt->bindValue(':id', $path, SQLITE3_TEXT);
            $stmt->execute();
            if ($this->db->changes() !== 1) {
                throw new \RuntimeException('missing protected path');
            }
        });
    }

    public function get_path($path) {
        try {
            if (!$this->ready()) { return null; }
            $stmt = $this->db->prepare(
                "SELECT password_version,blocked FROM paths WHERE path_id=:id");
            $stmt->bindValue(':id', $path, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$row) { return null; }
            return ['version' => (int)$row['password_version'],
                'blocked' => (int)$row['blocked'] === 1];
        } catch (\Throwable $e) { return null; }
    }

    /** Exact-path lookup; never infer public permission from an absent row. */
    public function get_media($path) {
        try {
            if (!$this->ready() || !is_string($path) || $path === '') {
                return null;
            }
            $hash = hash('sha256', $path);
            $check = $this->db->prepare(
                "SELECT 1 FROM fenced WHERE path_hash=:hash LIMIT 1");
            $check->bindValue(':hash', $hash, SQLITE3_TEXT);
            if ($check->execute()->fetchArray(SQLITE3_NUM) !== false) {
                return null;
            }
            $stmt = $this->db->prepare(
                "SELECT file_path,policy,path_id FROM media WHERE path_hash=:hash");
            $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$row || !is_string($row['file_path']) ||
                !hash_equals($row['file_path'], $path) ||
                !in_array($row['policy'], ['public', 'protected', 'deny'], true)) {
                return null;
            }
            return ['policy' => $row['policy'], 'path_id' => $row['path_id']];
        } catch (\Throwable $e) { return null; }
    }

    /** Internal indexing only. For an incomplete index, leave ready=0. */
    public function replace_media($records) {
        if (!is_array($records)) { return false; }
        return $this->tx(function () use ($records) {
            $this->db->exec("UPDATE meta SET v='0' WHERE k IN ('ready','serving')");
            $this->db->exec('DELETE FROM media');
            $stmt = $this->db->prepare(
                "INSERT INTO media(path_hash,file_path,policy,path_id)
                 VALUES(:hash,:path,:policy,:id)");
            foreach ($records as $file => $record) {
                if (!is_string($file) || $file === '' ||
                    !is_array($record) ||
                    !in_array($record['policy'] ?? null,
                              ['public', 'protected', 'deny'], true) ||
                    !is_string($record['path_id'] ?? null)) {
                    throw new \RuntimeException('bad record');
                }
                $stmt->bindValue(':hash', hash('sha256', $file), SQLITE3_TEXT);
                $stmt->bindValue(':path', $file, SQLITE3_TEXT);
                $stmt->bindValue(':policy', $record['policy'], SQLITE3_TEXT);
                $stmt->bindValue(':id', $record['path_id'], SQLITE3_TEXT);
                if (!$stmt->execute()) { throw new \RuntimeException('insert'); }
                $stmt->reset();
            }
            // A caller must separately verify coverage before enabling.
        });
    }

    /** All-or-nothing path catalog replacement while the media gate is stopped. */
    public function replace_paths($paths) {
        if (!is_array($paths)) { return false; }
        return $this->tx(function () use ($paths) {
            $this->db->exec("UPDATE meta SET v='0' WHERE k IN ('ready','serving')");
            $this->db->exec('DELETE FROM paths');
            $stmt = $this->db->prepare(
                'INSERT INTO paths(path_id,password_version,blocked) VALUES(:id,:version,0)');
            foreach ($paths as $id => $version) {
                if (!is_string($id) && !is_int($id) ||
                    !is_int($version) || $version < 0 ||
                    !preg_match('/^[A-Za-z0-9_-]{1,191}$/D', (string)$id)) {
                    throw new \RuntimeException('invalid protected path');
                }
                $stmt->bindValue(':id', (string)$id, SQLITE3_TEXT);
                $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
                if (!$stmt->execute()) { throw new \RuntimeException('path replace'); }
                $stmt->reset();
            }
        });
    }

    public function counts() {
        try {
            if (!$this->schema_ok()) { return null; }
            return [
                'media' => (int)$this->db->querySingle('SELECT COUNT(*) FROM media'),
                'paths' => (int)$this->db->querySingle('SELECT COUNT(*) FROM paths'),
                'revoked' => (int)$this->db->querySingle('SELECT COUNT(*) FROM revoked'),
                'unconfirmed_revoked' => (int)$this->db->querySingle(
                    'SELECT COUNT(*) FROM revoked WHERE db_deleted=0'),
            ];
        } catch (\Throwable $e) { return null; }
    }

    /** Persist a per-owner deny fence BEFORE canonical WordPress metadata changes. */
    public function fence_owner($id, $candidate_paths) {
        if (!is_int($id) || $id < 1 || !is_array($candidate_paths)) {
            return false;
        }
        foreach ($candidate_paths as $path) {
            if (!self::safe_media_path($path)) { return false; }
        }
        return $this->tx(function () use ($id, $candidate_paths) {
            $bump = $this->db->prepare(
                "INSERT INTO owner_epoch(attachment_id,epoch) VALUES(:id,1)
                 ON CONFLICT(attachment_id) DO UPDATE SET epoch=epoch+1");
            $bump->bindValue(':id', $id, SQLITE3_INTEGER);
            $bump->execute();
            $paths = [];
            $old = $this->db->prepare(
                "SELECT file_path FROM owner_media WHERE attachment_id=:id");
            $old->bindValue(':id', $id, SQLITE3_INTEGER);
            $results = $old->execute();
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $paths[$row['file_path']] = true;
            }
            foreach ($candidate_paths as $path) { $paths[$path] = true; }
            $insert = $this->db->prepare(
                "INSERT OR IGNORE INTO fenced(attachment_id,path_hash,file_path)
                 VALUES(:id,:hash,:path)");
            $bump_path = $this->db->prepare(
                "INSERT INTO path_epoch(path_hash,epoch) VALUES(:hash,1)
                 ON CONFLICT(path_hash) DO UPDATE SET epoch=epoch+1");
            foreach (array_keys($paths) as $path) {
                if (!self::safe_media_path($path)) {
                    throw new \RuntimeException('invalid old owner path');
                }
                $hash = hash('sha256', $path);
                $insert->bindValue(':id', $id, SQLITE3_INTEGER);
                $insert->bindValue(':hash', $hash, SQLITE3_TEXT);
                $insert->bindValue(':path', $path, SQLITE3_TEXT);
                if (!$insert->execute()) { throw new \RuntimeException('fence insert'); }
                $insert->reset();
                // A fence is a new authorization event even if the file's
                // policy value itself has not changed yet.
                $bump_path->bindValue(':hash', $hash, SQLITE3_TEXT);
                if (!$bump_path->execute()) { throw new \RuntimeException('path epoch fence'); }
                $bump_path->reset();
            }
        });
    }

    private static function safe_media_path($path) {
        if (!is_string($path) || $path === '' || strlen($path) > 4096 ||
            $path[0] === '/' || strpos($path, '\\') !== false ||
            preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part[0] === '.') { return false; }
        }
        return true;
    }

    public function owner_epoch($id) {
        if (!is_int($id) || $id < 1) { return null; }
        try {
            $stmt = $this->db->prepare(
                'SELECT epoch FROM owner_epoch WHERE attachment_id=:id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $value = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            return $value ? (int)$value['epoch'] : 0;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Snapshot the version of EACH shared path before reading canonical
     * WordPress authorization. Called once per owner refresh, not per media
     * request. A missing path starts at generation zero.
     *
     * @return array|null file_path => epoch, or null on storage error.
     */
    public function path_epochs($paths) {
        if (!is_array($paths)) { return null; }
        try {
            $query = $this->db->prepare(
                'SELECT epoch FROM path_epoch WHERE path_hash=:hash');
            $versions = [];
            foreach (array_unique($paths) as $path) {
                if (!self::safe_media_path($path)) { return null; }
                $query->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                $result = $query->execute();
                if (!$result) { return null; }
                $row = $result->fetchArray(SQLITE3_ASSOC);
                $versions[$path] = $row ? (int)$row['epoch'] : 0;
                $query->reset();
            }
            return $versions;
        } catch (\Throwable $e) { return null; }
    }

    /** Bounded retry queue of owners whose SQLite fences remain. */
    public function pending_owners($limit = 25) {
        if (!is_int($limit) || $limit < 1 || $limit > 200) { return null; }
        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT attachment_id FROM fenced ORDER BY attachment_id LIMIT :limit');
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $owners = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $owners[] = (int)$row['attachment_id'];
            }
            return $owners;
        } catch (\Throwable $e) { return null; }
    }

    public function owner_paths($id) {
        if (!is_int($id) || $id < 1) { return null; }
        try {
            $out = [];
            foreach (['owner_media', 'fenced'] as $table) {
                $stmt = $this->db->prepare(
                    "SELECT file_path FROM $table WHERE attachment_id=:id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $rows = $stmt->execute();
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    $out[$row['file_path']] = true;
                }
            }
            return array_keys($out);
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Publish refreshed policies and retire only THIS owner's fence atomically.
     * An intervening metadata mutation increments its epoch, invalidating
     * the worker's snapshot; other owners' fences are never retired.
     */
    public function publish_owner($id, $epoch, $policies, $owned_paths, $path_epochs) {
        if (!is_int($id) || $id < 1 || !is_int($epoch) || $epoch < 0 ||
            !is_array($policies) || !is_array($owned_paths) ||
            !is_array($path_epochs) || count($path_epochs) !== count($policies)) {
            return false;
        }
        foreach ($policies as $path => $record) {
            if (!self::safe_media_path($path) || !is_array($record) ||
                !in_array($record['policy'] ?? null,
                    ['public','protected','deny'], true) ||
                !is_string($record['path_id'] ?? null) ||
                !array_key_exists($path, $path_epochs) ||
                !is_int($path_epochs[$path]) || $path_epochs[$path] < 0) {
                return false;
            }
        }
        foreach ($owned_paths as $path) {
            if (!self::safe_media_path($path) || !array_key_exists($path, $policies)) {
                return false;
            }
        }
        return $this->tx(function () use ($id, $epoch, $policies, $owned_paths, $path_epochs) {
            $stmt = $this->db->prepare(
                'SELECT epoch FROM owner_epoch WHERE attachment_id=:id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (($row ? (int)$row['epoch'] : 0) !== $epoch) {
                throw new \RuntimeException('owner updated concurrently');
            }
            // Owner epochs alone cannot protect a shared media row.
            // Verify every affected file-path epoch inside this SAME write
            // transaction; another owner fencing or publishing that path
            // makes any earlier snapshot stale.
            $read_path = $this->db->prepare(
                'SELECT epoch FROM path_epoch WHERE path_hash=:hash');
            foreach ($path_epochs as $path => $expected_epoch) {
                $read_path->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                $result = $read_path->execute();
                if (!$result) { throw new \RuntimeException('path epoch read'); }
                $snapshot = $result->fetchArray(SQLITE3_ASSOC);
                $current_epoch = $snapshot ? (int)$snapshot['epoch'] : 0;
                if ($current_epoch !== $expected_epoch) {
                    throw new \RuntimeException('shared path was updated concurrently');
                }
                $read_path->reset();
            }
            $bump_path = $this->db->prepare(
                "INSERT INTO path_epoch(path_hash,epoch) VALUES(:hash,1)
                 ON CONFLICT(path_hash) DO UPDATE SET epoch=epoch+1");
            // A successful publish invalidates every other owner's snapshot
            // even if the published policy is unchanged (public -> public).
            foreach ($path_epochs as $path => $snapshot_epoch) {
                $bump_path->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                if (!$bump_path->execute()) {
                    throw new \RuntimeException('path epoch publication');
                }
                $bump_path->reset();
            }
            $update = $this->db->prepare(
                "INSERT INTO media(path_hash,file_path,policy,path_id)
                 VALUES(:hash,:path,:policy,:owner)
                 ON CONFLICT(path_hash) DO UPDATE SET
                    file_path=excluded.file_path,
                    policy=excluded.policy,path_id=excluded.path_id");
            foreach ($policies as $path => $policy) {
                $update->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                $update->bindValue(':path', $path, SQLITE3_TEXT);
                $update->bindValue(':policy', $policy['policy'], SQLITE3_TEXT);
                $update->bindValue(':owner', $policy['path_id'], SQLITE3_TEXT);
                if (!$update->execute()) { throw new \RuntimeException('media publish'); }
                $update->reset();
            }
            $clear = $this->db->prepare(
                'DELETE FROM owner_media WHERE attachment_id=:id');
            $clear->bindValue(':id', $id, SQLITE3_INTEGER);
            $clear->execute();
            $insert = $this->db->prepare(
                "INSERT INTO owner_media(attachment_id,path_hash,file_path)
                 VALUES(:id,:hash,:path)");
            foreach (array_unique($owned_paths) as $path) {
                $insert->bindValue(':id', $id, SQLITE3_INTEGER);
                $insert->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                $insert->bindValue(':path', $path, SQLITE3_TEXT);
                if (!$insert->execute()) { throw new \RuntimeException('owner insert'); }
                $insert->reset();
            }
            $rm = $this->db->prepare('DELETE FROM fenced WHERE attachment_id=:id');
            $rm->bindValue(':id', $id, SQLITE3_INTEGER);
            $rm->execute();
        });
    }

    /**
     * Offline catalog import. Never overwrites revocations or activates
     * serving, and leaves the gate stopped during/after the import.
     */
    public function replace_catalog($policies, $owners) {
        if (!is_array($policies) || !is_array($owners)) { return false; }
        foreach ($owners as $owner => $paths) {
            if ((!is_int($owner) && !ctype_digit((string)$owner)) ||
                (int)$owner <= 0 || !is_array($paths)) { return false; }
            foreach ($paths as $path) {
                if (!array_key_exists($path, $policies) || !self::safe_media_path($path)) {
                    return false;
                }
            }
        }
        return $this->tx(function () use ($policies, $owners) {
            $this->db->exec("UPDATE meta SET v='0' WHERE k IN ('ready','serving')");
            $this->db->exec('DELETE FROM media');
            $this->db->exec('DELETE FROM owner_media');
            $this->db->exec('DELETE FROM fenced');
            $this->db->exec('DELETE FROM owner_epoch');
            $this->db->exec('DELETE FROM path_epoch');
            $stmt = $this->db->prepare(
                "INSERT INTO media(path_hash,file_path,policy,path_id)
                 VALUES(:hash,:path,:policy,:owner)");
            foreach ($policies as $path => $policy) {
                if (!self::safe_media_path($path) ||
                    !is_array($policy) ||
                    !in_array($policy['policy'] ?? null, ['public','protected','deny'], true) ||
                    !is_string($policy['path_id'] ?? null)) {
                    throw new \RuntimeException('invalid catalog entry');
                }
                $stmt->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                $stmt->bindValue(':path', $path, SQLITE3_TEXT);
                $stmt->bindValue(':policy', $policy['policy'], SQLITE3_TEXT);
                $stmt->bindValue(':owner', $policy['path_id'], SQLITE3_TEXT);
                if (!$stmt->execute()) { throw new \RuntimeException('catalog insert'); }
                $stmt->reset();
            }
            $mapped = $this->db->prepare(
                "INSERT INTO owner_media(attachment_id,path_hash,file_path)
                 VALUES(:id,:hash,:path)");
            foreach ($owners as $owner => $paths) {
                foreach (array_unique($paths) as $path) {
                    $mapped->bindValue(':id', (int)$owner, SQLITE3_INTEGER);
                    $mapped->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
                    $mapped->bindValue(':path', $path, SQLITE3_TEXT);
                    if (!$mapped->execute()) { throw new \RuntimeException('catalog owner'); }
                    $mapped->reset();
                }
            }
        });
    }

    /** Store all revocations atomically, including simultaneous session+remember. */
    public function revoke_many($refs, $at) {
        if (!is_array($refs) || !is_int($at) || $at < 1) { return false; }
        $until = $at + ESP_Fast_Gate_Token::MAX_AGE + 300;
        return $this->tx(function () use ($refs, $until) {
            $stmt = $this->db->prepare("INSERT INTO revoked(session_ref,retain_until,db_deleted)
                VALUES(:ref,:until,0)
                ON CONFLICT(session_ref) DO UPDATE SET
                  retain_until=MAX(retain_until,excluded.retain_until),
                  db_deleted=0");
            foreach (array_unique($refs) as $ref) {
                if (!is_string($ref) ||
                    !preg_match('/^[a-f0-9]{64}$/D', $ref)) {
                    throw new \RuntimeException('invalid ref');
                }
                $stmt->bindValue(':ref', $ref, SQLITE3_TEXT);
                $stmt->bindValue(':until', $until, SQLITE3_INTEGER);
                if (!$stmt->execute()) { throw new \RuntimeException('revoke'); }
                $stmt->reset();
            }
        });
    }

    public function is_revoked($ref) {
        try {
            if (!$this->ready() || !is_string($ref) ||
                !preg_match('/^[a-f0-9]{64}$/D', $ref)) {
                return null; // Unknown is not an allow decision.
            }
            $stmt = $this->db->prepare(
                'SELECT 1 FROM revoked WHERE session_ref=:ref');
            $stmt->bindValue(':ref', $ref, SQLITE3_TEXT);
            return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
        } catch (\Throwable $e) { return null; }
    }

    public function confirm_deleted($ref) {
        if (!is_string($ref) || !preg_match('/^[a-f0-9]{64}$/D', $ref)) {
            return false;
        }
        return $this->tx(function () use ($ref) {
            $stmt = $this->db->prepare(
                'UPDATE revoked SET db_deleted=1 WHERE session_ref=:ref');
            $stmt->bindValue(':ref', $ref, SQLITE3_TEXT);
            $stmt->execute();
            if ($this->db->changes() !== 1) {
                throw new \RuntimeException('missing revocation');
            }
        });
    }

    public function gc($now) {
        if (!is_int($now) || $now < 1) { return false; }
        return $this->tx(function () use ($now) {
            $stmt = $this->db->prepare(
                'DELETE FROM revoked WHERE db_deleted=1 AND retain_until<:now');
            $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
            if (!$stmt->execute()) { throw new \RuntimeException('gc'); }
        });
    }
}
