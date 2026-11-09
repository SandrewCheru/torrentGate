<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/TorrentPathValidator.php';

use Rhilip\Bencode\TorrentFile;
use TorrentGate\TorrentPathValidator;

$passed = 0;
$failed = 0;

function checkValid(string $label, array $info): void
{
    global $passed, $failed;

    try {
        TorrentPathValidator::validate($info);
        ++$passed;
        echo "PASS: {$label}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: {$label}: {$exception->getMessage()}\n";
    }
}

function checkInvalid(string $label, array $info): void
{
    global $passed, $failed;

    try {
        TorrentPathValidator::validate($info);
        ++$failed;
        echo "FAIL: {$label}: invalid path was accepted\n";
    } catch (InvalidArgumentException) {
        ++$passed;
        echo "PASS: {$label}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: {$label}: unexpected exception: {$exception->getMessage()}\n";
    }
}

$base = [
    'name' => 'Example',
    'piece length' => 16384,
];

// Valid paths.
checkValid('ordinary torrent name', $base);

checkValid('Unicode torrent name', [
    ...$base,
    'name' => '音乐',
]);

checkValid('ordinary v1 multi-file path', [
    ...$base,
    'files' => [
        [
            'length' => 100,
            'path' => ['Music', 'Artist', 'Track.mp3'],
        ],
    ],
]);

checkValid('UTF-8 v1 path', [
    ...$base,
    'files' => [
        [
            'length' => 100,
            'path.utf-8' => ['音乐', '歌曲.mp3'],
        ],
    ],
]);

checkValid('ordinary v2 file tree', [
    ...$base,
    'file tree' => [
        'Music' => [
            'Track.mp3' => [
                '' => [
                    'length' => 100,
                ],
            ],
        ],
    ],
]);

// Invalid torrent names.
foreach ([
    '../escape',
    '..',
    '.',
    '/absolute',
    'folder/file',
    'folder\\file',
    "bad\0name",
    "bad\nname",
    'C:drive',
] as $name) {
    checkInvalid("unsafe torrent name " . bin2hex($name), [
        ...$base,
        'name' => $name,
    ]);
}

// Invalid v1 paths.
foreach ([
    ['..', 'escape.txt'],
    ['.', 'file.txt'],
    ['folder/escape.txt'],
    ['folder\\escape.txt'],
    [''],
    ["bad\0name"],
    ["bad\nname"],
] as $path) {
    checkInvalid('unsafe v1 path ' . json_encode($path), [
        ...$base,
        'files' => [
            [
                'length' => 100,
                'path' => $path,
            ],
        ],
    ]);
}

// An unsafe alternative path must not be ignored.
checkInvalid('unsafe legacy path alongside safe UTF-8 path', [
    ...$base,
    'files' => [
        [
            'length' => 100,
            'path' => ['..', 'escape.txt'],
            'path.utf-8' => ['Music', 'Track.mp3'],
        ],
    ],
]);

// Invalid v2 paths.
checkInvalid('v2 traversal component', [
    ...$base,
    'file tree' => [
        '..' => [
            'escape.txt' => [
                '' => ['length' => 100],
            ],
        ],
    ],
]);

checkInvalid('v2 embedded separator', [
    ...$base,
    'file tree' => [
        'folder/escape.txt' => [
            '' => ['length' => 100],
        ],
    ],
]);

checkInvalid('v2 empty directory', [
    ...$base,
    'file tree' => [
        'folder' => [],
    ],
]);

checkInvalid('v2 leaf mixed with children', [
    ...$base,
    'file tree' => [
        'folder' => [
            '' => ['length' => 100],
            'child.txt' => [
                '' => ['length' => 100],
            ],
        ],
    ],
]);

// Component length limit.
checkInvalid('path component exceeds 255 bytes', [
    ...$base,
    'files' => [
        [
            'length' => 100,
            'path' => [str_repeat('a', 256)],
        ],
    ],
]);

// Validate all existing package fixtures too.
$fixtureDirectory = dirname(__DIR__) .
    '/vendor/rhilip/bencode/tests/asserts';

$fixtures = [
    'v1-single.torrent',
    'v1-multi.torrent',
    'v2-single.torrent',
    'v2-multi.torrent',
    'hybrid-single.torrent',
    'hybrid-multi.torrent',
];

foreach ($fixtures as $filename) {
    try {
        $data = file_get_contents($fixtureDirectory . '/' . $filename);

        if ($data === false) {
            throw new RuntimeException('Could not read fixture.');
        }

        $torrent = TorrentFile::loadFromString($data);

        TorrentPathValidator::validate($torrent->getInfoData());

        ++$passed;
        echo "PASS: existing fixture {$filename}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: existing fixture {$filename}: {$exception->getMessage()}\n";
    }
}

echo "\nPassed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
