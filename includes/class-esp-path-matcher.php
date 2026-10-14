<?php
// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * パスのマッチングとリポジトリ機能を担当するクラス
 */
class ESP_Path_Matcher {
    /**
     * @var string トランジェントのキー
     */
    private static $cache_key = 'esp_path_index_v2';

    /**
     * @var int キャッシュの有効期間（秒）
     */
    private static $cache_duration = DAY_IN_SECONDS;

    /**
     * @var array 現在のパスインデックス
     */
    private $paths = [];

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->paths = self::all();
    }

    /**
     * 全てのパス設定を取得（キャッシュ込み）
     * 
     * @return array パス設定の配列
     */
    public static function all() {
        // 不正な設定やDB未取得を、意図的に空の保護設定と同視しない。
        $settings = get_option(ESP_Config::OPTION_KEY, null);
        if (!is_array($settings) || !isset($settings['path']) || !is_array($settings['path'])) {
            throw new \UnexpectedValueException('ESP protection path settings unavailable');
        }
        $raw = $settings['path'];
        $signature = hash('sha256', serialize($raw));
        $cached = get_transient(self::$cache_key);
        if (is_array($cached) && isset($cached['signature'], $cached['paths']) &&
            is_string($cached['signature']) && is_array($cached['paths']) &&
            hash_equals($signature, $cached['signature'])) {
            return $cached['paths'];
        }
        $index = self::build_index($raw);
        set_transient(self::$cache_key, ['signature' => $signature, 'paths' => $index], self::$cache_duration);
        return $index;
    }

    /**
     * キャッシュの無効化（設定変更時に呼び出す）
     */
    public static function invalidate() {
        delete_transient(self::$cache_key);
        delete_transient('esp_path_index'); // 旧形式キャッシュも無効化
    }

    /**
     * 生データから検索用インデックスを構築
     * 
     * @return array パスインデックス
     */
    private static function build_index($raw) {
        $index = [];
        
        foreach ($raw as $row) {
            if (!is_array($row) || empty($row['path']) || !is_string($row['path'])) {
                throw new \UnexpectedValueException('Invalid ESP protected path entry');
            }
            // キーの正規化
            $key = trim(strtolower($row['path']), '/');
            
            // 正規表現をプリコンパイル
            $row['regex'] = '#^/' . preg_quote($key, '#') . '(?:/|$)#i';
            $index[$key] = $row;
        }
        
        // キー長の降順ソート（/members/premium > /members）
        uksort($index, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        
        return $index;
    }

    /**
     * 現在パスにマッチする最適な設定を返す
     * 
     * @param string $current_path 現在のパス
     * @return array|null マッチした設定。なければnull
     */
    public function match($current_path) {
        $current_path = '/' . trim(strtolower($current_path), '/') . '/';
        
        foreach ($this->paths as $key => $row) {
            if (preg_match($row['regex'], $current_path)) {
                return $row; // 最長順なので最初に当たったものが最良
            }
        }
        
        return null;
    }
}