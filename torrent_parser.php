<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rhilip\Bencode\TorrentFile;
use TorrentGate\TorrentParser;

$fixtureDirectory = dirname(__DIR__) .
    '/vendor/rhilip/bencode/tests/asserts';

$fixtures = [
    'v1-single.torrent' => 'v1',
    'v1-multi.torrent' => 'v1',
    'v2-single.torrent' => 'v2',
    'v2-multi.torrent' => 'v2',
    'hybrid-single.torrent' => 'hybrid',
    'hybrid-multi.torrent' => 'hybrid',
];

$passed = 0;
$failed = 0;

function pass(string $message): void
{
    global $passed;
    ++$passed;
    echo "PASS: {$message}\n";
}

function fail(string $message): void
{
    global $failed;
    ++$failed;
    echo "FAIL: {$message}\n";
}

foreach ($fixtures as $filename => $expectedProtocol) {
    $path = $fixtureDirectory . '/' . $filename;
    $data = file_get_contents($path);

    if ($data === false) {
        fail("Could not read {$filename}");
        continue;
    }

    try {
        $result = TorrentParser::parse($data);

        if ($result['protocol'] !== $expectedProtocol) {
            throw new RuntimeException('Unexpected protocol.');
        }

        if ($result['name'] === '') {
            throw new RuntimeException('Torrent name is empty.');
        }

        if ($result['file_count'] < 1) {
            throw new RuntimeException('File count is empty.');
        }

        if ($result['file_count'] !== count($result['files'])) {
            throw new RuntimeException('File count does not match file list.');
        }

        if (
            $result['total_size'] !==
            array_sum(array_column($result['files'], 'size'))
        ) {
            throw new RuntimeException('Total size does not match file list.');
        }

        if ($result['input_size'] !== strlen($data)) {
            throw new RuntimeException('Input size is incorrect.');
        }

        if (
            $expectedProtocol === 'v1' &&
            (
                !is_string($result['info_hash_v1']) ||
                strlen($result['info_hash_v1']) !== 40 ||
                $result['info_hash_v2'] !== null
            )
        ) {
            throw new RuntimeException('V1 hash fields are incorrect.');
        }

        if (
            $expectedProtocol === 'v2' &&
            (
                !is_string($result['info_hash_v2']) ||
                strlen($result['info_hash_v2']) !== 64 ||
                $result['info_hash_v1'] !== null
            )
        ) {
            throw new RuntimeException('V2 hash fields are incorrect.');
        }

        if (
            $expectedProtocol === 'hybrid' &&
            (
                !is_string($result['info_hash_v1']) ||
                strlen($result['info_hash_v1']) !== 40 ||
                !is_string($result['info_hash_v2']) ||
                strlen($result['info_hash_v2']) !== 64
            )
        ) {
            throw new RuntimeException('Hybrid hash fields are incorrect.');
        }

        pass("{$filename}: protocol, metadata, file list and hashes");
    } catch (Throwable $exception) {
        fail("{$filename}: {$exception->getMessage()}");
    }
}

// Reject empty input.
try {
    TorrentParser::parse('');
    fail('Empty input was accepted.');
} catch (InvalidArgumentException) {
    pass('Empty input is rejected.');
}

// Reject input exceeding the upload size limit.
try {
    TorrentParser::parse(str_repeat('x', 5 * 1024 * 1024 + 1));
    fail('Oversized input was accepted.');
} catch (InvalidArgumentException) {
    pass('Input exceeding 5 MiB is rejected.');
}

// Reject malformed Bencode.
try {
    TorrentParser::parse('not a torrent');
    fail('Malformed input was accepted.');
} catch (InvalidArgumentException) {
    pass('Malformed Bencode is rejected.');
}

// Verify the parser returns no unexpected fields.
$data = file_get_contents(
    $fixtureDirectory . '/v1-single.torrent'
);

try {
    $result = TorrentParser::parse($data);

    $expectedKeys = [
        'name',
        'protocol',
        'info_hash_v1',
        'info_hash_v2',
        'file_count',
        'total_size',
        'files',
        'input_size',
    ];

    if (array_keys($result) !== $expectedKeys) {
        throw new RuntimeException('Unexpected result structure.');
    }

    pass('Result structure contains exactly the expected fields.');
} catch (Throwable $exception) {
    fail('Result structure: ' . $exception->getMessage());
}

echo "\nPassed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
