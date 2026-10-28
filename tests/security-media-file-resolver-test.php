<?php
/** Issue #42: bounded, evidence-based media ownership (no WordPress bootstrap). */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

class ResolverFakeDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $last_error = '';
    public $records = [];
    public $failed = false;
    public $query_count = 0;
    public $too_many = false;

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return ['query' => $query, 'args' => $args];
    }
    public function esc_like($value) {
        return addcslashes($value, '_%\\');
    }
    public function get_results($prepared, $mode) {
        $this->query_count++;
        $this->last_error = $this->failed ? 'simulated database failure' : '';
        if ($this->failed) { return null; }
        $params = array_slice($prepared['args'], 1);
        $found = [];
        foreach ($this->records as $row) {
            if (($row['type'] ?? 'attachment') !== 'attachment') { continue; }
            foreach ($params as $candidate) {
                if (substr($candidate, -1) === '%') {
                    // Stub deliberately supports only simple fixture edit names.
                    if (strncmp($row['path'], substr($candidate, 0, -1),
                        strlen($candidate) - 1) !== 0) { continue; }
                } elseif (strcasecmp($row['path'], $candidate) !== 0) {
                    continue;
                }
                $found[] = ['post_id' => (string) $row['id'], 'meta_value' => $row['path']];
                break;
            }
        }
        return $this->too_many ? array_fill(0, 65,
            ['post_id' => '1', 'meta_value' => '2026/10/photo.jpg']) : $found;
    }
}
$wpdb = new ResolverFakeDB();
$metadata = [];
$upload_root = sys_get_temp_dir() . '/esp-resolve-' . bin2hex(random_bytes(7));
mkdir($upload_root, 0700, true);
mkdir($upload_root . '/2026/10', 0700, true);
mkdir($upload_root . '/2026/11', 0700, true);

