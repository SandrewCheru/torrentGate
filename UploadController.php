<?php

declare(strict_types=1);

namespace TorrentGate\Http;

use TorrentGate\UploadSessionManager;
use TorrentGate\DestinationValidator;
use Throwable;

final class UploadController
{
    private const SESSION_STATE_KEY = 'torrentgate_upload_state';
    private const CSRF_KEY = 'torrentgate_csrf';
    private const DESTINATION_KEY = 'torrentgate_destination';

    public function __construct(
        private readonly UploadSessionManager $manager
    ) {
    }

    /**
     * Dispatch one API request.
     *
     * The optional upload-verification callback exists to make the
     * controller testable without an actual HTTP upload.
     */
    public function handle(
        string $method,
        string $action,
        array &$session,
        array $post = [],
        array $files = [],
        ?string $csrfHeader = null,
        ?callable $isUploadedFile = null
    ): array {
        try {
            $this->ensureCsrfToken($session);


	    if ($method === 'GET') {
                return match ($action) {
                    'state' => $this->stateResponse($session),
                    'preview' => $this->previewResponse($session),
                     default => $this->response(404, [
                         'ok' => false,
                         'error' => 'Unknown API action.',
                     ]),
                };
            }

            if ($method !== 'POST') {
                return $this->response(405, [
                    'ok' => false,
                    'error' => 'Method not allowed.',
                ]);
            }

            $expectedToken = $session[self::CSRF_KEY];

            if (
                $csrfHeader === null ||
                !hash_equals($expectedToken, $csrfHeader)
            ) {
                return $this->response(403, [
                    'ok' => false,
                    'error' => 'Invalid or missing CSRF token.',
                ]);
            }

            return match ($action) {
                'upload' => $this->uploadResponse(
                    $session,
                    $files,
                    $isUploadedFile
                ),
                'remove' => $this->removeResponse($session, $post),
                'select' => $this->selectResponse($session, $post),
                'clear' => $this->clearResponse($session),
                'destination' => $this->destinationResponse($session, $post),
                default => $this->response(404, [
                    'ok' => false,
                    'error' => 'Unknown API action.',
                ]),
            };
        } catch (Throwable $exception) {
            error_log(
                'TorrentGate HTTP controller error: ' .
                $exception::class . ': ' . $exception->getMessage()
            );

            return $this->response(500, [
                'ok' => false,
                'error' => 'The request could not be completed.',
            ]);
        }
    }

