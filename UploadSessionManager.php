<?php

declare(strict_types=1);

namespace TorrentGate;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TorrentGate\SubmissionService;

final class UploadSessionManager
{
    public const MAX_UPLOADS = 150;
    public const MAX_TORRENT_BYTES = 5 * 1024 * 1024;
    private const MAX_SESSION_BYTES = 25 * 1024 * 1024;
    private const SESSION_VERSION = 1;

    private readonly string $temporaryDirectory;

    public function __construct(string $temporaryDirectory)
    {
        $directory = realpath($temporaryDirectory);

        if (
            $directory === false ||
            !is_dir($directory) ||
            !is_writable($directory)
        ) {
            throw new RuntimeException(
                'The TorrentGate temporary directory is unavailable.'
            );
        }

        $this->temporaryDirectory = $directory;
    }

    /**
     * Add one uploaded torrent to the session.
     *
     * Invalid torrents are recorded individually. A session-limit
     * rejection is returned without changing the session.
     */
    public function addUpload(
        array &$state,
        string $originalName,
        string $data
    ): array {
        $this->initialise($state);

        $size = strlen($data);

        if ($size > self::MAX_TORRENT_BYTES) {
            return [
                'accepted' => false,
                'error' => 'The torrent file exceeds the 5 MiB size limit.',
            ];
        }

        if (count($state['uploads']) >= self::MAX_UPLOADS) {
            return [
                'accepted' => false,
                'error' => 'The session has reached the 150-upload limit.',
            ];
        }

        if ($size > self::MAX_SESSION_BYTES - $state['uploaded_bytes']) {
            return [
                'accepted' => false,
                'error' => 'The session has reached the 25 MiB upload limit.',
            ];
        }

        $id = bin2hex(random_bytes(16));

        $upload = [
            'id' => $id,
            'original_name' => $this->displayName($originalName),
            'input_size' => $size,
            'status' => 'invalid',
            'error' => null,
            'metadata' => null,
            'selected' => true,
            'duplicate' => false,
            'created_at' => time(),
        ];

        $metadata = null;

        try {
            $metadata = TorrentParser::parse($data);
        } catch (InvalidArgumentException $exception) {
            $upload['error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            // Do not expose unexpected internal details to the browser.
            $upload['error'] = 'The torrent could not be processed.';
        }

        if ($metadata !== null) {
            // Keep the original bytes for every valid upload, including
            // duplicates. A duplicate may become unique if another
            // upload is subsequently removed.
            // Storage failures abort the operation; they must never
            // leave a valid session entry without its torrent bytes.
            $this->writeTemporaryFile($id, $data);

            $upload['status'] = 'valid';
            $upload['metadata'] = $metadata;
        }

        $state['uploads'][$id] = $upload;
        $state['uploaded_bytes'] += $size;
        $state['last_activity'] = time();

        $this->recalculateDuplicates($state);

        return [
            'accepted' => true,
            'upload' => $state['uploads'][$id],
        ];
    }

    /**
     * Remove an upload and its temporary file.
     */
    public function removeUpload(array &$state, string $id): bool
    {
        $this->initialise($state);

        if (!$this->validId($id) || !isset($state['uploads'][$id])) {
            return false;
        }

        $upload = $state['uploads'][$id];

        $this->deleteTemporaryFile($id);

        $state['uploaded_bytes'] -= $upload['input_size'];
        unset($state['uploads'][$id]);

        $state['last_activity'] = time();

        $this->recalculateDuplicates($state);

        return true;
    }

    /**
     * Remove all uploads and their temporary files.
     */
    public function clear(array &$state): void
    {
        $this->initialise($state);

        foreach (array_keys($state['uploads']) as $id) {
            $this->deleteTemporaryFile($id);
        }

        $state = $this->newState();
    }

    /**
     * Change selection state.
     *
     * Invalid and duplicate uploads cannot be selected.
     */
    public function setSelected(
        array &$state,
        string $id,
        bool $selected
    ): bool {
        $this->initialise($state);

        if (
            !$this->validId($id) ||
            !isset($state['uploads'][$id])
        ) {
            return false;
        }

        $upload = &$state['uploads'][$id];

        if (
            $upload['status'] !== 'valid' ||
            $upload['duplicate']
        ) {
            return false;
        }

        $upload['selected'] = $selected;
        $state['last_activity'] = time();

        return true;
    }

    /**
     * Return upload records without exposing temporary filesystem paths.
     */
    public function getUploads(array &$state): array
    {
        $this->initialise($state);

        $state['last_activity'] = time();

        return array_values($state['uploads']);
    }

    /**
     * Return counts and sizes for the current upload session.
     *
     * "valid" includes duplicates. "duplicate" is a subset of valid.
     * unique_valid_size excludes duplicates.
     */
    public function getSummary(array &$state): array
    {
        $this->initialise($state);

        $valid = 0;
        $invalid = 0;
        $duplicates = 0;
        $uniqueValidSize = 0;
        $selectedCount = 0;
        $selectedSize = 0;

        foreach ($state['uploads'] as $upload) {
            if ($upload['status'] === 'invalid') {
                ++$invalid;
                continue;
            }

            ++$valid;

            if ($upload['duplicate']) {
                ++$duplicates;
                continue;
            }

            $uniqueValidSize += $upload['metadata']['total_size'];

            if ($upload['selected']) {
                ++$selectedCount;
                $selectedSize += $upload['metadata']['total_size'];
            }
        }

        return [
            'uploaded' => count($state['uploads']),
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicate' => $duplicates,
            'unique_valid_size' => $uniqueValidSize,
            'selected_count' => $selectedCount,
            'selected_size' => $selectedSize,
            'uploaded_bytes' => $state['uploaded_bytes'],
            'last_activity' => $state['last_activity'],
        ];
    }

    /**
     * Resolve the temporary path for a server-generated upload ID.
     *
     * Intended for the trusted backend submission component only.
     */
    public function getTemporaryPath(string $id): ?string
    {
        if (!$this->validId($id)) {
            return null;
        }

        $path = $this->temporaryDirectory . '/' . $id . '.torrent';

        return is_file($path) ? $path : null;
    }

    /**
     * Return selected, unique, valid uploads in their original order.
     */
    public function getSelectedUploads(array &$state): array
    {
        $this->initialise($state);

        $selected = [];

        foreach ($state['uploads'] as $upload) {
            if (
                $upload['status'] === 'valid' &&
                !$upload['duplicate'] &&
                $upload['selected']
            ) {
                $selected[] = $upload;
            }
        }

        $state['last_activity'] = time();

        return $selected;
    }

    private function initialise(array &$state): void
    {
        if ($state === []) {
            $state = $this->newState();
            return;
        }

        if (
            ($state['version'] ?? null) !== self::SESSION_VERSION ||
            !isset($state['uploads']) ||
            !is_array($state['uploads']) ||
            !isset($state['uploaded_bytes']) ||
            !is_int($state['uploaded_bytes']) ||
            $state['uploaded_bytes'] < 0 ||
            !isset($state['last_activity']) ||
            !is_int($state['last_activity'])
        ) {
            throw new RuntimeException(
                'The upload session state is invalid.'
            );
        }
    }

    private function newState(): array
    {
        $now = time();

        return [
            'version' => self::SESSION_VERSION,
            'created_at' => $now,
            'last_activity' => $now,
            'uploaded_bytes' => 0,
            'uploads' => [],
        ];
    }

    private function validId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
    }

