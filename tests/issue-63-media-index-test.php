<?php
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/includes/class-esp63-media-index-prototype.php';

function check63($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$meta = [
    'file' => '2026/10/cat-scaled.jpg',
    'sizes' => [
        'thumbnail' => ['file' => 'cat-150x150.jpg'],
        'webp' => ['file' => 'cat-300x200.webp'],
    ],
    'original_image' => 'cat.jpg',
];
$paths = ESP63_Media_Index_Prototype::enumerate('2026/10/cat-scaled.jpg', $meta);
check63($paths === ['2026/10/cat-scaled.jpg', '2026/10/cat-150x150.jpg',
    '2026/10/cat-300x200.webp', '2026/10/cat.jpg'], 'expected WordPress paths');
check63(ESP63_Media_Index_Prototype::enumerate('2026/10/report.pdf',
    ['sizes' => ['full' => ['file' => 'report-pdf.jpg']]])
    === ['2026/10/report.pdf', '2026/10/report-pdf.jpg'], 'PDF metadata without file');
check63(ESP63_Media_Index_Prototype::enumerate('2026/10/a.jpg',
    ['sizes' => ['bad' => ['file' => '../secret.jpg']]]) === null,
    'traversal metadata must be rejected');
check63(!ESP63_Media_Index_Prototype::valid_path('2026/../private.jpg'), 'invalid path');
check63(!ESP63_Media_Index_Prototype::valid_path('/root.jpg'), 'absolute path');
check63(strlen(ESP63_Media_Index_Prototype::hash_path('2026/10/cat.jpg')) === 64,
    'hash format');

final class ESP63_Lookup_Fake {
    public $last_error = '';
    public $rows = [];
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql, $mode) { return $this->rows; }
}
$fake = new ESP63_Lookup_Fake();
$path = '2026/10/cat.jpg';
check63(ESP63_Media_Index_Prototype::lookup($fake, 'wp_esp_media_files', $path)['status']
    === 'unresolved', 'missing rows not a known public result');
$fake->rows = [['file_path' => $path, 'attachment_id' => '123', 'protected_path_id' => 'secret_A']];
$r = ESP63_Media_Index_Prototype::lookup($fake, 'wp_esp_media_files', $path);
check63($r['status'] === 'resolved' && $r['attachment_id'] === 123 &&
    $r['protected_path_id'] === 'secret_A', 'protected ID in one lookup');
$fake->rows[] = ['file_path' => $path, 'attachment_id' => '456', 'protected_path_id' => ''];
check63(ESP63_Media_Index_Prototype::lookup($fake, 'wp_esp_media_files', $path)['status']
    === 'ambiguous', 'multiple owners must be ambiguous');
$fake->rows = [['file_path' => '2026/10/Different.jpg',
    'attachment_id' => '123', 'protected_path_id' => '']];
check63(ESP63_Media_Index_Prototype::lookup($fake, 'wp_esp_media_files', $path)['status']
    === 'error', 'incorrect hash/path evidence must never permit public');
$fake->rows = [['file_path' => $path, 'attachment_id' => '123', 'protected_path_id' => '']];
$fake->last_error = 'database unavailable';
check63(ESP63_Media_Index_Prototype::lookup($fake, 'wp_esp_media_files', $path)['status']
    === 'error', 'DB errors must be distinct');
echo "issue-63 index prototype: PASS\n";
