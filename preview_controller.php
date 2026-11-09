<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\Http\UploadController;
use TorrentGate\UploadSessionManager;

$passed = 0;
$failed = 0;

function check(bool $condition, string $description): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;
        echo "PASS: {$description}\n";
    } else {
        ++$failed;
        echo "FAIL: {$description}\n";
    }
}

$tempDir = sys_get_temp_dir() .
    '/torrentgate-preview-' . bin2hex(random_bytes(6));

if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Could not create test directory.');
}

try {
    $fixture = dirname(__DIR__) .
        '/vendor/rhilip/bencode/tests/asserts/v1-single.torrent';

    $torrentData = file_get_contents($fixture);

    if ($torrentData === false) {
        throw new RuntimeException('Could not read torrent fixture.');
    }

    $manager = new UploadSessionManager($tempDir);
    $controller = new UploadController($manager);
    $session = [];

    // Initialise the session and obtain its CSRF token.
    $stateResponse = $controller->handle('GET', 'state', $session);

    check(
        $stateResponse['status'] === 200,
        'Initial session state succeeds'
    );

    $csrf = $stateResponse['body']['csrf_token'];

    // Add a valid torrent.
    $state = &$session['torrentgate_upload_state'];

    $first = $manager->addUpload(
        $state,
        'first.torrent',
        $torrentData
    );

    check(
        ($first['accepted'] ?? false) === true &&
        ($first['upload']['status'] ?? null) === 'valid',
        'First valid torrent is added'
    );

    $firstId = $first['upload']['id'];

    // Add the same torrent again; it should be a duplicate.
    $second = $manager->addUpload(
        $state,
        'duplicate.torrent',
        $torrentData
    );

    check(
        ($second['accepted'] ?? false) === true &&
        ($second['upload']['duplicate'] ?? false) === true,
        'Duplicate torrent is recognised'
    );

    // Add an invalid upload.
    $invalid = $manager->addUpload(
        $state,
        'invalid.torrent',
        'not a torrent'
    );

    check(
        ($invalid['accepted'] ?? false) === true &&
        ($invalid['upload']['status'] ?? null) === 'invalid',
        'Invalid torrent is recorded'
    );

    // Save a destination.
    $destinationResponse = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => 'Music/Live'],
        [],
        $csrf
    );

    check(
        $destinationResponse['status'] === 200,
        'Destination can be saved before preview'
    );

    // Preview is read-only and does not require a CSRF header.
    $previewResponse = $controller->handle(
        'GET',
        'preview',
        $session
    );

    check(
        $previewResponse['status'] === 200,
        'Preview request succeeds without a CSRF header'
    );

    $preview = $previewResponse['body']['preview'] ?? [];

    check(
        ($preview['count'] ?? null) === 1,
        'Preview includes only the selected unique valid torrent'
    );

    check(
        count($preview['torrents'] ?? []) === 1,
        'Preview contains one torrent record'
    );

    $torrent = $preview['torrents'][0] ?? [];

    check(
        ($torrent['id'] ?? null) === $firstId,
        'Preview identifies the selected upload'
    );

    check(
        ($torrent['name'] ?? null) === 'first.torrent',
        'Preview includes the display filename'
    );

    check(
        is_int($torrent['total_size'] ?? null) &&
        $torrent['total_size'] >= 0,
        'Preview includes the torrent size'
    );

    check(
        is_int($torrent['file_count'] ?? null) &&
        $torrent['file_count'] > 0,
        'Preview includes the file count'
    );

    check(
        ($torrent['protocol'] ?? null) === 'v1',
        'Preview includes the torrent protocol'
    );

    check(
        is_string($torrent['info_hash_v1'] ?? null),
        'Preview includes the v1 info hash'
    );

    check(
        ($preview['total_size'] ?? null) === $torrent['total_size'],
        'Preview total size matches the selected torrent'
    );

    check(
        ($preview['destination']['relative_path'] ?? null) === 'Music/Live',
        'Preview includes the saved relative destination'
    );

    check(
        ($preview['destination']['absolute_path'] ?? null) ===
            '/data2/transmission/seeding/Music/Live',
        'Preview includes the resolved destination'
    );

    check(
        !array_key_exists('temporary_path', $torrent) &&
        !array_key_exists('path', $torrent),
        'Preview does not expose temporary filesystem paths'
    );

    // Deselect the first torrent; the duplicate must not appear.
    $manager->setSelected($state, $firstId, false);

    $previewResponse = $controller->handle(
        'GET',
        'preview',
        $session
    );

    check(
        ($previewResponse['body']['preview']['count'] ?? null) === 0,
        'Preview is empty when no unique valid torrent is selected'
    );

    check(
        ($previewResponse['body']['preview']['total_size'] ?? null) === 0,
        'Empty preview has a zero total size'
    );

    // Remove the first upload; the duplicate should become selectable.
    $manager->removeUpload($state, $firstId);

    $previewResponse = $controller->handle(
        'GET',
        'preview',
        $session
    );

    $promotedPreview = $previewResponse['body']['preview'] ?? [];

    check(
        ($promotedPreview['count'] ?? null) === 1,
        'Former duplicate appears after the original is removed'
    );

    check(
        ($promotedPreview['torrents'][0]['name'] ?? null) ===
            'duplicate.torrent',
        'Promoted duplicate is the torrent shown in the preview'
    );

    // Preview must not change the saved destination.
    check(
        ($promotedPreview['destination']['relative_path'] ?? null) ===
            'Music/Live',
        'Preview does not alter the saved destination'
    );

} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }

    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