    private function displayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        if ($name === '') {
            $name = 'unnamed.torrent';
        }

        return substr($name, 0, 255);
    }

    private function writeTemporaryFile(string $id, string $data): void
    {
        $path = $this->temporaryDirectory . '/' . $id . '.torrent';
        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            throw new RuntimeException(
                'Could not create the temporary torrent file.'
            );
        }

        $success = false;

        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException(
                    'Could not secure the temporary torrent file.'
                );
            }

            $length = strlen($data);
            $written = 0;

            while ($written < $length) {
                $result = fwrite($handle, substr($data, $written));

                if ($result === false || $result === 0) {
                    throw new RuntimeException(
                        'Could not write the temporary torrent file.'
                    );
                }

                $written += $result;
            }

            if (!fflush($handle)) {
                throw new RuntimeException(
                    'Could not flush the temporary torrent file.'
                );
            }

            $success = true;
        } finally {
            fclose($handle);

            if (!$success) {
                @unlink($path);
            }
        }
    }

    private function deleteTemporaryFile(string $id): void
    {
        if (!$this->validId($id)) {
            return;
        }

        $path = $this->temporaryDirectory . '/' . $id . '.torrent';

        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * Recalculate duplicates in upload order.
     *
     * A duplicate becomes selectable again if the earlier copy is
     * removed. Hashes are compared only within the same algorithm.
     */
    private function recalculateDuplicates(array &$state): void
    {
        $seen = [];

        foreach ($state['uploads'] as &$upload) {
            if ($upload['status'] !== 'valid') {
                $upload['duplicate'] = false;
                $upload['selected'] = false;
                continue;
            }

            $metadata = $upload['metadata'];
            $hashes = [];

            if (is_string($metadata['info_hash_v1'] ?? null)) {
                $hashes[] = 'v1:' . $metadata['info_hash_v1'];
            }

            if (is_string($metadata['info_hash_v2'] ?? null)) {
                $hashes[] = 'v2:' . $metadata['info_hash_v2'];
            }

            $duplicate = false;

            foreach ($hashes as $hash) {
                if (isset($seen[$hash])) {
                    $duplicate = true;
                    break;
                }
            }

            $wasDuplicate = $upload['duplicate'];

            $upload['duplicate'] = $duplicate;

            if ($duplicate) {
                $upload['selected'] = false;
            } elseif ($wasDuplicate) {
                // A formerly duplicated upload has just become unique.
                $upload['selected'] = true;
            } elseif (!isset($upload['selected'])) {
                $upload['selected'] = true;
            }

            foreach ($hashes as $hash) {
                $seen[$hash] = true;
            }
        }

        unset($upload);
    }
}
