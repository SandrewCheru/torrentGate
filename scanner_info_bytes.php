<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/BencodeScanner.php';

use TorrentGate\BencodeScanner;

$testsPassed = 0;
$testsFailed = 0;

function check(string $name, callable $test): void
{
    global $testsPassed, $testsFailed;

    try {
        $test();
        ++$testsPassed;
        echo "PASS: {$name}\n";
    } catch (Throwable $exception) {
        ++$testsFailed;
        echo "FAIL: {$name}: {$exception->getMessage()}\n";
    }
}

// A valid info dictionary containing binary data.
$info = 'd4:name1:x12:piece lengthi1e6:pieces4:' .
    "\x00\xffab" .
    'e';

// Root keys are sorted: announce, info.
$torrent = 'd8:announce1:a4:info' . $info . 'e';

check('exact original info bytes are extracted', function () use ($torrent, $info): void {
    $result = BencodeScanner::scan($torrent);

    $extracted = substr(
        $torrent,
        $result['info_offset'],
        $result['info_length']
    );

    if ($extracted !== $info) {
        throw new RuntimeException('Extracted info bytes do not match the original.');
    }
});

check('original info bytes produce the expected SHA-1 hash', function () use ($torrent): void {
    $result = BencodeScanner::scan($torrent);

    $extracted = substr(
        $torrent,
        $result['info_offset'],
        $result['info_length']
    );

    $actualHash = hash('sha1', $extracted);
    $expectedHash = '2ad5c173e3c57d334d7a23a77e3e809daade32a8';

    if (!hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException("Unexpected SHA-1 hash: {$actualHash}");
    }
});

check('reported input length matches the original torrent', function () use ($torrent): void {
    $result = BencodeScanner::scan($torrent);

    if ($result['input_length'] !== strlen($torrent)) {
        throw new RuntimeException('Reported input length is incorrect.');
    }
});

echo "\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";

exit($testsFailed === 0 ? 0 : 1);
