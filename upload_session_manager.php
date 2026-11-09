<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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

$fixturePath = dirname(__DIR__) .
    '/vendor/rhilip/bencode/tests/asserts/v1-single.torrent';

$fixture = file_get_contents($fixturePath);

if ($fixture === false) {
    fwrite(STDERR, "Could not read v1-single.torrent fixture.\n");
    exit(1);
}

$temporaryDirectory = sys_get_temp_dir() .
    '/torrentgate-test-' . bin2hex(random_bytes(8));

if (!mkdir($temporaryDirectory, 0700)) {
    fwrite(STDERR, "Could not create test temporary directory.\n");
    exit(1);
}

try {
    $manager = new UploadSessionManager($temporaryDirectory);
    $state = [];

    // Initial state.
    $summary = $manager->getSummary($state);

    check(
        $summary['uploaded'] === 0 &&
        $summary['valid'] === 0 &&
        $summary['invalid'] === 0,
        'New session starts empty'
    );

    // First valid upload.
    $first = $manager->addUpload(
        $state,
        'first.torrent',
        $fixture
    );

    check(
        $first['accepted'] === true &&
        $first['upload']['status'] === 'valid',
        'Valid torrent is accepted'
    );

    $firstId = $first['upload']['id'];
    $firstPath = $manager->getTemporaryPath($firstId);

    check(
        is_string($firstPath) && is_file($firstPath),
        'Original torrent bytes are stored in a temporary file'
    );

    check(
        $firstPath !== null &&
        (fileperms($firstPath) & 0777) === 0600,
        'Temporary torrent file has restrictive permissions'
    );

    check(
        $first['upload']['selected'] === true,
        'First valid non-duplicate upload is selected by default'
    );

    // Duplicate upload.
    $second = $manager->addUpload(
        $state,
        'second.torrent',
        $fixture
    );

    $secondId = $second['upload']['id'];

    check(
        $second['accepted'] === true &&
        $second['upload']['duplicate'] === true,
        'Duplicate torrent is detected'
    );

    check(
        $second['upload']['selected'] === false,
        'Duplicate torrent is not selected'
    );

    check(
        $manager->getTemporaryPath($secondId) !== null,
        'Duplicate retains its original temporary file'
    );

    $summary = $manager->getSummary($state);

    check(
        $summary['uploaded'] === 2 &&
        $summary['valid'] === 2 &&
        $summary['duplicate'] === 1 &&
        $summary['selected_count'] === 1,
        'Summary reports valid and duplicate uploads correctly'
    );

    // Remove original: duplicate should become unique.
    check(
        $manager->removeUpload($state, $firstId),
        'Original upload can be removed'
    );

    $uploads = $manager->getUploads($state);
    $remaining = $uploads[0] ?? null;

    check(
        is_array($remaining) &&
        $remaining['id'] === $secondId &&
        $remaining['duplicate'] === false,
        'Remaining duplicate is promoted to unique'
    );

    check(
        is_array($remaining) && $remaining['selected'] === true,
        'Promoted upload becomes selected'
    );

    check(
        $manager->getTemporaryPath($firstId) === null &&
        $manager->getTemporaryPath($secondId) !== null,
        'Removing an upload deletes only its own temporary file'
    );

    // Selection changes.
    check(
        $manager->setSelected($state, $secondId, false),
        'Unique upload can be deselected'
    );

    check(
        $manager->setSelected($state, $secondId, true),
        'Unique upload can be selected again'
    );

    check(
        count($manager->getSelectedUploads($state)) === 1,
        'Selected-upload accessor returns selected unique uploads'
    );

    // Invalid upload.
    $invalid = $manager->addUpload(
        $state,
        'broken.torrent',
        'not a valid torrent'
    );

    check(
        $invalid['accepted'] === true &&
        $invalid['upload']['status'] === 'invalid' &&
        is_string($invalid['upload']['error']),
        'Invalid torrent is recorded with an individual error'
    );

    check(
        $invalid['upload']['selected'] === false,
        'Invalid torrent is not selected'
    );

    check(
        $manager->getTemporaryPath($invalid['upload']['id']) === null,
        'Invalid torrent has no retained temporary file'
    );

    $summary = $manager->getSummary($state);

    check(
        $summary['uploaded'] === 2 &&
        $summary['valid'] === 1 &&
        $summary['invalid'] === 1,
        'Invalid upload does not discard other session entries'
    );

    // Invalid upload can be removed.
    check(
        $manager->removeUpload($state, $invalid['upload']['id']),
        'Invalid upload can be removed'
    );

    check(
        $manager->removeUpload($state, 'invalid-id') === false,
        'Malformed upload ID is rejected'
    );

    // Oversized individual upload must not consume quota.
    $oversized = $manager->addUpload(
        $state,
        'oversized.torrent',
        str_repeat('x', 5 * 1024 * 1024 + 1)
    );

    check(
        $oversized['accepted'] === false,
        'Upload larger than 5 MiB is rejected'
    );

    check(
        $manager->getSummary($state)['uploaded'] === 1,
        'Oversized upload does not create a session entry'
    );

    // Clear removes all entries and temporary files.
    $manager->clear($state);

    $summary = $manager->getSummary($state);

    check(
        $summary['uploaded'] === 0 &&
        $summary['uploaded_bytes'] === 0,
        'Clear resets session state'
    );

    check(
        $manager->getTemporaryPath($secondId) === null,
        'Clear deletes retained temporary torrent files'
    );

    // Aggregate 25 MiB limit. Five invalid 5 MiB uploads fill
    // the quota exactly; a further byte must be rejected.
    $largeInvalid = str_repeat('x', 5 * 1024 * 1024);

    for ($index = 1; $index <= 5; ++$index) {
        $result = $manager->addUpload(
            $state,
            "invalid-{$index}.torrent",
            $largeInvalid
        );

        check(
            $result['accepted'] === true &&
            $result['upload']['status'] === 'invalid',
            "Aggregate-limit test upload {$index} is accepted"
        );
    }

    $summary = $manager->getSummary($state);

    check(
        $summary['uploaded_bytes'] === 25 * 1024 * 1024,
        'Session can reach exactly 25 MiB'
    );

    $overQuota = $manager->addUpload(
        $state,
        'over-quota.torrent',
        'x'
    );

    check(
        $overQuota['accepted'] === false,
        'Upload exceeding aggregate 25 MiB limit is rejected'
    );

    check(
        $manager->getSummary($state)['uploaded'] === 5,
        'Aggregate-limit rejection does not alter session entries'
    );

    // Clear before the upload-count test.
    $manager->clear($state);

    // Upload-count limit. Repeated fixtures are duplicates, but
    // all accepted uploads still count towards the 150-upload cap.
    for ($index = 1; $index <= 150; ++$index) {
        $result = $manager->addUpload(
            $state,
            "copy-{$index}.torrent",
            $fixture
        );

        if (!$result['accepted']) {
            check(false, "Upload-count test accepts upload {$index}");
            break;
        }
    }

    check(
        $manager->getSummary($state)['uploaded'] === 150,
        'Session accepts 150 uploads'
    );

    $overCount = $manager->addUpload(
        $state,
        'copy-151.torrent',
        $fixture
    );

    check(
        $overCount['accepted'] === false,
        '151st upload is rejected'
    );

    check(
        $manager->getSummary($state)['uploaded'] === 150,
        'Upload-count rejection does not alter session entries'
    );

    $manager->clear($state);

} finally {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }

    rmdir($temporaryDirectory);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
