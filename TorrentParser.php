<?php

declare(strict_types=1);

namespace TorrentGate;

use InvalidArgumentException;
use Rhilip\Bencode\TorrentFile;
use Throwable;

final class TorrentParser
{
    private const MAX_TORRENT_BYTES = 5 * 1024 * 1024;
    private const MAX_FILES = 50000;

        /**
     * Count file entries in torrent metadata before Rhilip builds
     * its file list. For hybrid torrents, check each representation
     * independently because v1 may include padding files.
     */
    private static function enforceFileCountLimit(array $info): void
    {
        // v1 single-file torrent.
        if (array_key_exists('length', $info)) {
            self::checkFileCount(1);
        }

        // v1 multi-file torrent.
        if (array_key_exists('files', $info)) {
            if (!is_array($info['files']) || !array_is_list($info['files'])) {
                throw new InvalidArgumentException(
                    'Torrent file list is invalid.'
                );
            }

            self::checkFileCount(count($info['files']));
        }

        // v2 file tree, also present in hybrid torrents.
        if (array_key_exists('file tree', $info)) {
            $count = 0;
            self::countV2Files($info['file tree'], $count, 0);
            self::checkFileCount($count);
        }
    }

    private static function checkFileCount(int $count): void
    {
        if ($count > self::MAX_FILES) {
            throw new InvalidArgumentException(
                'The torrent exceeds the 50,000-file limit.'
            );
        }
    }

    /**
     * Count v2 file-tree leaves.
     *
     * An empty dictionary key represents a file leaf.
     * Directory entries contain further dictionaries.
     */
    private static function countV2Files(
        mixed $node,
        int &$count,
        int $depth
    ): void {
        if ($depth > 100) {
            throw new InvalidArgumentException(
                'Torrent file tree exceeds the permitted depth.'
            );
        }

        if (!is_array($node)) {
            throw new InvalidArgumentException(
                'Torrent contains an invalid v2 file tree.'
            );
        }

        if (array_key_exists('', $node)) {
            ++$count;
            self::checkFileCount($count);
            return;
        }

        foreach ($node as $child) {
            self::countV2Files($child, $count, $depth + 1);
        }
    }

    /**
     * Parse and validate an uploaded .torrent file.
     *
     * Hashes are calculated from the original encoded info dictionary,
     * never from Rhilip's re-encoded representation.
     */
    public static function parse(string $data): array
    {
        $inputLength = strlen($data);

        if ($inputLength === 0) {
            throw new InvalidArgumentException('The torrent file is empty.');
        }

        if ($inputLength > self::MAX_TORRENT_BYTES) {
            throw new InvalidArgumentException(
                'The torrent file exceeds the 5 MiB size limit.'
            );
        }

        try {
            // Validate the complete Bencode document and locate its
            // original encoded info dictionary.
            $scan = BencodeScanner::scan($data);

            $infoOffset = $scan['info_offset'];
            $infoLength = $scan['info_length'];

            if (
                !is_int($infoOffset) ||
                !is_int($infoLength) ||
                $infoOffset < 0 ||
                $infoLength <= 0 ||
                $infoOffset > $inputLength ||
                $infoLength > $inputLength - $infoOffset
            ) {
                throw new InvalidArgumentException(
                    'The torrent contains an invalid info dictionary range.'
                );
            }

            $infoBytes = substr($data, $infoOffset, $infoLength);

            if ($infoBytes === '') {
                throw new InvalidArgumentException(
                    'The torrent info dictionary is empty.'
                );
            }

            // Decode using Rhilip only after the bounded scanner succeeds.
            $torrent = TorrentFile::loadFromString($data);

            $info = $torrent->getInfoData();

                        if (!is_array($info)) {
                throw new InvalidArgumentException(
                    'The torrent info dictionary is invalid.'
                );
            }

            // Enforce file-count limits before path validation
            // and before Rhilip constructs its file list.
            self::enforceFileCountLimit($info);

            // Validate names and every v1/v2 path representation.
	    TorrentPathValidator::validate($info);

            $protocol = $torrent->getProtocol();

            $protocolName = match ($protocol) {
                TorrentFile::PROTOCOL_V1 => 'v1',
                TorrentFile::PROTOCOL_V2 => 'v2',
                TorrentFile::PROTOCOL_HYBRID => 'hybrid',
                default => throw new InvalidArgumentException(
                    'The torrent protocol is unsupported.'
                ),
            };

            // Obtain the parsed file list. This triggers Rhilip's
            // structural checks for the relevant torrent format.
            $files = $torrent->getFileList();

            if (!is_array($files) || !array_is_list($files)) {
                throw new InvalidArgumentException(
                    'The torrent file list is invalid.'
                );
            }

            $fileCount = count($files);

            if ($fileCount === 0) {
                throw new InvalidArgumentException(
                    'The torrent does not contain any files.'
                );
            }

            if ($fileCount > self::MAX_FILES) {
                throw new InvalidArgumentException(
                    'The torrent exceeds the 50,000-file limit.'
                );
            }

            // Validate the library's output and calculate the total
            // ourselves so integer overflow cannot silently occur.
            $totalSize = 0;
            $validatedFiles = [];

            foreach ($files as $index => $file) {
                if (
                    !is_array($file) ||
                    !isset($file['path']) ||
                    !is_string($file['path']) ||
                    $file['path'] === '' ||
                    !array_key_exists('size', $file) ||
                    !is_int($file['size']) ||
                    $file['size'] < 0
                ) {
                    throw new InvalidArgumentException(
                        "The parsed file entry {$index} is invalid."
                    );
                }

                if ($file['size'] > PHP_INT_MAX - $totalSize) {
                    throw new InvalidArgumentException(
                        "The torrent's total file size is too large."
                    );
                }

                $totalSize += $file['size'];

                $validatedFiles[] = [
                    'path' => $file['path'],
                    'size' => $file['size'],
                ];
            }

            if ($torrent->getFileCount() !== $fileCount) {
                throw new InvalidArgumentException(
                    'The torrent file count is inconsistent.'
                );
            }

            if ($torrent->getSize() !== $totalSize) {
                throw new InvalidArgumentException(
                    'The torrent total size is inconsistent.'
                );
            }

            // Calculate hashes directly from the original info bytes.
            $infoHashV1 = null;
            $infoHashV2 = null;

            if (
                $protocol === TorrentFile::PROTOCOL_V1 ||
                $protocol === TorrentFile::PROTOCOL_HYBRID
            ) {
                $infoHashV1 = hash('sha1', $infoBytes);
            }

            if (
                $protocol === TorrentFile::PROTOCOL_V2 ||
                $protocol === TorrentFile::PROTOCOL_HYBRID
            ) {
                $infoHashV2 = hash('sha256', $infoBytes);
            }

            return [
                'name' => $torrent->getName(),
                'protocol' => $protocolName,
                'info_hash_v1' => $infoHashV1,
                'info_hash_v2' => $infoHashV2,
                'file_count' => $fileCount,
                'total_size' => $totalSize,
                'files' => $validatedFiles,
                'input_size' => $inputLength,
            ];
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'The torrent is malformed or could not be parsed.',
                0,
                $exception
            );
        }
    }
}
