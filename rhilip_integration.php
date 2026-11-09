<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/BencodeScanner.php';

use Rhilip\Bencode\TorrentFile;
use TorrentGate\BencodeScanner;

$fixtures = [
    'v1-single.torrent'    => TorrentFile::PROTOCOL_V1,
    'v1-multi.torrent'     => TorrentFile::PROTOCOL_V1,
    'v2-single.torrent'    => TorrentFile::PROTOCOL_V2,
    'v2-multi.torrent'     => TorrentFile::PROTOCOL_V2,
    'hybrid-single.torrent'=> TorrentFile::PROTOCOL_HYBRID,
    'hybrid-multi.torrent' => TorrentFile::PROTOCOL_HYBRID,
];

$fixtureDirectory = dirname(__DIR__) .
    '/vendor/rhilip/bencode/tests/asserts';

$passed = 0;
$failed = 0;

foreach ($fixtures as $filename => $expectedProtocol) {
    try {
        $path = $fixtureDirectory . '/' . $filename;
        $data = file_get_contents($path);

        if ($data === false) {
            throw new RuntimeException('Could not read fixture.');
        }

        $scan = BencodeScanner::scan($data);

        $infoBytes = substr(
            $data,
            $scan['info_offset'],
            $scan['info_length']
        );

        $torrent = TorrentFile::loadFromString($data);

        if ($torrent->getProtocol() !== $expectedProtocol) {
            throw new RuntimeException('Unexpected torrent protocol.');
        }

        $files = $torrent->getFileList();

        if ($files === []) {
            throw new RuntimeException('File list is empty.');
        }

        if ($torrent->getFileCount() !== count($files)) {
            throw new RuntimeException('File count does not match file list.');
        }

        if ($torrent->getSize() !== array_sum(array_column($files, 'size'))) {
            throw new RuntimeException('Total size does not match file list.');
        }

        if ($expectedProtocol !== TorrentFile::PROTOCOL_V2) {
            $originalHash = hash('sha1', $infoBytes);

            if (!hash_equals($originalHash, $torrent->getInfoHashV1())) {
                throw new RuntimeException('V1 hash mismatch.');
            }
        }

        if ($expectedProtocol !== TorrentFile::PROTOCOL_V1) {
            $originalHash = hash('sha256', $infoBytes);

            if (!hash_equals($originalHash, $torrent->getInfoHashV2())) {
                throw new RuntimeException('V2 hash mismatch.');
            }
        }

        ++$passed;
        echo "PASS: {$filename}\n";
    } catch (Throwable $exception) {
        ++$failed;
        echo "FAIL: {$filename}: {$exception->getMessage()}\n";
    }
}

echo "\nPassed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
