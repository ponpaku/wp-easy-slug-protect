<?php
/** CLI-only relocation and media seed, never part of the distributable plugin. */
if (PHP_SAPI !== 'cli' || getenv('ESP_FG_CI_HTTP_AUTH') !== '1') { exit(1); }
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8096';
$_SERVER['REQUEST_URI'] = '/blog/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_USE_THEMES', false);
require '/var/www/esp-fg-subdir/blog/wp-load.php';
if (DB_NAME !== 'esp_fg_lifecycle' ||
    realpath(ABSPATH) !== '/var/www/esp-fg-subdir/blog' ||
    ESP_Fast_Gate_Deployment::route_fast() ||
    ESP_Fast_Gate_WP::active()) { exit(1); }
function fg_subdir_require($ok, $label) {
    if (!$ok) { throw new RuntimeException('FG subdirectory setup: '.$label); }
}
if (($argv[1] ?? '') === 'move-home') {
    fg_subdir_require(home_url() === 'http://127.0.0.1:8096', 'original fixture origin');
    fg_subdir_require(update_option('home', 'http://127.0.0.1:8096/blog') &&
        update_option('siteurl', 'http://127.0.0.1:8096/blog'), 'relocate WordPress');
    exit;
}
fg_subdir_require(($argv[1] ?? '') === 'seed-media' &&
    home_url('/') === 'http://127.0.0.1:8096/blog/' &&
    site_url('/') === home_url('/'), 'new home');
$uploads = wp_upload_dir(null, false);
fg_subdir_require(realpath($uploads['basedir']) ===
    '/var/www/esp-fg-subdir/blog/wp-content/uploads' &&
    $uploads['baseurl'] === home_url('/wp-content/uploads'), 'physical relocation');
fg_subdir_require(ESP_Media_Protection::ensure_rewrite_policy() === true &&
    ESP_Media_Protection::audit_rewrite_policy(), 'regenerate legacy Rewrite');
foreach (['fg-ci-range.pdf'=>'application/pdf','fg-ci-range.mp4'=>'video/mp4'] as $name=>$mime) {
    $file = $uploads['basedir'].'/'.$name;
    fg_subdir_require(is_file($file) && filesize($file)>100, 'seed file exists');
    $id = wp_insert_attachment(['post_title'=>'FG Range '.$name,'post_mime_type'=>$mime,
        'post_status'=>'inherit'], $file, 0, true);
    fg_subdir_require(!is_wp_error($id) && $id>0 &&
        realpath(get_attached_file($id))===realpath($file), 'real attachment');
    fg_subdir_require((bool)update_post_meta($id, ESP_Media_Index::PROTECTION_META, 'fg_path'),
        'assign protection using normal WordPress hook');
    ESP_Media_Index::finish_noop_writes();
    fg_subdir_require(ESP_Media_Index::reindex_attachment($id), 'publish index');
    $result = ESP_Media_Index::resolve($file);
    fg_subdir_require($result['status']==='resolved' &&
        $result['protected_path_id']==='fg_path', 'verify protected ownership');
}
flush_rewrite_rules(false);
echo "FG_SUBDIR_SETUP ready real path, legacy Rewrite, protected attachments\n";