    private function ensureCsrfToken(array &$session): void
    {
        if (
            !isset($session[self::CSRF_KEY]) ||
            !is_string($session[self::CSRF_KEY]) ||
            preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $session[self::CSRF_KEY]
            ) !== 1
        ) {
            $session[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
    }

    private function stateResponse(array &$session): array
    {
        $state = &$session[self::SESSION_STATE_KEY];

        if (!is_array($state ?? null)) {
            $state = [];
        }

        return $this->response(200, [
            'ok' => true,
            'csrf_token' => $session[self::CSRF_KEY],
            'uploads' => $this->manager->getUploads($state),
            'summary' => $this->manager->getSummary($state),
            'destination' => $this->destinationData($session),
        ]);
    }

    private function destinationResponse(
        array &$session,
        array $post
    ): array {
        $relativePath = $post['relative_path'] ?? null;

        if (!is_string($relativePath)) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'A destination subdirectory must be supplied as text.',
            ]);
        }

        try {
            $validatedPath = DestinationValidator::validate($relativePath);
        } catch (\InvalidArgumentException $exception) {
            return $this->response(400, [
                'ok' => false,
                'error' => $exception->getMessage(),
            ]);
        }

        $session[self::DESTINATION_KEY] = $validatedPath;

        return $this->response(200, [
            'ok' => true,
            'destination' => $this->destinationData($session),
        ]);
    }

    private function destinationData(array &$session): array
    {
        if (!array_key_exists(self::DESTINATION_KEY, $session)) {
            $session[self::DESTINATION_KEY] = '';
        }

        if (!is_string($session[self::DESTINATION_KEY])) {
            throw new \RuntimeException(
                'The destination session setting is invalid.'
            );
        }

        $relativePath = DestinationValidator::validate(
            $session[self::DESTINATION_KEY]
        );

        return [
            'root' => DestinationValidator::ROOT,
            'relative_path' => $relativePath,
            'absolute_path' => DestinationValidator::absolutePath(
                $relativePath
            ),
        ];
    }

    private function uploadResponse(
        array &$session,
        array $files,
        ?callable $isUploadedFile
    ): array {
        if (!isset($files['files'])) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'No files field was supplied.',
            ]);
        }

        $entries = $this->normaliseFilesField($files['files']);

        if ($entries === null) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'The uploaded files field is malformed.',
            ]);
        }

        $state = &$session[self::SESSION_STATE_KEY];

        if (!is_array($state ?? null)) {
            $state = [];
        }

        $verifyUpload = $isUploadedFile ?? 'is_uploaded_file';
        $results = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $tmpName = $entry['tmp_name'];
            $errorCode = $entry['error'];

            if ($errorCode !== UPLOAD_ERR_OK) {
                $results[] = [
                    'accepted' => false,
                    'name' => $name,
                    'error' => $this->uploadErrorMessage($errorCode),
                ];
                continue;
            }

            if (
                $tmpName === '' ||
                !$verifyUpload($tmpName)
            ) {
                $results[] = [
                    'accepted' => false,
                    'name' => $name,
                    'error' => 'The uploaded file could not be verified.',
                ];
                continue;
            }

            $actualSize = @filesize($tmpName);

            if (
                $actualSize === false ||
                $actualSize > UploadSessionManager::MAX_TORRENT_BYTES
            ) {
                $results[] = [
                    'accepted' => false,
                    'name' => $name,
                    'error' => 'The torrent file exceeds the 5 MiB size limit.',
                ];
                continue;
            }

            if ($entry['size'] !== $actualSize) {
                $results[] = [
                    'accepted' => false,
                    'name' => $name,
                    'error' => 'The uploaded file size did not match the reported size.',
                ];
                continue;
            }

            $data = @file_get_contents($tmpName);

            if (
                $data === false ||
                strlen($data) !== $actualSize
            ) {
                $results[] = [
                    'accepted' => false,
                    'name' => $name,
                    'error' => 'The uploaded file could not be read.',
                ];
                continue;
            }

            $result = $this->manager->addUpload(
                $state,
                $name,
                $data
            );

            $results[] = [
                'name' => $name,
                ...$result,
            ];

            unset($data);
        }

        return $this->response(200, [
            'ok' => true,
            'results' => $results,
            'uploads' => $this->manager->getUploads($state),
            'summary' => $this->manager->getSummary($state),
        ]);
    }

    /**
     * Convert PHP's multi-file $_FILES representation into entries.
     */
    private function normaliseFilesField(mixed $field): ?array
    {
        if (!is_array($field)) {
            return null;
        }

        foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
            if (!isset($field[$key]) || !is_array($field[$key])) {
                return null;
            }
        }

        $names = $field['name'];
        $tmpNames = $field['tmp_name'];
        $errors = $field['error'];
        $sizes = $field['size'];

        if (
            count($names) > UploadSessionManager::MAX_UPLOADS ||
            array_keys($names) !== array_keys($tmpNames) ||
            array_keys($names) !== array_keys($errors) ||
            array_keys($names) !== array_keys($sizes)
        ) {
            return null;
        }

        $entries = [];

        foreach ($names as $key => $name) {
            if (
                !is_string($name) ||
                !isset($tmpNames[$key]) ||
                !is_string($tmpNames[$key]) ||
                !isset($errors[$key]) ||
                !is_int($errors[$key]) ||
                !isset($sizes[$key]) ||
                !is_int($sizes[$key]) ||
                $sizes[$key] < 0
            ) {
                return null;
            }

            $entries[] = [
                'name' => $name,
                'tmp_name' => $tmpNames[$key],
                'error' => $errors[$key],
                'size' => $sizes[$key],
            ];
        }

        return $entries;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'The uploaded file exceeds the configured upload size limit.',
            UPLOAD_ERR_PARTIAL =>
                'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE =>
                'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR =>
                'The server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE =>
                'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION =>
                'A PHP extension stopped the upload.',
            default =>
                'The upload failed with an unknown error.',
        };
    }

    private function removeResponse(
        array &$session,
        array $post
    ): array {
        $id = $post['id'] ?? null;

        if (!is_string($id)) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'A valid upload ID is required.',
            ]);
        }

        $state = &$session[self::SESSION_STATE_KEY];

        if (!is_array($state ?? null)) {
            $state = [];
        }

        if (!$this->manager->removeUpload($state, $id)) {
            return $this->response(404, [
                'ok' => false,
                'error' => 'The upload was not found.',
            ]);
        }

        return $this->updatedStateResponse($state);
    }

    private function selectResponse(
        array &$session,
        array $post
    ): array {
        $id = $post['id'] ?? null;
        $selected = $post['selected'] ?? null;

        if (
            !is_string($id) ||
            !is_bool($selected)
        ) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'A valid upload ID and boolean selection are required.',
            ]);
        }

        $state = &$session[self::SESSION_STATE_KEY];

        if (!is_array($state ?? null)) {
            $state = [];
        }

        if (!$this->manager->setSelected($state, $id, $selected)) {
            return $this->response(400, [
                'ok' => false,
                'error' => 'The upload could not be selected or deselected.',
            ]);
        }

        return $this->updatedStateResponse($state);
    }

    private function clearResponse(array &$session): array
    {
        $state = &$session[self::SESSION_STATE_KEY];

        if (!is_array($state ?? null)) {
            $state = [];
        }

        $this->manager->clear($state);

        return $this->updatedStateResponse($state);
    }

    private function updatedStateResponse(array &$state): array
    {
        return $this->response(200, [
            'ok' => true,
            'uploads' => $this->manager->getUploads($state),
            'summary' => $this->manager->getSummary($state),
        ]);
    }

    private function previewResponse(array &$session): array
    {
        $state = &$session[self::SESSION_STATE_KEY];
    
        if (!is_array($state ?? null)) {
            $state = [];
        }
    
        $selectedUploads = $this->manager->getSelectedUploads($state);
        $torrents = [];
        $totalSize = 0;
    
        foreach ($selectedUploads as $upload) {
            $metadata = $upload['metadata'];
            $size = $metadata['total_size'];
    
            if (
                !is_int($size) ||
                $size < 0 ||
                $size > PHP_INT_MAX - $totalSize
            ) {
                throw new \RuntimeException(
                    'The selected torrent size total is invalid.'
                );
            }
    
            $totalSize += $size;
    
            $torrents[] = [
                'id' => $upload['id'],
                'name' => $upload['original_name'],
                'total_size' => $size,
                'file_count' => $metadata['file_count'],
                'protocol' => $metadata['protocol'],
                'info_hash_v1' => $metadata['info_hash_v1'],
                'info_hash_v2' => $metadata['info_hash_v2'],
            ];
        }
    
        return $this->response(200, [
            'ok' => true,
            'preview' => [
                'count' => count($torrents),
                'total_size' => $totalSize,
                'torrents' => $torrents,
                'destination' => $this->destinationData($session),
            ],
        ]);
    }

    private function response(int $status, array $body): array
    {
        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}
