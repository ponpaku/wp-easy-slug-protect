<?php
/**
 * Fast Gate v2 local ledger. This class has no WordPress dependencies and
 * never treats a missing media row as public. SQLite is an opt-in dependency.
 */
final class ESP_Fast_Gate_Store {
    public const SCHEMA = '1';
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
                ('schema','1'), ('ready','0')");
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
            $stmt = $this->db->prepare("UPDATE meta SET v=:v WHERE k='ready'");
            $stmt->bindValue(':v', $ready ? '1' : '0', SQLITE3_TEXT);
            if (!$stmt->execute()) { throw new \RuntimeException('state'); }
        });
    }

    public function set_path($path, $version, $blocked = false) {
        if (!is_string($path) || $path === '' || !is_int($version) || $version < 0) {
            return false;
        }
        return $this->tx(function () use ($path, $version, $blocked) {
            $stmt = $this->db->prepare("INSERT INTO paths(path_id,password_version,blocked)
                VALUES(:id,:version,:blocked)
                ON CONFLICT(path_id) DO UPDATE SET password_version=excluded.password_version,
                                                   blocked=excluded.blocked");
            $stmt->bindValue(':id', $path, SQLITE3_TEXT);
            $stmt->bindValue(':version', $version, SQLITE3_INTEGER);
            $stmt->bindValue(':blocked', $blocked ? 1 : 0, SQLITE3_INTEGER);
            if (!$stmt->execute()) { throw new \RuntimeException('path'); }
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
            $stmt = $this->db->prepare(
                "SELECT file_path,policy,path_id FROM media WHERE path_hash=:hash");
            $stmt->bindValue(':hash', hash('sha256', $path), SQLITE3_TEXT);
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
            $this->db->exec("UPDATE meta SET v='0' WHERE k='ready'");
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
            $this->db->exec("UPDATE meta SET v='0' WHERE k='ready'");
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
