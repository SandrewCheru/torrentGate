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
        $passed++;
        echo "PASS: {$description}\n";
    } else {
        $failed++;
        echo "FAIL: {$description}\n";
    }
}

$tempDir = sys_get_temp_dir() . '/torrentgate-controller-' . bin2hex(random_bytes(6));

if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Could not create test temporary directory.');
}

$fixture = dirname(__DIR__) . '/vendor/rhilip/bencode/tests/asserts/v1-single.torrent';

if (!is_file($fixture)) {
    throw new RuntimeException('The v1-single.torrent fixture was not found.');
}

$fixtureData = file_get_contents($fixture);

if ($fixtureData === false) {
    throw new RuntimeException('Could not read the torrent fixture.');
}

$manager = new UploadSessionManager($tempDir);
$controller = new UploadController($manager);
$session = [];

try {
    // GET state should initialise the CSRF token and empty upload state.
    $response = $controller->handle('GET', 'state', $session);

    check($response['status'] === 200, 'GET state returns HTTP 200');
    check($response['body']['ok'] === true, 'GET state reports success');
    check(
        is_string($response['body']['csrf_token'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/', $response['body']['csrf_token']) === 1,
        'GET state supplies a 64-character CSRF token'
    );
    check(
        ($response['body']['summary']['uploaded'] ?? null) === 0,
        'Initial upload count is zero'
    );

    $csrf = $response['body']['csrf_token'];

    // Missing CSRF token must reject mutations.
    $response = $controller->handle(
        'POST',
        'clear',
        $session
    );

    check($response['status'] === 403, 'POST without CSRF token is rejected');

    // Unsupported methods must be rejected.
    $response = $controller->handle('PUT', 'state', $session);

    check($response['status'] === 405, 'Unsupported HTTP method is rejected');

    // Malformed upload field.
    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => 'not-an-upload-array'],
        $csrf
    );

    check($response['status'] === 400, 'Malformed upload field is rejected');

    // Valid torrent upload.
    $uploadField = [
        'name' => [0 => 'fixture.torrent'],
        'tmp_name' => [0 => $fixture],
        'error' => [0 => UPLOAD_ERR_OK],
        'size' => [0 => strlen($fixtureData)],
    ];

    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => $uploadField],
        $csrf,
        static fn(string $path): bool => true
    );

    check($response['status'] === 200, 'Valid upload request returns HTTP 200');
    check($response['body']['ok'] === true, 'Valid upload request reports success');
    check(
        ($response['body']['results'][0]['accepted'] ?? false) === true,
        'Valid torrent is accepted by the session manager'
    );
    check(
        ($response['body']['summary']['valid'] ?? 0) === 1,
        'Valid upload appears in the session summary'
    );

    $uploads = $response['body']['uploads'];
    $uploadId = $uploads[0]['id'] ?? null;

    check(is_string($uploadId), 'Uploaded torrent has a session upload ID');

    // The upload should be selected by default.
    check(
        ($uploads[0]['selected'] ?? false) === true,
        'New valid upload is selected by default'
    );

    // Deselect the upload.
    $response = $controller->handle(
        'POST',
        'select',
        $session,
        ['id' => $uploadId, 'selected' => false],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Deselect request succeeds');
    check(
        ($response['body']['summary']['selected_count'] ?? -1) === 0,
        'Deselecting updates selected count'
    );

    // Select it again.
    $response = $controller->handle(
        'POST',
        'select',
        $session,
        ['id' => $uploadId, 'selected' => true],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Select request succeeds');
    check(
        ($response['body']['summary']['selected_count'] ?? -1) === 1,
        'Selecting updates selected count'
    );

    // Invalid selection type.
    $response = $controller->handle(
        'POST',
        'select',
        $session,
        ['id' => $uploadId, 'selected' => 'false'],
        [],
        $csrf
    );

    check($response['status'] === 400, 'Non-boolean selection value is rejected');

    // Declared size mismatch.
    $mismatchField = [
        'name' => [0 => 'mismatch.torrent'],
        'tmp_name' => [0 => $fixture],
        'error' => [0 => UPLOAD_ERR_OK],
        'size' => [0 => strlen($fixtureData) + 1],
    ];

    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => $mismatchField],
        $csrf,
        static fn(string $path): bool => true
    );

    check(
        ($response['body']['results'][0]['accepted'] ?? true) === false,
        'Upload with mismatched declared size is rejected'
    );

    // Upload provenance check.
    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => $uploadField],
        $csrf,
        static fn(string $path): bool => false
    );

    check(
        ($response['body']['results'][0]['accepted'] ?? true) === false,
        'Unverified upload temporary file is rejected'
    );

    // PHP upload error is reported per file without blocking the request.
    $errorField = [
        'name' => [0 => 'partial.torrent'],
        'tmp_name' => [0 => ''],
        'error' => [0 => UPLOAD_ERR_PARTIAL],
        'size' => [0 => 0],
    ];

    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => $errorField],
        $csrf
    );

    check($response['status'] === 200, 'PHP upload error is handled as a per-file result');
    check(
        ($response['body']['results'][0]['accepted'] ?? true) === false,
        'Partially uploaded file is rejected'
    );

    // Remove the valid upload.
    $response = $controller->handle(
        'POST',
        'remove',
        $session,
        ['id' => $uploadId],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Remove request succeeds');
    check(
        ($response['body']['summary']['uploaded'] ?? -1) === 0,
        'Removing upload updates the summary'
    );

    // Removing the same ID again should return not found.
    $response = $controller->handle(
        'POST',
        'remove',
        $session,
        ['id' => $uploadId],
        [],
        $csrf
    );

    check($response['status'] === 404, 'Removing an unknown upload returns HTTP 404');

    // Add another upload and clear the session.
    $response = $controller->handle(
        'POST',
        'upload',
        $session,
        [],
        ['files' => $uploadField],
        $csrf,
        static fn(string $path): bool => true
    );

    check(
        ($response['body']['results'][0]['accepted'] ?? false) === true,
        'A new upload can be added after removal'
    );

    $response = $controller->handle(
        'POST',
        'clear',
        $session,
        [],
        [],
        $csrf
    );

    check($response['status'] === 200, 'Clear request succeeds');
    check(
        ($response['body']['summary']['uploaded'] ?? -1) === 0,
        'Clear empties the upload session'
    );

    // Unknown action.
    $response = $controller->handle(
        'POST',
        'unknown',
        $session,
        [],
        [],
        $csrf
    );

    check($response['status'] === 404, 'Unknown action returns HTTP 404');

} finally {
    // Remove test-managed temporary files and directory.
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