function wp_upload_dir() {
    return ['basedir' => $GLOBALS['upload_root'], 'error' => ''];
}
function wp_get_attachment_metadata($id, $unfiltered = false) {
    if (!$unfiltered) { throw new RuntimeException('Resolver must fetch unfiltered metadata'); }
    return $GLOBALS['metadata'][$id] ?? false;
}
function check_resolver($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
function write_fixture($relative) {
    $file = rtrim($GLOBALS['upload_root'], '/') . '/' . $relative;
    $directory = dirname($file);
    if (!is_dir($directory)) { mkdir($directory, 0700, true); }
    file_put_contents($file, 'esp fixture');
    return $file;
}
function expect_status($resolver, $relative, $status, $id = null) {
    $actual = $resolver->resolve(write_fixture($relative));
    check_resolver($actual['status'] === $status &&
        ($status !== 'resolved' || $actual['attachment_id'] === $id),
        "$relative -> $status" . ($id === null ? '' : " ($id)"));
}
require __DIR__ . '/../includes/class-esp-media-file-resolver.php';
$resolver = new ESP_Media_File_Resolver();
try {
    $wpdb->records = [
        ['id' => 10, 'path' => '2026/10/photo-scaled.jpg'],
        ['id' => 20, 'path' => '2026/10/report.pdf'],
        ['id' => 30, 'path' => '2026/10/clip.gif'],
        ['id' => 40, 'path' => '2026/10/edited-e20260101090000.png'],
    ];
    $metadata = [
        10 => [
            'file' => '2026/10/photo-scaled.jpg',
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
                'medium' => ['file' => 'photo-300x200.webp'],
                'avif' => ['file' => 'photo-80x80.avif'],
                'converted' => ['file' => 'photo-300x200.png'],
            ],
            'original_image' => 'photo.jpg',
            'source_image' => 'photo.heic',
        ],
        20 => ['file' => '2026/10/report.pdf',
            'sizes' => ['thumbnail' => ['file' => 'report-pdf-150x150.jpg']]],
        30 => ['file' => '2026/10/clip.gif',
            'animated_video' => ['file' => 'clip.mp4'],
            'animated_video_poster' => 'clip-poster.jpg'],
        40 => ['file' => '2026/10/edited-e20260101090000.png',
            'sizes' => ['thumbnail' => ['file' => 'edited-250x250.png']]],
    ];
    expect_status($resolver, '2026/10/photo-scaled.jpg', 'resolved', 10);
    expect_status($resolver, '2026/10/photo-150x150.jpg', 'resolved', 10);
    expect_status($resolver, '2026/10/photo-300x200.webp', 'resolved', 10);
    expect_status($resolver, '2026/10/photo-80x80.avif', 'resolved', 10);
    expect_status($resolver, '2026/10/photo-300x200.png', 'resolved', 10);
    expect_status($resolver, '2026/10/photo.jpg', 'resolved', 10);
    expect_status($resolver, '2026/10/photo.heic', 'resolved', 10);
    expect_status($resolver, '2026/10/report-pdf-150x150.jpg', 'resolved', 20);
    expect_status($resolver, '2026/10/clip.mp4', 'resolved', 30);
    expect_status($resolver, '2026/10/clip-poster.jpg', 'resolved', 30);
    $metadata[30]['animated_video'] = 'clip-animated.mp4';
    $metadata[30]['animated_video_poster'] = 'clip.gif-poster.jpg';
    expect_status($resolver, '2026/10/clip-animated.mp4', 'resolved', 30);
    expect_status($resolver, '2026/10/clip.gif-poster.jpg', 'resolved', 30);
    expect_status($resolver, '2026/10/edited-250x250.png', 'resolved', 40);
    expect_status($resolver, '2026/10/unknown-150x150.avif', 'unresolved');
    expect_status($resolver, '2026/11/photo-150x150.jpg', 'unresolved');

    // Filename similarity is only candidate generation, never proof.
    $wpdb->records[] = ['id' => 50, 'path' => '2026/10/photo.png'];
    $metadata[50] = ['file' => '2026/10/photo.png',
        'sizes' => ['thumbnail' => ['file' => 'different-80x80.avif']]];
    expect_status($resolver, '2026/10/photo-80x80.avif', 'resolved', 10);

    // Attachment collision: a derivative belongs to another exact owner.
    $wpdb->records[] = ['id' => 60, 'path' => '2026/10/photo-80x80.avif'];
    expect_status($resolver, '2026/10/photo-80x80.avif', 'ambiguous');

    // Duplicate original records, including SQL collation case-insensitivity.
    $wpdb->records[] = ['id' => 61, 'path' => '2026/10/report.pdf'];
    expect_status($resolver, '2026/10/report.pdf', 'ambiguous');
    $wpdb->records[] = ['id' => 62, 'path' => '2026/10/REPORT.pdf'];
    // Wrong-case records are not proof for report.pdf.
    expect_status($resolver, '2026/10/report.pdf', 'ambiguous');
    $wpdb->records = array_values(array_filter($wpdb->records,
        static function ($row) { return !in_array($row['id'], [60, 61], true); }));

    // Temporary sideload and backup-size metadata cannot prove ownership.
    $wpdb->records[] = ['id' => 70, 'path' => '2026/10/staging.jpg'];
    $metadata[70] = ['file' => '2026/10/staging.jpg',
        'sizes' => [], 'original_image' => 'not-this.jpg'];
    expect_status($resolver, '2026/10/staging-200x200.jpg', 'unresolved');
    // A metadata-relative path must not be interpreted from an unrelated root.
    $wpdb->records[] = ['id' => 71, 'path' => '2026/10/mismatch.jpg'];
    $metadata[71] = ['file' => '2026/11/mismatch.jpg',
        'sizes' => ['thumbnail' => ['file' => 'mismatch-80x80.webp']]];
    expect_status($resolver, '2026/10/mismatch-80x80.webp', 'unresolved');

    $wpdb->failed = true;
    expect_status($resolver, '2026/10/photo-150x150.jpg', 'error');
    $wpdb->failed = false;
    $wpdb->too_many = true;
    expect_status($resolver, '2026/10/photo-150x150.jpg', 'error');
    $wpdb->too_many = false;
    $metadata[10]['sizes'] = array_fill(0, 129, ['file' => 'other.jpg']);
    expect_status($resolver, '2026/10/photo-300x200.webp', 'error');
    $metadata[10]['sizes'] = ['thumbnail' => ['file' => 'photo-150x150.jpg']];

    // The configured root may itself be a symlink.
    $link = $upload_root . '-link';
    check_resolver(symlink($upload_root, $link), 'create trusted uploads root symlink');
    $GLOBALS['upload_root'] = $link;
    expect_status($resolver, '2026/10/photo-150x150.jpg', 'resolved', 10);
    $GLOBALS['upload_root'] = $upload_root;

    $outside = $upload_root . '-backup';
    mkdir($outside, 0700);
    $bad = $outside . '/photo.jpg';
    file_put_contents($bad, 'outside');
    check_resolver($resolver->resolve($bad)['status'] === 'error',
        'uploads-backup sibling is outside trusted root');
    symlink($outside, $upload_root . '/escape');
    check_resolver($resolver->resolve($upload_root . '/escape/photo.jpg')['status'] === 'error',
        'nested link cannot escape uploads root');
    check_resolver($resolver->resolve($upload_root . '/not-existing.jpg')['status'] === 'error',
        'missing absolute file is not considered a public attachment');
    unlink($upload_root . '/escape');
    unlink($bad);
    rmdir($outside);
    unlink($link);
} finally {
    foreach (glob($upload_root . '/2026/{10,11}/*', GLOB_BRACE) as $file) {
        @unlink($file);
    }
    rmdir($upload_root . '/2026/10');
    rmdir($upload_root . '/2026/11');
    rmdir($upload_root . '/2026');
    rmdir($upload_root);
}
echo "All WordPress media file resolver tests passed.\n";
