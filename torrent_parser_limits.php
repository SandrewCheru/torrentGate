<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\TorrentParser;

$passed = 0;
$failed = 0;

$method = new ReflectionMethod(
    TorrentParser::class,
    'enforceFileCountLimit'
);

function expectAccepted(
    ReflectionMethod $method,
    array $info,
    string $description
): void {
    global $passed, $failed;

    try {
        $method->invoke(null, $info);
        ++$passed;
        echo "PASS: {$description}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: {$description}: {$exception->getMessage()}\n";
    }
}

function expectRejected(
    ReflectionMethod $method,
    array $info,
    string $description
): void {
    global $passed, $failed;

    try {
        $method->invoke(null, $info);
        ++$failed;
        echo "FAIL: {$description}: limit was not enforced.\n";
    } catch (InvalidArgumentException $exception) {
        ++$passed;
        echo "PASS: {$description}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: {$description}: unexpected exception: " .
            $exception->getMessage() . "\n";
    }
}

function makeV1Files(int $count): array
{
    return array_fill(
        0,
        $count,
        [
            'length' => 0,
            'path' => ['file'],
        ]
    );
}

function makeV2Tree(int $count): array
{
    $tree = [];

    for ($index = 0; $index < $count; ++$index) {
        $key = sprintf('file-%05d', $index);
        $tree[$key] = [
            '' => [
                'length' => 0,
            ],
        ];
    }

    return $tree;
}

// V1 boundary.
expectAccepted(
    $method,
    ['files' => makeV1Files(50000)],
    'v1 accepts exactly 50,000 files'
);

expectRejected(
    $method,
    ['files' => makeV1Files(50001)],
    'v1 rejects 50,001 files'
);

// V2 boundary.
expectAccepted(
    $method,
    ['file tree' => makeV2Tree(50000)],
    'v2 accepts exactly 50,000 files'
);

expectRejected(
    $method,
    ['file tree' => makeV2Tree(50001)],
    'v2 rejects 50,001 files'
);

// Hybrid counts are independent: padding files can make the
// v1 and v2 file counts differ. They must not be added together.
expectAccepted(
    $method,
    [
        'files' => makeV1Files(30000),
        'file tree' => makeV2Tree(30000),
    ],
    'hybrid accepts 30,000 v1 entries and 30,000 v2 leaves independently'
);

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
