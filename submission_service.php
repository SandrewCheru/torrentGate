<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\SubmissionAttemptStore;
use TorrentGate\SubmissionService;
use TorrentGate\TransmissionRpcUncertainException;
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
    '/torrentgate-submission-' . bin2hex(random_bytes(6));

if (!mkdir($tempDir, 0700)) {
    throw new RuntimeException('Could not create test directory.');
}

$fixture = dirname(__DIR__) .
    '/vendor/rhilip/bencode/tests/asserts/v1-single.torrent';

$data = file_get_contents($fixture);

if ($data === false) {
    throw new RuntimeException('Could not read torrent fixture.');
}

function createUpload(
    UploadSessionManager $manager,
    string $data
): array {
    $state = [];
    $result = $manager->addUpload($state, 'fixture.torrent', $data);

    if (($result['accepted'] ?? false) !== true) {
        throw new RuntimeException('Could not create test upload.');
    }

    return $state;
}

try {
    $manager = new UploadSessionManager($tempDir);
    $store = new SubmissionAttemptStore($tempDir);

    // Successful submission.
    $state = createUpload($manager, $data);
    $calls = 0;

    $service = new SubmissionService(
        $manager,
        $store,
        static function (
            string $torrentData,
            string $destination
        ) use (&$calls, $data): array {
            ++$calls;

            return [
                'status' => 'added',
                'id' => 123,
                'hash_string' => 'examplehash',
                'name' => 'Example torrent',
            ];
        }
    );

    $sessionKey = bin2hex(random_bytes(32));

    $result = $service->submit($state, $sessionKey, '');

    check(
        $result['selected_count'] === 1,
        'Selected upload is included in submission'
    );

    check(
        ($result['results'][0]['status'] ?? null) === 'added',
        'Successful RPC is reported as added'
    );

    check(
        $calls === 1,
        'Successful submission makes exactly one RPC call'
    );

    // Repeating the request must return the saved result.
    $result = $service->submit($state, $sessionKey, '');

    check(
        ($result['results'][0]['status'] ?? null) === 'added',
        'Repeated request returns the saved result'
    );

    check(
        $calls === 1,
        'Repeated request does not call Transmission again'
    );

    // An unselected upload must not be submitted.
    $unselectedState = createUpload($manager, $data);
    $unselectedId = array_key_first($unselectedState['uploads']);

    $manager->setSelected($unselectedState, $unselectedId, false);

    $unselectedCalls = 0;

    $unselectedService = new SubmissionService(
        $manager,
        $store,
        static function () use (&$unselectedCalls): array {
            ++$unselectedCalls;
            return [];
        }
    );

    $result = $unselectedService->submit(
        $unselectedState,
        bin2hex(random_bytes(32)),
        ''
    );

    check(
        $result['selected_count'] === 0,
        'Unselected upload is excluded'
    );

    check(
        $unselectedCalls === 0,
        'Unselected upload makes no RPC call'
    );

    // An uncertain RPC outcome must never be retried.
    $uncertainState = createUpload($manager, $data);
    $uncertainCalls = 0;
    $uncertainSession = bin2hex(random_bytes(32));

    $uncertainService = new SubmissionService(
        $manager,
        $store,
        static function () use (&$uncertainCalls): array {
            ++$uncertainCalls;

            throw new TransmissionRpcUncertainException(
                'Simulated transport timeout.'
            );
        }
    );

    $result = $uncertainService->submit(
        $uncertainState,
        $uncertainSession,
        ''
    );

    check(
        ($result['results'][0]['status'] ?? null) === 'uncertain',
        'Uncertain RPC outcome is reported as uncertain'
    );

    $result = $uncertainService->submit(
        $uncertainState,
        $uncertainSession,
        ''
    );

    check(
        ($result['results'][0]['status'] ?? null) === 'uncertain',
        'Repeated uncertain submission returns saved status'
    );

    check(
        $uncertainCalls === 1,
        'Uncertain submission is never retried'
    );

    // A definite failure must also not be silently retried.
    $failedState = createUpload($manager, $data);
    $failedCalls = 0;
    $failedSession = bin2hex(random_bytes(32));

    $failedService = new SubmissionService(
        $manager,
        $store,
        static function () use (&$failedCalls): array {
            ++$failedCalls;

            throw new RuntimeException('Simulated definite failure.');
        }
    );

    $result = $failedService->submit(
        $failedState,
        $failedSession,
        ''
    );

    check(
        ($result['results'][0]['status'] ?? null) === 'failed',
        'Definite failure is reported as failed'
    );

    $result = $failedService->submit(
        $failedState,
        $failedSession,
        ''
    );

    check(
        ($result['results'][0]['status'] ?? null) === 'failed',
        'Repeated failed submission returns saved status'
    );

    check(
        $failedCalls === 1,
        'Failed submission is not automatically retried'
    );

    // Invalid destination must be rejected before any attempt or RPC.
    $invalidState = createUpload($manager, $data);
    $invalidCalls = 0;
    $invalidSession = bin2hex(random_bytes(32));

    $invalidService = new SubmissionService(
        $manager,
        $store,
        static function () use (&$invalidCalls): array {
            ++$invalidCalls;
            return [];
        }
    );

    $invalidRejected = false;

    try {
        $invalidService->submit(
            $invalidState,
            $invalidSession,
            '../outside'
        );
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }

    check(
        $invalidRejected,
        'Invalid destination is rejected'
    );

    check(
        $invalidCalls === 0,
        'Invalid destination makes no RPC call'
    );

} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    foreach (glob($tempDir . '/submission-attempts/.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    foreach (glob($tempDir . '/submission-attempts/*') ?: [] as $file) {
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
