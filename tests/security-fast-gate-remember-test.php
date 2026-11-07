<?php
/**
 * Fast Gate R04: Remember Me must never reissue from a vanished/expired/
 * changed MySQL credential. Exercise the actual private ESP_Auth methods.
 */
define('ABSPATH', __DIR__ . '/');
define('AUTH_SALT', 'remember-test-salt');
define('DAY_IN_SECONDS', 86400);
class ESP_Config {
    const DB_TABLES = ['remember' => 'esp_login_remember'];
    const TEXT_DOMAIN = 'easy-slug-protect';
}
class ESP_Option {
    public static function get_current_setting($name) {
        if ($name !== 'remember') { throw new RuntimeException('Unexpected settings'); }
        return ['time_frame' => 15];
    }
}
class ESP_Cookie {
    public $written = 0;
    public $cleared = 0;
    public $credentials;
    public static function get_instance() { return $GLOBALS['cookie_stub']; }
    public function get_remember_cookies_for_path($path) { return $this->credentials; }
    public function prepare_remember_cookies($path, $id, $token, $expires) {
        $this->written++;
    }
    public function clear_remember_cookies_for_path($path) { $this->cleared++; }
}
class ESP_Fast_Gate_WP {
    public static $issued = 0;
    public static function issue_cookie(...$args) { self::$issued++; }
}
class FakeRememberDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $row;
    public $drop_after_select = false;
    public $modify_after_select = null;
    public $force_query_failure = false;
    public $force_unchanged = false;
    private $args = [];
    private $path_id;
    private $token_hash;
    private $user_id;

    public function __construct($user, $token, $path, $version = 3) {
        $this->user_id = $user;
        $this->path_id = $path;
        $this->token_hash = hash_hmac('sha256', $token, AUTH_SALT);
        $this->row = (object)[
            'id' => 7,
            'user_id' => $user,
            'token' => $this->token_hash,
            'path_id' => $path,
            'password_version' => $version,
            'expires' => date('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ];
    }
    public function prepare($sql, ...$args) {
        $this->args = $args;
        return $sql;
    }
    private function matches($args) {
        if (!$this->row || count($args) < 5) { return false; }
        return (int)$args[0] === $this->row->id &&
            (string)$args[1] === $this->row->user_id &&
            (string)$args[2] === $this->row->token &&
            (string)$args[3] === $this->row->path_id &&
            (int)$args[4] === $this->row->password_version &&
            strtotime($this->row->expires) > time();
    }
    public function get_row($sql) {
        if (strpos($sql, 'expires > NOW()') === false ||
            strpos($sql, 'user_id = %s') === false ||
            strpos($sql, 'token = %s') === false ||
            strpos($sql, 'path_id = %s') === false) {
            throw new RuntimeException('Initial verification query lost credentials/expiry');
        }
        $args = $this->args;
        if (!$this->row ||
            (string)$args[0] !== $this->row->user_id ||
            (string)$args[1] !== $this->row->token ||
            (string)$args[2] !== $this->row->path_id ||
            strtotime($this->row->expires) <= time()) {
            return null;
        }
        $read = clone $this->row;
        if ($this->drop_after_select) { $this->row = null; }
        if ($this->modify_after_select !== null && $this->row) {
            $this->row->{$this->modify_after_select['field']} =
                $this->modify_after_select['value'];
        }
        return $read;
    }
    public function query($sql) {
        if (strpos($sql, 'UPDATE wp_esp_login_remember SET expires=%s') === false ||
            strpos($sql, 'id=%d AND user_id=%s AND token=%s AND path_id=%s') === false ||
            strpos($sql, 'password_version=%d') === false ||
            strpos($sql, 'expires>NOW()') === false) {
            throw new RuntimeException('Refresh must condition on full credential and expiry');
        }
        if ($this->force_query_failure) {
            $this->last_error = 'Injected SQL failure';
            return false;
        }
        $this->last_error = '';
        $args = $this->args;
        if (!$this->matches(array_slice($args, 1))) { return 0; }
        if ($this->force_unchanged) {
            $this->row->expires = $args[0];
            return 0;
        }
        if ($this->row->expires === $args[0]) { return 0; }
        $this->row->expires = $args[0];
        return 1;
    }
    public function get_var($sql) {
        if (strpos($sql, 'SELECT id FROM wp_esp_login_remember') === false ||
            strpos($sql, 'id=%d AND user_id=%s AND token=%s AND path_id=%s') === false ||
            strpos($sql, 'password_version=%d') === false ||
            strpos($sql, 'expires>NOW() AND expires >= %s') === false) {
            throw new RuntimeException('Zero-row fallback must revalidate full credential');
        }
        $args = $this->args;
        $this->last_error = '';
        return $this->matches($args) &&
            strcmp($this->row->expires, $args[5]) >= 0
            ? (string)$this->row->id : null;
    }
}
function remember_assert($ok, $label) {
    if (!$ok) { throw new RuntimeException('Remember Me R04: ' . $label); }
}
function run_remember_case($name, $mutator, $should_authorize) {
    $path = ['id' => 'path_7', 'password_version' => 3];
    $user = 'remember-user';
    $token = str_repeat('t', 64);
    $cookie = new ESP_Cookie();
    $cookie->credentials = ['id' => $user, 'token' => $token];
    $GLOBALS['cookie_stub'] = $cookie;
    $GLOBALS['wpdb'] = new FakeRememberDB($user, $token, $path['id']);
    ESP_Fast_Gate_WP::$issued = 0;
    $mutator($GLOBALS['wpdb']);
    $reflection = new ReflectionClass('ESP_Auth');
    $auth = $reflection->newInstanceWithoutConstructor();
    $prop = $reflection->getProperty('cookie');
    $prop->setValue($auth, $cookie);
    $check = $reflection->getMethod('check_remember_login');
    $accepted = $check->invoke($auth, $path);
    remember_assert($accepted === $should_authorize, $name . ': login result');
    remember_assert($cookie->written === ($should_authorize ? 1 : 0),
        $name . ': Remember Cookie issuance');
    remember_assert(ESP_Fast_Gate_WP::$issued === ($should_authorize ? 1 : 0),
        $name . ': signed gate ticket issuance');
}
require __DIR__ . '/../includes/class-esp-auth.php';

run_remember_case('normal extension', function ($db) {}, true);
run_remember_case('same-value (0 affected) valid', function ($db) {
    $db->force_unchanged = true;
}, true);
run_remember_case('deleted between SELECT and UPDATE', function ($db) {
    $db->drop_after_select = true;
}, false);
run_remember_case('password version changed between SELECT and UPDATE', function ($db) {
    $db->modify_after_select = ['field'=>'password_version','value'=>4];
}, false);
run_remember_case('token changed between SELECT and UPDATE', function ($db) {
    $db->modify_after_select = ['field'=>'token','value'=>str_repeat('z', 64)];
}, false);
run_remember_case('expiry revoked between SELECT and UPDATE', function ($db) {
    $db->modify_after_select = ['field'=>'expires',
        'value'=>date('Y-m-d H:i:s', time()-60)];
}, false);
run_remember_case('SQL failure', function ($db) {
    $db->force_query_failure = true;
}, false);
echo "Fast Gate Remember Me zero-row/race regression tests OK\n";
