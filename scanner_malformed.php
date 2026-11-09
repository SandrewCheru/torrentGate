<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/BencodeScanner.php';

use TorrentGate\BencodeScanner;

$testsPassed = 0;
$testsFailed = 0;

function checkRejected(string $name, string $input, string $expectedMessage): void
{
    global $testsPassed, $testsFailed;

    try {
        BencodeScanner::scan($input);
        throw new RuntimeException('Invalid input was accepted.');
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), $expectedMessage)) {
            ++$testsFailed;
            echo "FAIL: {$name}: unexpected error: {$exception->getMessage()}\n";
            return;
        }

        ++$testsPassed;
        echo "PASS: {$name}\n";
    } catch (Throwable $exception) {
        ++$testsFailed;
        echo "FAIL: {$name}: {$exception->getMessage()}\n";
    }
}

$validInfo = 'd4:name1:x12:piece lengthi1e6:pieces0:e';

checkRejected('empty input', '', 'empty');
checkRejected('root is not a dictionary', 'l1:xe', 'root must be');
checkRejected('missing info dictionary', 'd4:name1:xe', 'does not contain');
checkRejected('info is not a dictionary', 'd4:info1:xe', 'must be a dictionary');

checkRejected(
    'duplicate top-level key',
    'd4:info' . $validInfo . '4:info' . $validInfo . 'e',
    'duplicate'
);

checkRejected(
    'duplicate key inside info',
    'd4:infod4:name1:x4:name1:ye e',
    'duplicate'
);

checkRejected(
    'unsorted top-level keys',
    'd4:info' . $validInfo . '8:announce1:ae',
    'canonical order'
);

checkRejected(
    'invalid integer',
    'd4:infod1:ai03eee',
    'integer'
);

checkRejected(
    'truncated byte string',
    'd4:infod1:a3:xy',
    'extends beyond'
);

checkRejected(
    'truncated integer',
    'd4:infod1:ai1',
    'Unterminated Bencode integer'
);

checkRejected(
    'truncated list',
    'd4:infod1:al1:x',
    'Unterminated Bencode list'
);

checkRejected(
    'truncated nested dictionary',
    'd4:infod1:ad1:x1:y',
    'Unterminated Bencode dictionary'
);

checkRejected(
    'truncated root dictionary',
    'd4:info' . $validInfo,
    'Unterminated Bencode dictionary'
);

checkRejected(
    'trailing data',
    'd4:info' . $validInfo . 'eX',
    'trailing data'
);

echo "\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";

exit($testsFailed === 0 ? 0 : 1);
