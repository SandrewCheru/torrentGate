<?php

declare(strict_types=1);

use TorrentGate\DestinationValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;

function checkValid(string $input, string $expected): void
{
    global $passed, $failed;

    try {
        $actual = DestinationValidator::validate($input);

        if ($actual !== $expected) {
            throw new RuntimeException(
                "Expected " . var_export($expected, true) .
                ', got ' . var_export($actual, true)
            );
        }

        $passed++;
        echo "PASS valid: " . var_export($input, true) . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL valid: " . var_export($input, true) .
            ' — ' . $exception->getMessage() . PHP_EOL;
    }
}

function checkInvalid(string $input): void
{
    global $passed, $failed;

    try {
        DestinationValidator::validate($input);

        $failed++;
        echo "FAIL invalid: " . var_export($input, true) .
            " — unexpectedly accepted" . PHP_EOL;
    } catch (InvalidArgumentException) {
        $passed++;
        echo "PASS invalid: " . var_export($input, true) . PHP_EOL;
    }
}

checkValid('', '');
checkValid('Music', 'Music');
checkValid('TV/Documentaries', 'TV/Documentaries');
checkValid('Music/Live Concerts', 'Music/Live Concerts');
checkValid('日本語/音楽', '日本語/音楽');
checkValid('A & B/Albums', 'A & B/Albums');

checkInvalid('/etc');
checkInvalid('../outside');
checkInvalid('Music/../../outside');
checkInvalid('Music/../outside');
checkInvalid('./Music');
checkInvalid('Music/.');
checkInvalid('Music/');
checkInvalid('/Music');
checkInvalid('Music//Albums');
checkInvalid('Music\\Albums');
checkInvalid('C:/Music');
checkInvalid("Music/\0Albums");
checkInvalid("Music/\nAlbums");
checkInvalid(str_repeat('a', 256));

checkInvalid(str_repeat('a', 1025));
checkInvalid(implode('/', array_fill(0, 101, 'x')));

$tooLongAbsolute = str_repeat('a', 255) . '/' .
    str_repeat('b', 255) . '/' .
    str_repeat('c', 255) . '/' .
    str_repeat('d', 255) . '/' .
    str_repeat('e', 255);

checkInvalid($tooLongAbsolute);

echo PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;

exit($failed === 0 ? 0 : 1);
