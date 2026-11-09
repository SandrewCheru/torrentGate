<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\DestinationValidator;
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
    '/torrentgate-destination-' . bin2hex(random_bytes(6));

if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Could not create test directory.');
}

try {
    $manager = new UploadSessionManager($tempDir);
    $controller = new UploadController($manager);
    $session = [];

    // Initial state.
    $response = $controller->handle('GET', 'state', $session);

    check($response['status'] === 200, 'Initial state request succeeds');

    $csrf = $response['body']['csrf_token'] ?? null;

    check(is_string($csrf), 'Initial state provides a CSRF token');

    $destination = $response['body']['destination'] ?? [];

    check(
        ($destination['root'] ?? null) === DestinationValidator::ROOT,
        'Initial destination uses the fixed root'
    );

    check(
        ($destination['relative_path'] ?? null) === '',
        'Initial relative destination is empty'
    );

    check(
        ($destination['absolute_path'] ?? null) === DestinationValidator::ROOT,
        'Initial absolute destination is the fixed root'
    );

    // Missing CSRF.
    $response = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => 'Music']
    );

    check(
        $response['status'] === 403,
        'Destination update without CSRF is rejected'
    );

    // Valid relative destination.
    $response = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => 'Music/Live Concerts'],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Valid destination update succeeds');

    check(
        ($response['body']['destination']['relative_path'] ?? null)
            === 'Music/Live Concerts',
        'Valid relative destination is saved'
    );

    check(
        ($response['body']['destination']['absolute_path'] ?? null)
            === DestinationValidator::ROOT . '/Music/Live Concerts',
        'Absolute destination is constructed from the fixed root'
    );

    // State should return the saved destination.
    $response = $controller->handle('GET', 'state', $session);

    check(
        ($response['body']['destination']['relative_path'] ?? null)
            === 'Music/Live Concerts',
        'Saved destination persists across state requests'
    );

    // Invalid traversal must be rejected.
    $response = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => '../outside'],
        [],
        $csrf
    );

    check(
        $response['status'] === 400,
        'Path traversal destination is rejected'
    );

    // Invalid input must not overwrite the last valid value.
    $response = $controller->handle('GET', 'state', $session);

    check(
        ($response['body']['destination']['relative_path'] ?? null)
            === 'Music/Live Concerts',
        'Rejected destination leaves the previous setting unchanged'
    );

    // Non-string input.
    $response = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => ['Music']],
        [],
        $csrf
    );

    check(
        $response['status'] === 400,
        'Non-string destination input is rejected'
    );

    // Empty destination resets to the fixed root.
    $response = $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => ''],
        [],
        $csrf
    );

    check(
        $response['status'] === 200,
        'Empty destination can be saved'
    );

    check(
        ($response['body']['destination']['absolute_path'] ?? null)
            === DestinationValidator::ROOT,
        'Empty destination resolves to the fixed root'
    );

    // Save a subdirectory again and verify clearing uploads does not reset it.
    $controller->handle(
        'POST',
        'destination',
        $session,
        ['relative_path' => 'TV/Documentaries'],
        [],
        $csrf
    );

    $response = $controller->handle(
        'POST',
        'clear',
        $session,
        [],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Queue clear request succeeds');

    $response = $controller->handle('GET', 'state', $session);

    check(
        ($response['body']['destination']['relative_path'] ?? null)
            === 'TV/Documentaries',
        'Clearing the queue preserves the destination setting'
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
