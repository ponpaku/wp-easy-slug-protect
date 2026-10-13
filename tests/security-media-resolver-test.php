<?php
/** WordPress-free but filesystem-backed ownership and policy regression tests. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
require __DIR__ . '/../includes/class-esp-config.php';
require __DIR__ . '/../includes/class-esp-media-file-resolver.php';
require __DIR__ . '/../includes/class-esp-media-access-policy.php';

class ResolverTestDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $rows = [];
    public $metadata = [];
    public $fail = false;
    public function prepare($query, $params) { return json_encode([$query, $params]); }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function get_results($query, $format) {
        $this->last_error = '';
        if ($this->fail) {
            $this->last_error = 'Injected failure';
            return null;
        }
        [$sql, $params] = json_decode($query, true);
        $allowed = array_slice($params, 1);
        $results = [];
        foreach ($this->rows as $row) {
            foreach ($allowed as $part) {
                $prefix = substr($part, 0, -1);
                if ($part === $row['meta_value'] ||
                    (substr($part, -1) === '%' && strncmp($row['meta_value'], $prefix, strlen($prefix)) === 0)) {
                    $results[] = $row;
                    break;
                }
            }
        }
        return array_slice($results, 0, 65);
    }
}
$wpdb = new ResolverTestDB();
function wp_get_attachment_metadata($id, $unfiltered = false) {
    if (!$unfiltered) { throw new RuntimeException('Metadata must be unfiltered'); }
    return $GLOBALS['wpdb']->metadata[$id] ?? false;
}
function wp_upload_dir() { return ['basedir' => $GLOBALS['upload_dir'], 'error' => '']; }
function verify_esp($valid, $reason) {
    if (!$valid) { fwrite(STDERR, "FAIL: $reason\n"); exit(1); }
    echo "PASS: $reason\n";
}
function expect_owner($path, $id) {
    $result = ESP_Media_File_Resolver::resolve($path);
    verify_esp($result['state'] === 'resolved' && $result['attachment_id'] === $id, $path . ' owns attachment ' . $id);
}
$base = sys_get_temp_dir() . '/esp-resolver-' . bin2hex(random_bytes(5));
mkdir($base);
mkdir($base . '/actual');
mkdir($base . '/actual/2026');
mkdir($base . '/actual/2026/10');
symlink($base . '/actual', $base . '/uploads');
$GLOBALS['upload_dir'] = $base . '/uploads';

$files = ['photo.jpg','photo-150x150.jpg','photo-300x300.webp','photo-150x150.avif',
    'picture.png','picture-150x150.png','book.pdf','book-pdf.jpg',
    'edited-e1234567890.jpg','edited-e1234567890-200x100.jpg',
    'camera-scaled.jpg','camera.jpg'];
foreach ($files as $f) { file_put_contents($base . '/actual/2026/10/' . $f, 'test'); }
$wpdb->rows = [
    ['post_id' => 10, 'meta_value' => '2026/10/photo.jpg'],
    ['post_id' => 11, 'meta_value' => '2026/10/picture.png'],
    ['post_id' => 12, 'meta_value' => '2026/10/book.pdf'],
    ['post_id' => 13, 'meta_value' => '2026/10/edited-e1234567890.jpg'],
    ['post_id' => 14, 'meta_value' => '2026/10/camera-scaled.jpg'],
];
$wpdb->metadata = [
    10 => ['file' => '2026/10/photo.jpg', 'sizes' => [
        'thumbnail' => ['file' => 'photo-150x150.jpg'],
        'medium' => ['file' => 'photo-300x300.webp'],
        'avif' => ['file' => 'photo-150x150.avif'],
    ]],
    11 => ['file' => '2026/10/picture.png', 'sizes' => ['thumb' => ['file' => 'picture-150x150.png']]],
    12 => ['file' => '2026/10/book.pdf', 'sizes' => ['full' => ['file' => 'book-pdf.jpg']]],
    13 => ['file' => '2026/10/edited-e1234567890.jpg', 'sizes' => [
        'thumb' => ['file' => 'edited-e1234567890-200x100.jpg']]],
    14 => ['file' => '2026/10/camera-scaled.jpg', 'original_image' => 'camera.jpg'],
];

foreach (['photo.jpg','photo-150x150.jpg','photo-300x300.webp','photo-150x150.avif'] as $file) {
    expect_owner('2026/10/' . $file, 10);
}
expect_owner('2026/10/picture-150x150.png', 11);
expect_owner('2026/10/book-pdf.jpg', 12);
expect_owner('2026/10/edited-e1234567890-200x100.jpg', 13);
expect_owner('2026/10/camera.jpg', 14);

$good = ESP_Media_File_Resolver::validate_request_path('2026/10/photo-150x150.jpg');
verify_esp($good !== null && $good['relative'] === '2026/10/photo-150x150.jpg' &&
    $good['absolute'] === realpath($base . '/actual/2026/10/photo-150x150.jpg'),
    'Symlinked upload ROOT is canonicalized consistently');
verify_esp(ESP_Media_File_Resolver::relative_from_absolute($good['absolute']) === $good['relative'],
    'Canonical absolute and relative paths agree');
foreach (['../other.jpg','2026/10/../10/photo.jpg','2026/10/%2e%2e/photo.jpg',
    '2026//10/photo.jpg','2026\\10\\photo.jpg'] as $bad) {
    verify_esp(ESP_Media_File_Resolver::validate_request_path($bad) === null,
        'Reject suspicious request path: ' . $bad);
}
mkdir($base . '/actual/escape');
symlink($base . '/actual/2026/10/photo.jpg', $base . '/actual/escape/linked.jpg');
verify_esp(ESP_Media_File_Resolver::validate_request_path('escape/linked.jpg') === null,
    'Nested symbolic links are rejected');
mkdir($base . '/uploads-backup');
file_put_contents($base . '/uploads-backup/photo.jpg', 'other');
verify_esp(ESP_Media_File_Resolver::relative_from_absolute(realpath($base . '/uploads-backup/photo.jpg')) === null,
    'Sibling prefix uploads-backup is not part of uploads');

verify_esp(ESP_Media_File_Resolver::resolve('2026/10/not-registered.jpg')['state'] === 'unresolved',
    'Unregistered media is unresolved, not public');
$wpdb->rows[] = ['post_id' => 20, 'meta_value' => '2026/10/photo-150x150.jpg'];
verify_esp(ESP_Media_File_Resolver::resolve('2026/10/photo-150x150.jpg')['state'] === 'ambiguous',
    'Derivative and independent attachment collision is ambiguous');
array_pop($wpdb->rows);
$wpdb->fail = true;
verify_esp(ESP_Media_File_Resolver::resolve('2026/10/photo-150x150.jpg')['state'] === 'error',
    'DB failure returns error rather than unresolved');
$wpdb->fail = false;
verify_esp(ESP_Media_Access_Policy::decide(['state'=>'unresolved'], '', [])['action'] === 'deny',
    'Unknown ownership is denied');
verify_esp(ESP_Media_Access_Policy::decide(['state'=>'ambiguous'], '', [])['action'] === 'deny',
    'Ambiguous ownership is denied');
$resolved = ESP_Media_File_Resolver::resolve('2026/10/photo-150x150.jpg');
verify_esp(ESP_Media_Access_Policy::decide($resolved, '', [])['action'] === 'allow',
    'Confirmed public media may be delivered');
verify_esp(ESP_Media_Access_Policy::decide($resolved, 'secret', ['secret'=>['path'=>'/members']])['action'] === 'authenticate',
    'Protected derivative must be authenticated');
verify_esp(ESP_Media_Access_Policy::decide($resolved, 'orphan', [])['action'] === 'deny',
    'Orphaned protected media is denied');

@unlink($base . '/actual/escape/linked.jpg');
@rmdir($base . '/actual/escape');
foreach ($files as $f) { @unlink($base . '/actual/2026/10/' . $f); }
@rmdir($base . '/actual/2026/10');
@rmdir($base . '/actual/2026');
@unlink($base . '/uploads');
@rmdir($base . '/actual');
@unlink($base . '/uploads-backup/photo.jpg');
@rmdir($base . '/uploads-backup');
@rmdir($base);
echo "All ownership resolver regression tests passed.\n";
