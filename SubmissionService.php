<?php

declare(strict_types=1);

namespace TorrentGate;

use RuntimeException;
use Throwable;

final class SubmissionService
{
    /** @var \Closure(string,string):array */
    private readonly \Closure $submitTorrent;

    public function __construct(
        private readonly UploadSessionManager $uploads,
        private readonly SubmissionAttemptStore $attempts,
        ?callable $submitTorrent = null
    ) {
        if ($submitTorrent === null) {
            $client = new TransmissionRpcClient();

            $submitTorrent = static function (
                string $data,
                string $destination
            ) use ($client): array {
                return $client->addTorrent($data, $destination);
            };
        }

        $this->submitTorrent = \Closure::fromCallable($submitTorrent);
    }

    /**
     * Submit selected, valid, unique uploads.
     *
     * The session key must be a server-generated, stable per-session value.
     * The controller will use its session CSRF token for this purpose.
     *
     * @return array{
     *     ok:bool,
     *     selected_count:int,
     *     results:list<array<string,mixed>>
     * }
     */
    public function submit(
        array &$state,
        string $sessionKey,
        string $relativeDestination
    ): array {
        // Validate before creating any attempt records or making RPC calls.
        $destination = DestinationValidator::validate(
            $relativeDestination
        );

        $selected = $this->uploads->getSelectedUploads($state);
        $results = [];

        foreach ($selected as $upload) {
            $uploadId = $upload['id'];

            // An existing record means this upload has already been
            // attempted. Return its stored outcome without contacting
            // Transmission again.
            $existing = $this->attempts->get(
                $sessionKey,
                $uploadId
            );

            if ($existing !== null) {
                $results[] = $this->formatExistingResult(
                    $upload,
                    $existing
                );
                continue;
            }

            // Atomically create the conservative uncertain marker before
            // reading the torrent or contacting Transmission.
            if (!$this->attempts->begin($sessionKey, $uploadId)) {
                $existing = $this->attempts->get(
                    $sessionKey,
                    $uploadId
                );

                if ($existing === null) {
                    throw new RuntimeException(
                        'A submission attempt already exists but cannot be read.'
                    );
                }

                $results[] = $this->formatExistingResult(
                    $upload,
                    $existing
                );
                continue;
            }

            // Failures before the RPC call are definite failures. Once the
            // RPC call starts, only its explicit uncertain exception is
            // classified as uncertain.
            try {
                $temporaryPath = $this->uploads->getTemporaryPath(
                    $uploadId
                );

                if ($temporaryPath === null) {
                    throw new RuntimeException(
                        'The original torrent file is unavailable.'
                    );
                }

                $torrentData = @file_get_contents($temporaryPath);

                if ($torrentData === false || $torrentData === '') {
                    throw new RuntimeException(
                        'The original torrent file could not be read.'
                    );
                }

                $rpcResult = ($this->submitTorrent)(
                    $torrentData,
                    $destination
                );

		if (
                    !isset(
                        $rpcResult['status'],
                        $rpcResult['id'],
                        $rpcResult['hash_string'],
                        $rpcResult['name']
                    ) ||
                    !in_array($rpcResult['status'], ['added', 'duplicate'], true) ||
                    !is_int($rpcResult['id']) ||
                    !is_string($rpcResult['hash_string']) ||
                    !is_string($rpcResult['name'])
                ) {
                    throw new TransmissionRpcUncertainException(
                        'The submission returned an unrecognised result.'
                    );
                }

            } catch (TransmissionRpcUncertainException $exception) {
                $result = [
                    'status' => 'uncertain',
                    'error' => 'Transmission may have received this torrent. It will not be retried automatically.',
                ];

                // If this write fails, the initial uncertain marker remains.
                $this->attempts->complete(
                    $sessionKey,
                    $uploadId,
                    'uncertain',
                    $result
                );

                $results[] = $this->formatResult($upload, $result);
                continue;
            } catch (Throwable $exception) {
                $result = [
                    'status' => 'failed',
                    'error' => 'The torrent could not be submitted.',
                ];

                // A definite local or RPC rejection is also recorded so
                // repeated requests cannot silently retry it.
                $this->attempts->complete(
                    $sessionKey,
                    $uploadId,
                    'failed',
                    $result
                );

                $results[] = $this->formatResult($upload, $result);
                continue;
            }

            // Keep this outside the try/catch above. If persisting a
            // confirmed RPC response fails, the original uncertain marker
            // must remain rather than being overwritten as "failed".
            $result = [
                'status' => $rpcResult['status'],
                'transmission' => [
                    'id' => $rpcResult['id'] ?? null,
                    'hash_string' => $rpcResult['hash_string'] ?? null,
                    'name' => $rpcResult['name'] ?? null,
                ],
            ];

            $this->attempts->complete(
                $sessionKey,
                $uploadId,
                $rpcResult['status'],
                $result
            );

            $results[] = $this->formatResult($upload, $result);
        }

        return [
            'ok' => true,
            'selected_count' => count($selected),
            'results' => $results,
        ];
    }

    private function formatExistingResult(
        array $upload,
        array $record
    ): array {
        $result = $record['result'];

        if (!is_array($result)) {
            $result = [
                'status' => $record['status'],
                'error' => $record['status'] === 'uncertain'
                    ? 'The previous submission outcome is uncertain. It will not be retried automatically.'
                    : 'A previous submission attempt was recorded.',
            ];
        }

        // The record's status is authoritative.
        $result['status'] = $record['status'];

        return $this->formatResult($upload, $result);
    }

    private function formatResult(array $upload, array $result): array
    {
        return [
            'upload_id' => $upload['id'],
            'filename' => $upload['original_name'],
            'status' => $result['status'],
            'error' => $result['error'] ?? null,
            'transmission' => $result['transmission'] ?? null,
        ];
    }
}
