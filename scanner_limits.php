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

function expectRejected(string $input, string $expectedMessage): void
{
    try {
        BencodeScanner::scan($input);
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), $expectedMessage)) {
            throw new RuntimeException(
                'Unexpected rejection: ' . $exception->getMessage()
            );
        }

        return;
    }

    throw new RuntimeException('Invalid input was accepted.');
}

// Minimal info dictionary.
$validInfo = 'd4:name1:x12:piece lengthi1e6:pieces0:';

// Depth test.
// Keys are correctly ordered: extra, name, piece length, pieces.
$deepInfo = 'd5:extra' .
    str_repeat('l', 101) . '0:' . str_repeat('e', 101) .
    '4:name1:x12:piece lengthi1e6:pieces0:e';

$deepTorrent = 'd4:info' . $deepInfo . 'e';

check('depth limit is enforced', function () use ($deepTorrent): void {
    expectRejected($deepTorrent, 'nesting depth');
});

// Node test.
// The root keys are correctly ordered: a, info.
// More than one million values are included in the list.
$padding = 'd1:al' . str_repeat('0:', 1000001) . 'e';
$largeTorrent = $padding . '4:info' . $validInfo . 'e';

check('node limit is enforced', function () use ($largeTorrent): void {
    expectRejected($largeTorrent, 'too many');
});

echo "\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";

exit($testsFailed === 0 ? 0 : 1);
