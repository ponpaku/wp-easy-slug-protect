<?php
/**
 * R04 real MySQL interleaving test. Uses a disposable MySQL database and
 * the actual ESP_Auth private Remember Me verification/refresh methods.
 *
 * Two mysqli connections interleave between the initial SELECT and UPDATE.
 */
define('ABSPATH', __DIR__ . '/');
define('AUTH_SALT', 'esp-r04-disposable-test-salt');
define('DAY_IN_SECONDS', 86400);
class ESP_Config {
    const DB_TABLES = ['remember'=>'remember_gate_r04'];
    const TEXT_DOMAIN = 'easy-slug-protect';
}
class ESP_Option {
    public static function get_current_setting($name) {
        if ($name !== 'remember') { throw new RuntimeException('unknown option'); }
        return ['time_frame'=>15];
    }
}
class ESP_Cookie {
    public static $instance;
    public $prepared = 0;
    public function get_instance() { return self::$instance; }
    public function get_remember_cookies_for_path($path) {
        return ['id'=>'test-user', 'token'=>str_repeat('x',64)];
    }
    public function prepare_remember_cookies($path,$id,$token,$expires) {
        $this->prepared++;
    }
    public function clear_remember_cookies_for_path($path) {}
}
class ESP_Fast_Gate_WP {
    public static $issued = 0;
    public static function issue_cookie(...$args) { ++self::$issued; }
}
class WPDBRememberShim {
    public $prefix = 'esp_test_';
    public $last_error = '';
    public $interleave = null;
    private $db;
    private $other;
    public function __construct($db,$other) {
        $this->db = $db; $this->other = $other;
    }
    public function prepare($sql,...$args) {
        $i=0;
        return preg_replace_callback('/%[sd]/',function ($m) use (&$i,$args) {
            $x=$args[$i++];
            return $m[0]==='%d'?(string)(int)$x:
                "'" . $this->db->real_escape_string((string)$x) . "'";
        },$sql);
    }
    public function get_row($sql) {
        $data=$this->db->query($sql);
        $this->last_error=$data===false?$this->db->error:'';
        if ($data===false) { return null; }
        $row=$data->fetch_object();
        $data->free();
        // A second PHP worker may delete or alter a session after our read
        // but before the original PHP request refreshes its expiration.
        if ($row && $this->interleave!==null && $this->interleave!=='same') {
            $sql = null;
            if ($this->interleave==='delete') {
                $sql="DELETE FROM esp_test_remember_gate_r04 WHERE id=".(int)$row->id;
            } elseif ($this->interleave==='version') {
                $sql="UPDATE esp_test_remember_gate_r04 SET password_version=4 WHERE id=".(int)$row->id;
            } elseif ($this->interleave==='expire') {
                $sql="UPDATE esp_test_remember_gate_r04 SET expires=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id=".(int)$row->id;
            }
            if ($sql!==null) {
                if (!$this->other->query($sql)) {
                    throw new RuntimeException('concurrent writer: '.$this->other->error);
                }
                $this->interleave=null;
            }
        }
        return $row;
    }
    public function query($sql) {
        if ($this->interleave==='same') {
            if (!preg_match("/^UPDATE esp_test_remember_gate_r04 SET expires='([^']+)'/D", $sql, $match)) {
                // UPDATE can have trailing WHERE; /D only affects the final
                // anchor, not this prefix.
                if (!preg_match("/^UPDATE esp_test_remember_gate_r04 SET expires='([^']+)'/", $sql, $match)) {
                    throw new RuntimeException('unexpected expiry UPDATE SQL');
                }
            }
            $expire=$this->other->real_escape_string($match[1]);
            if (!$this->other->query(
                "UPDATE esp_test_remember_gate_r04 SET expires='$expire' WHERE id=7"
            )) {
                throw new RuntimeException('concurrent expiry refresh: '.$this->other->error);
            }
            $this->interleave=null;
        }
        $result=$this->db->query($sql);
        $this->last_error=$result===false?$this->db->error:'';
        return $result===false?false:$this->db->affected_rows;
    }
    public function get_var($sql) {
        $result=$this->db->query($sql);
        $this->last_error=$result===false?$this->db->error:'';
        if ($result===false) { return null; }
        $row=$result->fetch_row(); $result->free();
        return $row?$row[0]:null;
    }
}
require __DIR__.'/../includes/class-esp-auth.php';
function assert_r04($ok,$message) {
    if (!$ok) { throw new RuntimeException('R04 MySQL: '.$message); }
}
mysqli_report(MYSQLI_REPORT_OFF);
function connect_r04() {
    $db=new mysqli(
        getenv('ESP_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('ESP_TEST_DB_USER') ?: 'root',
        getenv('ESP_TEST_DB_PASSWORD') ?: '',
        getenv('ESP_TEST_DB_NAME') ?: 'esp_test'
    );
    if ($db->connect_errno) {
        throw new RuntimeException('MySQL unavailable: '.$db->connect_error);
    }
    return $db;
}
$first=connect_r04();
$second=connect_r04();
$table='esp_test_remember_gate_r04';
$created=$first->query("CREATE TABLE IF NOT EXISTS $table (
    id bigint unsigned NOT NULL PRIMARY KEY,
    user_id varchar(100) NOT NULL,
    token char(64) NOT NULL,
    path_id varchar(100) NOT NULL,
    password_version int NOT NULL,
    expires datetime NOT NULL
) ENGINE=InnoDB");
assert_r04($created===true,'fixture schema creation');
try {
    foreach ([
        ['normal',null,true],
        ['deleted after read','delete',false],
        ['version changed after read','version',false],
        ['expired after read','expire',false],
        ['already renewed (0 affected)','same',true],
    ] as [$name,$interleave,$expected]) {
        $first->query("DELETE FROM $table");
        $hash=hash_hmac('sha256',str_repeat('x',64),AUTH_SALT);
        $expire=date('Y-m-d H:i:s',time()+DAY_IN_SECONDS);
        $stmt=$first->prepare(
            "INSERT INTO $table(id,user_id,token,path_id,password_version,expires)
             VALUES (7,'test-user',?,'path_7',3,?)");
        $stmt->bind_param('ss',$hash,$expire);
        assert_r04($stmt->execute(),'setup '.$name);
        $stmt->close();
        $wpdb=new WPDBRememberShim($first,$second);
        $wpdb->interleave=$interleave;
        $cookie=new ESP_Cookie();
        ESP_Cookie::$instance=$cookie;
        ESP_Fast_Gate_WP::$issued=0;
        $auth=(new ReflectionClass('ESP_Auth'))->newInstanceWithoutConstructor();
        (new ReflectionClass('ESP_Auth'))->getProperty('cookie')->setValue($auth,$cookie);
        $method=(new ReflectionClass('ESP_Auth'))->getMethod('check_remember_login');
        $result=$method->invoke($auth,['id'=>'path_7','password_version'=>3]);
        assert_r04($result===$expected,$name.' credential state');
        assert_r04($cookie->prepared===($expected?1:0),$name.' Remember Cookie');
        assert_r04(ESP_Fast_Gate_WP::$issued===($expected?1:0),
            $name.' signed ticket');
    }
    echo "Real MySQL Remember Me changed/vanished/0-affected tests OK\n";
} finally {
    $first->query("DROP TABLE IF EXISTS $table");
    $first->close(); $second->close();
}
