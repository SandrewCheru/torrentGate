<?php

declare(strict_types=1);

namespace TorrentGate;

use RuntimeException;
use JsonException;

final class TransmissionRpcClient
{
    private const ENDPOINT = 'http://127.0.0.1:9091/transmission/rpc';
    private const CONFIG_FILE = '/etc/torrentgate/transmission.json';
    private const CONNECT_TIMEOUT = 3;
    private const REQUEST_TIMEOUT = 8;

    /** @var array{username:string,password:string} */
    private array $credentials;

    /** @var callable|null Test seam; never configured from an HTTP request. */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
        $this->credentials = $this->loadCredentials();
    }

    /**
     * Read-only connectivity test.
     *
     * @return array<string,mixed>
     */
    public function sessionGet(): array
    {
        return $this->call('session-get');
    }

    /**
     * Submit one torrent to Transmission.
     *
     * @return array{
     *     status:'added'|'duplicate',
     *     id:int,
     *     hash_string:string,
     *     name:string
     * }
     */
    public function addTorrent(
        string $torrentData,
        string $relativeDestination
    ): array {
        if ($torrentData === '') {
            throw new RuntimeException('Torrent data is empty.');
        }

        $downloadDirectory = DestinationValidator::absolutePath(
            $relativeDestination
        );

        $arguments = $this->call('torrent-add', [
            'metainfo' => base64_encode($torrentData),
            'download-dir' => $downloadDirectory,
            'paused' => false,
        ], true);

        $added = $arguments['torrent-added'] ?? null;
        $duplicate = $arguments['torrent-duplicate'] ?? null;

        if (is_array($added)) {
            $status = 'added';
            $torrent = $added;
        } elseif (is_array($duplicate)) {
            $status = 'duplicate';
            $torrent = $duplicate;
        } else {
            throw new TransmissionRpcUncertainException(
                'Transmission may have processed the torrent, but returned an unexpected response.'
            );
        }

        if (
            !isset($torrent['id'], $torrent['hashString'], $torrent['name'])
            || !is_int($torrent['id'])
            || !is_string($torrent['hashString'])
            || !is_string($torrent['name'])
        ) {
            throw new TransmissionRpcUncertainException(
                'Transmission may have processed the torrent, but returned incomplete torrent details.'
            );
        }

        return [
            'status' => $status,
            'id' => $torrent['id'],
            'hash_string' => $torrent['hashString'],
            'name' => $torrent['name'],
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(
        string $method,
        array $arguments = [],
        bool $uncertainOnFailure = false
    ): array
    {
        try {
            $payload = json_encode([
                'method' => $method,
                'arguments' => (object) $arguments,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode Transmission RPC request.');
        }

        $sessionId = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ];

            if ($sessionId !== null) {
                $headers[] = 'X-Transmission-Session-Id: ' . $sessionId;
            }

            try {
                $response = $this->send($headers, $payload);
            } catch (RuntimeException $e) {
                if ($uncertainOnFailure) {
                    throw new TransmissionRpcUncertainException(
                        'Transmission submission outcome is uncertain. Do not retry automatically.',
                        0,
                        $e
                    );
                }

                throw $e;
            }

            if ($response['status'] === 409) {
                $newSessionId = $this->findHeader(
                    $response['headers'],
                    'x-transmission-session-id'
                );

                if ($attempt !== 0 || $newSessionId === null || $newSessionId === '') {
                    throw new RuntimeException(
                        'Transmission RPC session negotiation failed.'
                    );
                }

                $sessionId = $newSessionId;
                continue;
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                if ($uncertainOnFailure) {
                    throw new TransmissionRpcUncertainException(
                        'Transmission submission returned HTTP '
                        . $response['status']
                        . '. Its outcome may be uncertain.'
                    );
                }

                throw new RuntimeException(
                    'Transmission RPC returned HTTP ' . $response['status'] . '.'
                );
            }

            try {
                $decoded = json_decode(
                    $response['body'],
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                if ($uncertainOnFailure) {
                    throw new TransmissionRpcUncertainException(
                        'Transmission may have processed the torrent, but returned invalid JSON.',
                        0,
                        $e
                    );
                }

                throw new RuntimeException('Transmission RPC returned invalid JSON.');
            }

            if (
                !is_array($decoded)
                || !isset($decoded['result'])
                || !is_string($decoded['result'])
                || !array_key_exists('arguments', $decoded)
                || !is_array($decoded['arguments'])
            ) {
                if ($uncertainOnFailure) {
                    throw new TransmissionRpcUncertainException(
                        'Transmission may have processed the torrent, but returned an unexpected response.'
                    );
                }

                throw new RuntimeException('Transmission RPC returned an unexpected response.');
            }

            if ($decoded['result'] !== 'success') {
                throw new RuntimeException('Transmission RPC method failed.');
            }

            return $decoded['arguments'];
        }

        throw new RuntimeException('Transmission RPC request could not be completed.');
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function send(array $headers, string $payload): array
    {
        $request = [
            'headers' => $headers,
            'body' => $payload,
            'username' => $this->credentials['username'],
            'password' => $this->credentials['password'],
        ];

        if ($this->transport !== null) {
            $response = ($this->transport)($request);

            if (
                !is_array($response)
                || !isset($response['status'], $response['headers'], $response['body'])
                || !is_int($response['status'])
                || !is_array($response['headers'])
                || !is_string($response['body'])
            ) {
                throw new RuntimeException('Invalid RPC transport response.');
            }

            return $response;
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is unavailable.');
        }

        $handle = curl_init(self::ENDPOINT);

        if ($handle === false) {
            throw new RuntimeException('Could not initialize Transmission RPC connection.');
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->credentials['username']
                . ':'
                . $this->credentials['password'],
            CURLOPT_PROXY => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $responseHeaders[$name] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        $body = curl_exec($handle);

        if ($body === false) {
            curl_close($handle);
            throw new RuntimeException('Could not connect to the local Transmission RPC service.');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    /**
     * @return array{username:string,password:string}
     */
    private function loadCredentials(): array
    {
        if (!is_readable(self::CONFIG_FILE)) {
            throw new RuntimeException('Transmission RPC credentials are unavailable.');
        }

        $contents = file_get_contents(self::CONFIG_FILE);

        if ($contents === false) {
            throw new RuntimeException('Could not read Transmission RPC credentials.');
        }

        try {
            $config = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Transmission RPC credentials are invalid.');
        }

        if (
            !is_array($config)
            || !isset($config['username'], $config['password'])
            || !is_string($config['username'])
            || !is_string($config['password'])
            || $config['username'] === ''
            || $config['password'] === ''
        ) {
            throw new RuntimeException('Transmission RPC credentials are invalid.');
        }

        return [
            'username' => $config['username'],
            'password' => $config['password'],
        ];
    }

    /**
     * @param array<string,string> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }
}
