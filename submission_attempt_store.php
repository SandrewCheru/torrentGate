<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\SubmissionAttemptStore;

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
    '/torrentgate-attempt-' . bin2hex(random_bytes(6));

if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Could not create test directory.');
}

$sessionId = 'test-session-123456';
$uploadId = bin2hex(random_bytes(16));
$otherUploadId = bin2hex(random_bytes(16));

try {
    $store = new SubmissionAttemptStore($tempDir);

    // No record should exist initially.
    check(
        $store->get($sessionId, $uploadId) === null,
        'No attempt exists initially'
    );

    // The first begin creates an uncertain record.
    check(
        $store->begin($sessionId, $uploadId),
        'First attempt can be started'
    );

    $record = $store->get($sessionId, $uploadId);

    check(
        ($record['status'] ?? null) === 'uncertain',
        'New attempt is initially marked uncertain'
    );

    // A second begin must not permit another RPC attempt.
    check(
        !$store->begin($sessionId, $uploadId),
        'Existing attempt cannot be started again'
    );

    // Store a confirmed result.
    $store->complete(
        $sessionId,
        $uploadId,
        'added',
        [
            'id' => 42,
            'hash_string' => 'examplehash',
            'name' => 'Example torrent',
        ]
    );

    $record = $store->get($sessionId, $uploadId);

    check(
        ($record['status'] ?? null) === 'added',
        'Confirmed added status is persisted'
    );

    check(
        ($record['result']['id'] ?? null) === 42,
        'Submission result is persisted'
    );

    check(
        !$store->begin($sessionId, $uploadId),
        'Completed attempt cannot be restarted'
    );

    // A different upload in the same session can start independently.
    check(
        $store->begin($sessionId, $otherUploadId),
        'Different upload can start its own attempt'
    );

    // A different session has a separate namespace.
    check(
        $store->begin('another-session', $uploadId),
        'Different session can use the same upload ID independently'
    );

    // Invalid status must be rejected.
    $invalidStatusRejected = false;

    try {
        $store->complete(
            $sessionId,
            $otherUploadId,
            'retry'
        );
    } catch (RuntimeException) {
        $invalidStatusRejected = true;
    }

    check(
        $invalidStatusRejected,
        'Invalid attempt status is rejected'
    );

    // Invalid IDs must be rejected.
    $invalidIdRejected = false;

    try {
        $store->begin($sessionId, '../invalid');
    } catch (RuntimeException) {
        $invalidIdRejected = true;
    }

    check(
        $invalidIdRejected,
        'Invalid upload ID is rejected'
    );

    // Confirm uncertain can be stored explicitly.
    $store->complete(
        $sessionId,
        $otherUploadId,
        'uncertain'
    );

    check(
        ($store->get($sessionId, $otherUploadId)['status'] ?? null)
            === 'uncertain',
        'Uncertain outcome can be persisted'
    );

    // Check the on-disk permissions.
    $files = glob($tempDir . '/submission-attempts/*.json') ?: [];
    $permissionsCorrect = count($files) === 3;

    foreach ($files as $file) {
        $permissionsCorrect = $permissionsCorrect &&
            ((fileperms($file) & 0777) === 0600);
    }

    check(
        $permissionsCorrect,
        'Attempt records are stored with mode 0600'
    );

} finally {
    foreach (glob($tempDir . '/submission-attempts/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    foreach (glob($tempDir . '/submission-attempts/.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    if (is_dir($tempDir . '/submission-attempts')) {
        rmdir($tempDir . '/submission-attempts');
    }

    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
