<?php

declare(strict_types=1);

namespace TorrentGate;

use RuntimeException;

final class SubmissionAttemptStore
{
    private const STATUSES = [
        'uncertain',
        'added',
        'duplicate',
        'failed',
    ];

    private readonly string $directory;

    public function __construct(string $temporaryDirectory)
    {
        $parent = realpath($temporaryDirectory);

        if (
            $parent === false ||
            !is_dir($parent) ||
            !is_writable($parent)
        ) {
            throw new RuntimeException(
                'The submission-attempt storage directory is unavailable.'
            );
        }

        $directory = $parent . '/submission-attempts';

        if (!is_dir($directory) && !mkdir($directory, 0700)) {
            throw new RuntimeException(
                'Could not create submission-attempt storage.'
            );
        }

        $resolved = realpath($directory);

        if (
            $resolved === false ||
            !is_dir($resolved) ||
            !is_writable($resolved)
        ) {
            throw new RuntimeException(
                'Submission-attempt storage is unavailable.'
            );
        }

        if (!chmod($resolved, 0700)) {
            throw new RuntimeException(
                'Could not secure submission-attempt storage.'
            );
        }

        $this->directory = $resolved;
    }

    /**
     * Create a durable record before making an RPC request.
     *
     * The initial status is deliberately "uncertain". If PHP terminates
     * after this point, the upload cannot be submitted again automatically.
     *
     * Returns false if an attempt record already exists.
     */
    public function begin(string $sessionId, string $uploadId): bool
    {
        $path = $this->recordPath($sessionId, $uploadId);

        $handle = @fopen($path, 'x+b');

        if ($handle === false) {
            if (file_exists($path) || is_link($path)) {
                return false;
            }

            throw new RuntimeException(
                'Could not create the submission-attempt record.'
            );
        }

        $record = [
            'version' => 1,
            'status' => 'uncertain',
            'created_at' => time(),
            'updated_at' => time(),
            'result' => null,
        ];

        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException(
                    'Could not secure the submission-attempt record.'
                );
            }

            $this->writeHandle($handle, $record);

            return true;
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Replace the initial uncertain record with a confirmed result.
     *
     * Allowed statuses: added, duplicate, failed, uncertain.
     */
    public function complete(
        string $sessionId,
        string $uploadId,
        string $status,
        ?array $result = null
    ): void {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException(
                'Invalid submission-attempt status.'
            );
        }

        $path = $this->recordPath($sessionId, $uploadId);

        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException(
                'The submission-attempt record does not exist.'
            );
        }

        $existing = $this->get($sessionId, $uploadId);

        if ($existing === null) {
            throw new RuntimeException(
                'The submission-attempt record is invalid.'
            );
        }

        $record = [
            'version' => 1,
            'status' => $status,
            'created_at' => $existing['created_at'],
            'updated_at' => time(),
            'result' => $result,
        ];

        $temporaryPath = $this->directory . '/.' .
            basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $handle = @fopen($temporaryPath, 'x+b');

        if ($handle === false) {
            throw new RuntimeException(
                'Could not create a temporary attempt record.'
            );
        }

        try {
            if (!chmod($temporaryPath, 0600)) {
                throw new RuntimeException(
                    'Could not secure the temporary attempt record.'
                );
            }

            $this->writeHandle($handle, $record);
            fclose($handle);
            $handle = null;

            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException(
                    'Could not update the submission-attempt record.'
                );
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Retrieve a submission-attempt record.
     */
    public function get(
        string $sessionId,
        string $uploadId
    ): ?array {
        $path = $this->recordPath($sessionId, $uploadId);

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'Could not read the submission-attempt record.'
            );
        }

        try {
            $record = json_decode(
                $contents,
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new RuntimeException(
                'The submission-attempt record is corrupt.'
            );
        }

        if (
            !is_array($record) ||
            ($record['version'] ?? null) !== 1 ||
            !in_array($record['status'] ?? null, self::STATUSES, true) ||
            !is_int($record['created_at'] ?? null) ||
            !is_int($record['updated_at'] ?? null) ||
            !array_key_exists('result', $record)
        ) {
            throw new RuntimeException(
                'The submission-attempt record is invalid.'
            );
        }

        return $record;
    }

    private function recordPath(
        string $sessionId,
        string $uploadId
    ): string {
        if (
            $sessionId === '' ||
            strlen($sessionId) > 128 ||
            preg_match('/\A[A-Za-z0-9,-]+\z/D', $sessionId) !== 1
        ) {
            throw new RuntimeException('Invalid session identifier.');
        }

        if (
            preg_match('/\A[a-f0-9]{32}\z/D', $uploadId) !== 1
        ) {
            throw new RuntimeException('Invalid upload identifier.');
        }

        $sessionKey = hash('sha256', $sessionId);

        return $this->directory . '/' .
            $sessionKey . '-' . $uploadId . '.json';
    }

    private function writeHandle($handle, array $record): void
    {
        $json = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        $length = strlen($json);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($handle, substr($json, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException(
                    'Could not write the submission-attempt record.'
                );
            }

            $written += $result;
        }

        if (!fflush($handle)) {
            throw new RuntimeException(
                'Could not flush the submission-attempt record.'
            );
        }

        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException(
                'Could not synchronise the submission-attempt record.'
            );
        }
    }
}
