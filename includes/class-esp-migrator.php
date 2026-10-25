<?php
if (!defined('ABSPATH')) { exit; }

/** Single-site schema/data migration. No network or update UI responsibilities. */
final class ESP_Migrator {
    const STATE_OPTION = 'esp_migration_state';
    const BATCH_SIZE = 200;
    private $lock;
    private $state = [];
    private $connection;

    /** Read the database, not an option-cache snapshot; absence and SQL failure differ. */
    public static function read_option($name, $default = null) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new RuntimeException('option_read_failed'); }
        return $row === null ? $default : maybe_unserialize($row['option_value']);
    }

    public static function write_option($name, $value) {
        global $wpdb;
        $lock_name = 'esp_verify_' . md5((defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix);
        // INSERT SELECT fences the write on the server itself, including wpdb's retry
        // after reconnect. No current connection lock => zero rows written.
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        if (!is_numeric($connection)) { throw new RuntimeException('lock_lost'); }
        $sql = $wpdb->prepare("INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload)
            SELECT %s, %s, 'no' WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d
            ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $name, maybe_serialize($value), $lock_name, $connection);
        if ($wpdb->query($sql) === false) { throw new RuntimeException('option_write_failed'); }
        foreach (['alloptions', 'notoptions', $name] as $key) { wp_cache_delete($key, 'options'); }
        if ((string) maybe_serialize(self::read_option($name)) !== (string) maybe_serialize($value)) {
            throw new RuntimeException('option_write_failed');
        }
        $owner = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lock_name));
        if ((string) $owner !== (string) $connection) { throw new RuntimeException('lock_lost'); }
        // Schema/settings migration deliberately bypasses Settings API sanitizers: an
        // existing password hash must never be rehashed as if it were a new password.
    }

    private function owns() {
        if (!ESP_Protection_State::owns_verification_lock($this->lock)) {
            throw new RuntimeException('lock_lost');
        }
    }

    private function checkpoint($step, $status = 'running', $reason = '') {
        $this->owns();
        $this->state = array_merge($this->state, [
            'version' => ESP_VERSION, 'step' => $step, 'status' => $status,
            'reason' => $reason, 'updated_at' => time(),
        ]);
        self::write_option(self::STATE_OPTION, $this->state);
    }

    /**
     * A single bounded invocation. Interrupted DDL is discovered from actual schema on
     * resume. GET_LOCK is connection owned, has no lease to steal, and is shared with #48.
     * $maintenance confirms drained old workers for legacy V1 data conversion only.
     */
    public function run($held_lock = null, $maintenance = false) {
        if (is_multisite()) { return false; } // Network migration needs its own design/tests.
        $this->lock = $held_lock ?? ESP_Protection_State::acquire_verification_lock();
        if ($this->lock === null) { return false; }
        try {
            $this->owns();
            global $wpdb;
            $this->connection = $wpdb->dbh;
            $old = self::read_option(self::STATE_OPTION, []);
            if (!is_array($old) || (isset($old['version']) && (!is_string($old['version']) ||
                version_compare($old['version'], ESP_VERSION, '>')))) {
                return false; // Preserve newer checkpoints, even for a manual downgrade.
            }
            $this->state = $old;
            if (($old['version'] ?? ESP_VERSION) !== ESP_VERSION && ($old['status'] ?? '') !== 'complete') {
                throw new RuntimeException('future_or_invalid_migration_state');
            }
            $resuming_install = !empty($old['clean_install']) && ($old['status'] ?? '') !== 'complete';
            $runtime = self::read_option(ESP_Protection_State::RUNTIME_STATE_OPTION, []);
            $plugin = self::read_option(ESP_Config::VERSION_OPTION_KEY);
            if ($plugin !== null && $plugin !== false && $plugin !== '' &&
                (!is_string($plugin) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $plugin))) {
                throw new RuntimeException('invalid_plugin_version');
            }
            if ((is_array($runtime) && ($runtime['state'] ?? '') === 'UPDATING') ||
                (is_string($plugin) && version_compare($plugin, ESP_VERSION, '>')) ||
                (is_array($runtime) && isset($runtime['version']) && is_string($runtime['version']) &&
                    version_compare($runtime['version'], ESP_VERSION, '>'))) {
                return false;
            }
            $marker = self::read_option('esp_db_version');
            if ($marker !== null && $marker !== false && $marker !== '' &&
                !((is_int($marker) && $marker >= 0) || (is_string($marker) && ctype_digit($marker)))) {
                throw new RuntimeException('invalid_db_version');
            }
            if ((int) $marker > ESP_Config::OPTION_DEFAULTS['db_version']) {
                throw new RuntimeException('future_schema');
            }
            $settings = self::read_option(ESP_Config::OPTION_KEY);
            if ($settings === null && $resuming_install) {
                $settings = ESP_Config::OPTION_DEFAULTS;
                self::write_option(ESP_Config::OPTION_KEY, $settings);
            }
            $tables = $this->inspect();
            $present = count(array_filter($tables, static function ($t) { return $t !== null; }));
            // Settings, versions, tables, migration history and protected metadata all
            // count as installation evidence. Never create defaults over a partial site.
            $unverified_runtime = $runtime === [] || (is_array($runtime) &&
                ($runtime['state'] ?? '') === 'PENDING' && ($runtime['version'] ?? null) === null);
            $clean = $settings === null && $marker === null && $plugin === null && $unverified_runtime &&
                $present === 0 && $old === [] && !$this->has_protection_metadata();
            $this->state['classification'] = $clean ? 'clean_install' : 'existing_install';
            if (!$clean && (!is_array($settings) || !isset($settings['path']) || !is_array($settings['path']) ||
                (($tables['brute'] === null || $tables['remember'] === null) && !$resuming_install))) {
                $this->state['classification'] = 'partial_or_inconsistent';
                throw new RuntimeException('partial_installation');
            }
            if ($clean) { $this->state['clean_install'] = true; }
            $this->checkpoint('inspect');
            if ($clean) {
                $settings = ESP_Config::OPTION_DEFAULTS;
                // Persist install intent first, so interruption remains resumable but
                // never looks like another unrelated clean installation.
                $this->state['clean_install'] = true;
                $this->checkpoint('create');
                self::write_option(ESP_Config::OPTION_KEY, $settings);
            } elseif ($resuming_install && $present < 4) {
                // Only our own recorded empty install may resume missing base tables.
                if ($settings['path'] !== [] || $this->has_protection_metadata()) {
                    throw new RuntimeException('partial_installation');
                }
            }
            foreach ($this->definitions() as $kind => $def) { $this->validate_existing($kind, $tables[$kind], $def); }
            $paths = $this->path_map($settings['path']);
            $legacy = $paths['changed'];
            // Discover ambiguous existing links before changing even the first row.
            if (!$this->verify_references($paths['paths'])) {
                throw new RuntimeException('path_reference_invalid');
            }
            $this->preflight_path_rows($tables, $paths, $legacy);
            if (!$clean && !$resuming_install &&
                (($marker === null && $present < 4 && !$legacy) ||
                 ((int) $marker >= 2 && $tables['session'] === null) ||
                 ((int) $marker >= 6 && $tables['limit'] === null))) {
                $this->state['classification'] = 'partial_or_inconsistent';
                throw new RuntimeException('partial_installation');
            }
            if ($legacy && !$maintenance && empty($old['maintenance_confirmed'])) {
                $this->checkpoint('v1', 'blocked', 'maintenance_confirmation_required');
                return false;
            }
            if ($legacy && $maintenance) { $this->state['maintenance_confirmed'] = true; $this->checkpoint('v1'); }
            // There is no v5 migration. These are explicit historical steps, not a loop
            // over integers; the schema inspector validates every required field/index.
            $this->apply_schema($tables, $clean || $resuming_install);
            $this->checkpoint('v1');
            $backfill = $legacy || ($old['status'] ?? '') === 'pending' || $this->missing_path_rows();
            if ($backfill) {
                if (($old['status'] ?? '') !== 'pending') { unset($this->state['cursors']); }
                if (!$this->backfill_paths($paths['map'])) { return false; }
            }
            $this->verify_path_rows($paths['map']);
            if ($legacy) {
                $this->owns();
                // Refuse concurrent settings edits instead of overwriting new passwords.
                if (self::read_option(ESP_Config::OPTION_KEY) !== $settings) {
                    throw new RuntimeException('settings_changed');
                }
                $settings['path'] = $paths['paths'];
                self::write_option(ESP_Config::OPTION_KEY, $settings);
            }
            $changed = false;
            foreach ($settings['path'] as &$path) {
                if (!array_key_exists('password_version', $path)) {
                    $path['password_version'] = 0; $changed = true;
                }
            }
            unset($path);
            if ($changed) { self::write_option(ESP_Config::OPTION_KEY, $settings); }
            $this->checkpoint('v2_v3_v4_v6_verify');
            $this->verify_schema($this->inspect());
            $verified = $this->path_map(self::read_option(ESP_Config::OPTION_KEY)['path']);
            if ($verified['changed'] || !$this->verify_references($verified['paths'])) {
                throw new RuntimeException('path_reference_invalid');
            }
            $this->owns();
            self::write_option('esp_db_version', ESP_Config::OPTION_DEFAULTS['db_version']);
            unset($this->state['clean_install'], $this->state['maintenance_confirmed']);
            $this->checkpoint('complete', 'complete');
            return true;
        } catch (Throwable $error) {
            // Only fixed diagnostic codes reach the UI. Never store exception SQL/paths.
            $known = ['option_read_failed', 'option_write_failed', 'lock_lost', 'future_schema',
                'invalid_db_version', 'invalid_plugin_version', 'partial_installation', 'path_reference_invalid',
                'path_mapping_ambiguous', 'password_version_ambiguous', 'settings_changed', 'schema_incompatible',
                'schema_read_failed', 'schema_write_failed', 'schema_verification_failed',
                'future_or_invalid_migration_state'];
            $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'migration_exception';
            try { $this->checkpoint($this->state['step'] ?? 'inspect', 'failed', $reason); }
            catch (Throwable $ignored) { /* Lost lock / DB failure must never advance versions. */ }
            return false;
        } finally {
            if ($held_lock === null) { ESP_Protection_State::release_verification_lock($this->lock); }
        }
    }

    /** Canonical required columns/types and indexes, including historical v1/2/3/4/6. */
    private function definitions() {
        return [
            'brute' => [
                'columns' => ['id' => 'bigint unsigned NOT NULL AUTO_INCREMENT', 'ip_address' => 'varchar(45) NOT NULL',
                    'path' => 'varchar(255) NOT NULL', 'path_id' => 'varchar(50) NOT NULL', 'time' => 'datetime NOT NULL'],
                'indexes' => ['PRIMARY' => ['id'], 'ip_path_time' => ['ip_address', 'path', 'time'], 'ip_path_id' => ['ip_address', 'path_id']],
            ],
            'remember' => [
                'columns' => ['id' => 'bigint unsigned NOT NULL AUTO_INCREMENT', 'path' => 'varchar(255) NOT NULL',
                    'path_id' => 'varchar(50) NOT NULL', 'password_version' => 'int unsigned NOT NULL DEFAULT 0',
                    'user_id' => 'varchar(32) NOT NULL', 'token' => 'varchar(64) NOT NULL',
                    'created' => 'datetime NOT NULL', 'expires' => 'datetime NOT NULL'],
                'indexes' => ['PRIMARY' => ['id'], 'user_token' => ['user_id', 'token'], 'path_expires' => ['path', 'expires'], 'path_id' => ['path_id']],
            ],
            'session' => [
                'columns' => ['id' => 'bigint unsigned NOT NULL AUTO_INCREMENT', 'path_id' => 'varchar(50) NOT NULL',
                    'password_version' => 'int unsigned NOT NULL DEFAULT 0', 'token' => 'varchar(64) NOT NULL',
                    'created' => 'datetime NOT NULL', 'expires' => 'datetime NOT NULL'],
                'indexes' => ['PRIMARY' => ['id'], 'token_unique' => ['token'], 'path_id' => ['path_id'], 'expires' => ['expires']],
            ],
            'limit' => [
                'columns' => ['ip_address' => 'varchar(45) NOT NULL', 'path_id' => 'varchar(50) NOT NULL',
                    'window_started' => 'bigint unsigned NOT NULL DEFAULT 0', 'attempts' => 'int unsigned NOT NULL DEFAULT 0',
                    'blocked_until' => 'bigint unsigned NOT NULL DEFAULT 0', 'updated_at' => 'bigint unsigned NOT NULL DEFAULT 0',
                    'attempt_times' => 'longtext NULL', 'last_attempt_token' => "varchar(32) NOT NULL DEFAULT ''"],
                'indexes' => ['PRIMARY' => ['ip_address', 'path_id'], 'updated_at' => ['updated_at']],
            ],
        ];
    }

    private function table($kind) {
        global $wpdb;
        return '`' . str_replace('`', '``', $wpdb->prefix . ESP_Config::DB_TABLES[$kind]) . '`';
    }

    private function inspect() {
        global $wpdb;
        $tables = [];
        foreach ($this->definitions() as $kind => $def) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $wpdb->prefix . ESP_Config::DB_TABLES[$kind]));
            if ($wpdb->last_error !== '') { throw new RuntimeException('schema_read_failed'); }
            if ($engine === null) { $tables[$kind] = null; continue; }
            $cols = $wpdb->get_results('SHOW COLUMNS FROM ' . $this->table($kind), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($cols) || !$cols) { throw new RuntimeException('schema_read_failed'); }
            $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $this->table($kind), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($indexes)) { throw new RuntimeException('schema_read_failed'); }
            $tables[$kind] = ['engine' => $engine, 'columns' => array_column($cols, null, 'Field'), 'indexes' => []];
            foreach ($indexes as $idx) {
                $tables[$kind]['indexes'][$idx['Key_name']][(int) $idx['Seq_in_index']] = $idx;
            }
        }
        return $tables;
    }

    private function validate_existing($kind, $table, $def) {
        if ($table === null) { return; }
        if ($kind === 'limit' && strcasecmp($table['engine'], 'InnoDB') !== 0) {
            throw new RuntimeException('schema_incompatible');
        }
        // Only additive, known historical differences are repairable automatically.
        $allowed_missing = ['brute' => ['path_id'], 'remember' => ['path_id', 'password_version'],
            'session' => ['password_version'], 'limit' => ['attempt_times', 'last_attempt_token']];
        foreach ($def['columns'] as $name => $sql) {
            $actual = $table['columns'][$name] ?? null;
            if ($actual === null) {
                if (!in_array($name, $allowed_missing[$kind], true)) { throw new RuntimeException('schema_incompatible'); }
                continue;
            }
            $expected_type = explode(' NOT', explode(' NULL', $sql)[0])[0];
            $expected_type = preg_replace('/ AUTO_INCREMENT| DEFAULT .*/', '', $expected_type);
            $actual_type = preg_replace('/\b(bigint|int)\(\d+\)/', '$1', strtolower($actual['Type']));
            if ($actual_type !== strtolower($expected_type) ||
                ($actual['Null'] === 'YES') !== (strpos($sql, 'NOT NULL') === false) ||
                (strpos($sql, 'AUTO_INCREMENT') !== false && $actual['Extra'] !== 'auto_increment')) {
                throw new RuntimeException('schema_incompatible');
            }
            if (preg_match("/ DEFAULT ('[^']*'|[0-9]+)/", $sql, $default) &&
                (!array_key_exists('Default', $actual) || $actual['Default'] === null ||
                    (string) $actual['Default'] !== trim($default[1], "'"))) {
                throw new RuntimeException('schema_incompatible');
            }
        }
        foreach ($def['indexes'] as $name => $columns) {
            if (!isset($table['indexes'][$name])) {
                if ($name === 'PRIMARY') { throw new RuntimeException('schema_incompatible'); }
                continue;
            }
            $idx = $table['indexes'][$name]; ksort($idx);
            if (array_column($idx, 'Column_name') !== $columns ||
                (int) reset($idx)['Non_unique'] !== (in_array($name, ['PRIMARY', 'token_unique'], true) ? 0 : 1) ||
                array_filter($idx, static function ($i) { return $i['Sub_part'] !== null; })) {
                throw new RuntimeException('schema_incompatible');
            }
        }
    }

    private function apply_schema($tables, $allow_base_creation) {
        global $wpdb;
        $defs = $this->definitions();
        // Validate all existing structures BEFORE the first mutation.
        foreach ($defs as $kind => $def) { $this->validate_existing($kind, $tables[$kind], $def); }
        foreach ($defs as $kind => $def) {
            $this->owns();
            $table = $tables[$kind];
            if ($table === null) {
                if (!$allow_base_creation && in_array($kind, ['brute', 'remember'], true)) {
                    throw new RuntimeException('partial_installation');
                }
                $parts = [];
                foreach ($def['columns'] as $name => $sql) { $parts[] = "`$name` $sql"; }
                foreach ($def['indexes'] as $name => $cols) { $parts[] = $this->index_sql($name, $cols); }
                $this->sql('CREATE TABLE ' . $this->table($kind) . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB ' . $wpdb->get_charset_collate());
            } else {
                foreach ($def['columns'] as $name => $sql) {
                    if (!isset($table['columns'][$name])) {
                        // An empty default backfills historical rows; no existing IDs are touched.
                        if ($name === 'path_id') { $sql .= " DEFAULT ''"; }
                        $this->sql('ALTER TABLE ' . $this->table($kind) . " ADD COLUMN `$name` $sql");
                    }
                }
                foreach ($def['indexes'] as $name => $cols) {
                    if (!isset($table['indexes'][$name])) {
                        $this->sql('ALTER TABLE ' . $this->table($kind) . ' ADD ' . $this->index_sql($name, $cols));
                    }
                }
            }
        }
    }

    private function index_sql($name, $cols) {
        $prefix = $name === 'PRIMARY' ? 'PRIMARY KEY' : ($name === 'token_unique' ? 'UNIQUE KEY' : 'KEY') . " `$name`";
        return $prefix . ' (`' . implode('`,`', $cols) . '`)';
    }

    private function sql($query) {
        global $wpdb;
        $this->owns();
        if (!$this->connection instanceof mysqli || @mysqli_query($this->connection, $query) === false) { throw new RuntimeException('schema_write_failed'); }
        $this->owns();
    }

    private function verify_schema($tables) {
        foreach ($this->definitions() as $kind => $def) {
            $table = $tables[$kind];
            if ($table === null || array_diff_key($def['columns'], $table['columns']) ||
                array_diff_key($def['indexes'], $table['indexes'])) { throw new RuntimeException('schema_verification_failed'); }
            $this->validate_existing($kind, $table, $def);
        }
    }

    private function path_map($paths) {
        $map = []; $new = []; $changed = false;
        foreach ($paths as $key => $path) {
            if (!is_array($path) || !isset($path['path']) || !is_string($path['path']) ||
                trim($path['path'], " /\t\n\r") === '' || isset($map[$path['path']])) {
                throw new RuntimeException('path_mapping_ambiguous');
            }
            if (isset($path['id'])) {
                $id = $path['id'];
                if (!is_string($id) || $id === '' || strlen($id) > 50 || $key !== $id) {
                    throw new RuntimeException('path_mapping_ambiguous');
                }
            } else {
                if (!is_int($key)) { throw new RuntimeException('path_mapping_ambiguous'); }
                // Deterministic across interruption, including after DDL but before settings
                // are written. Existing assigned IDs always take precedence.
                $id = 'path_' . substr(hash('sha256', $path['path']), 0, 32);
                $path['id'] = $id; $changed = true;
            }
            // The retired_ namespace is reserved for non-authenticatable
            // historical rows, never for active protection identities.
            if (strpos($id, 'retired_') === 0 || isset($new[$id])) {
                throw new RuntimeException('path_mapping_ambiguous');
            }
            $new[$id] = $path; $map[$path['path']] = $id;
        }
        return ['map' => $map, 'paths' => $new, 'changed' => $changed];
    }

    private function backfill_paths($map) {
        global $wpdb;
        $active_ids = array_fill_keys(array_values($map), true);
        foreach (['brute', 'remember'] as $kind) {
            $cursor = (int) ($this->state['cursors'][$kind] ?? 0);
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, path, path_id FROM ' .
                $this->table($kind) . ' WHERE id > %d ORDER BY id LIMIT %d', $cursor, self::BATCH_SIZE), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) { throw new RuntimeException('schema_read_failed'); }
            foreach ($rows as $row) {
                $expected = $map[$row['path']] ?? null;
                if ($expected !== null) {
                    // Stale history IDs are inert; never rewrite them to the current
                    // path ID. A different *active* ID, however, is an invalid link.
                    if ($row['path_id'] !== '' && $row['path_id'] !== $expected &&
                        isset($active_ids[$row['path_id']])) {
                        throw new RuntimeException('path_mapping_ambiguous');
                    }
                    if ($row['path_id'] === '') {
                        $this->sql($wpdb->prepare('UPDATE ' . $this->table($kind) . ' SET path_id = %s WHERE id = %d AND path_id = %s',
                            $expected, $row['id'], ''));
                    }
                } elseif ($row['path_id'] === '') {
                    // Do not leave deleted-path history unassigned: a later re-add
                    // would otherwise bind its old credentials to the new path ID.
                    // Assign a deterministic inert ID only to previously blank rows.
                    $retired_id = 'retired_' . substr(hash('sha256', $row['path']), 0, 40);
                    if (isset($active_ids[$retired_id])) { throw new RuntimeException('path_mapping_ambiguous'); }
                    $this->sql($wpdb->prepare('UPDATE ' . $this->table($kind) . ' SET path_id = %s WHERE id = %d AND path_id = %s',
                        $retired_id, $row['id'], ''));
                }
                $cursor = (int) $row['id'];
            }
            $this->state['cursors'][$kind] = $cursor;
            $this->checkpoint('v1_backfill_' . $kind);
            if (count($rows) === self::BATCH_SIZE) {
                $this->checkpoint('v1_backfill_' . $kind, 'pending'); return false;
            }
        }
        return true;
    }

    /**
     * Permit detached historical IDs, but not a reference to another active
     * path: remember authentication identifies its scope by path_id.
     * After backfill, unassigned rows are also invalid for active paths.
     */
    private function assert_active_path_links($kind, $map, $require_assigned) {
        global $wpdb;
        if ($map === []) { return; }

        // A single primary-key walk replaces one increasingly large IN(...) SQL
        // statement per protected path. Memory and SQL size are bounded by the
        // same fixed batch limit used by the V1 migration.
        $active_ids = array_fill_keys(array_values($map), true);
        $cursor = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id, path, path_id FROM ' .
                $this->table($kind) . ' WHERE id > %d ORDER BY id LIMIT %d',
                $cursor, self::BATCH_SIZE), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                throw new RuntimeException('schema_read_failed');
            }
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $expected = $map[$row['path']] ?? null;
                if ($expected === null) { continue; }
                $actual = $row['path_id'];
                if (($require_assigned && $actual === '') ||
                    ($actual !== $expected && isset($active_ids[$actual]))) {
                    throw new RuntimeException('path_mapping_ambiguous');
                }
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    private function verify_path_rows($map) {
        foreach (['brute', 'remember'] as $kind) {
            $this->assert_active_path_links($kind, $map, true);
        }
    }

    private function missing_path_rows() {
        global $wpdb;
        // This includes retired-path history even when no active paths remain.
        foreach (['brute', 'remember'] as $kind) {
            $row = $wpdb->get_var('SELECT id FROM ' . $this->table($kind) . " WHERE path_id = '' LIMIT 1");
            if ($wpdb->last_error !== '') { throw new RuntimeException('schema_read_failed'); }
            if ($row !== null) { return true; }
        }
        return false;
    }

    private function preflight_path_rows($tables, $paths, $legacy) {
        global $wpdb;
        foreach (['remember', 'session'] as $kind) {
            if ($tables[$kind] === null || isset($tables[$kind]['columns']['password_version'])) { continue; }
            foreach ($paths['paths'] as $id => $path) {
                if ((int) ($path['password_version'] ?? 0) === 0) { continue; }
                // A missing version column cannot reconstruct a nonzero password
                // generation for an existing token. Do not silently expire that login
                // by guessing zero, or revive it by guessing the current generation.
                $by_id = isset($tables[$kind]['columns']['path_id']);
                // A partial migration can have path_id='' rows even with the
                // column present. Never guess their missing password generation.
                $unassigned_remember = $kind === 'remember' && $by_id;
                $where = $unassigned_remember
                    ? " WHERE BINARY path_id = BINARY %s OR (path_id = '' AND BINARY path = BINARY %s)"
                    : ($by_id ? ' WHERE BINARY path_id = BINARY %s' : ' WHERE BINARY path = BINARY %s');
                $args = $unassigned_remember ? [$id, $path['path']] : [$by_id ? $id : $path['path']];
                $row = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table($kind) .
                    $where . ' LIMIT 1', $args));
                if ($wpdb->last_error !== '') { throw new RuntimeException('schema_read_failed'); }
                if ($row !== null) { throw new RuntimeException('password_version_ambiguous'); }
            }
        }
        foreach (['brute', 'remember'] as $kind) {
            if (!isset($tables[$kind]['columns']['path_id'])) { continue; }
            $this->assert_active_path_links($kind, $paths['map'], false);
        }
        if ($legacy && $tables['session'] !== null) {
            $ids = $wpdb->get_col('SELECT DISTINCT path_id FROM ' . $this->table('session') .
                ' LIMIT ' . (count($paths['paths']) + 1));
            if ($wpdb->last_error !== '') { throw new RuntimeException('schema_read_failed'); }
            foreach ($ids as $id) {
                if (!isset($paths['paths'][$id])) { throw new RuntimeException('path_mapping_ambiguous'); }
            }
        }
    }

    private function has_protection_metadata() {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM `{$wpdb->postmeta}` WHERE meta_key IN (%s, %s) LIMIT 1",
            ESP_Config::PERMALINK_PATH_META_KEY, '_esp_media_protected_path_id'));
        if ($wpdb->last_error !== '') { throw new RuntimeException('schema_read_failed'); }
        return $value !== null;
    }

    private function verify_references($paths) {
        global $wpdb;
        // Protected attachment IDs must resolve. Stale auth/rate-limit rows belonging
        // to paths intentionally removed by an administrator are retained for cleanup.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = %s", '_esp_media_protected_path_id'));
        if ($wpdb->last_error !== '' || !is_array($ids)) { throw new RuntimeException('schema_read_failed'); }
        foreach ($ids as $id) { if (!isset($paths[$id])) { return false; } }
        return true;
    }

}
