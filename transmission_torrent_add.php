<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\DestinationValidator;
use TorrentGate\TransmissionRpcClient;
use TorrentGate\TransmissionRpcUncertainException;

$assertions = 0;

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;

    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }

    echo "PASS: $message\n";
}

function expectException(
    string $class,
    callable $callback,
    string $message
): void {
    try {
        $callback();
    } catch (Throwable $e) {
        check($e instanceof $class, $message);
        return;
    }

    check(false, $message);
}

$torrentData = 'test torrent metainfo bytes';
$requests = [];

$client = new TransmissionRpcClient(
    static function (array $request) use (&$requests): array {
        $requests[] = $request;

        if (count($requests) === 1) {
            return [
                'status' => 409,
                'headers' => [
                    'X-Transmission-Session-Id' => 'add-session-token',
                ],
                'body' => '',
            ];
        }

        $payload = json_decode($request['body'], true, 16, JSON_THROW_ON_ERROR);

        check($payload['method'] === 'torrent-add', 'uses torrent-add RPC method');
        check(
            base64_decode($payload['arguments']['metainfo'], true) ===
                'test torrent metainfo bytes',
            'original torrent bytes are base64 encoded unchanged'
        );
        check(
            $payload['arguments']['download-dir'] ===
                DestinationValidator::ROOT . '/Music/Live',
            'relative destination is resolved beneath fixed root'
        );
        check(
            $payload['arguments']['paused'] === false,
            'torrent is submitted unpaused'
        );

        return [
            'status' => 200,
            'headers' => [],
            'body' => json_encode([
                'result' => 'success',
                'arguments' => [
                    'torrent-added' => [
                        'id' => 42,
                        'hashString' => '0123456789abcdef',
                        'name' => 'Test Torrent',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }
);

$result = $client->addTorrent($torrentData, 'Music/Live');

check(count($requests) === 2, '409 negotiation retries exactly once');
check(
    in_array('X-Transmission-Session-Id: add-session-token', $requests[1]['headers'], true),
    'retry includes negotiated session ID'
);
check($result['status'] === 'added', 'new torrent is reported as added');
check($result['id'] === 42, 'Transmission torrent ID is returned');
check($result['hash_string'] === '0123456789abcdef', 'torrent hash is returned');
check($result['name'] === 'Test Torrent', 'torrent name is returned');

$duplicateClient = new TransmissionRpcClient(
    static fn (array $request): array => [
        'status' => 200,
        'headers' => [],
        'body' => json_encode([
            'result' => 'success',
            'arguments' => [
                'torrent-duplicate' => [
                    'id' => 7,
                    'hashString' => 'duplicate-hash',
                    'name' => 'Existing Torrent',
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    ]
);

$duplicate = $duplicateClient->addTorrent($torrentData, '');

check($duplicate['status'] === 'duplicate', 'existing torrent is reported as duplicate');
check($duplicate['id'] === 7, 'duplicate torrent ID is returned');

$invalidDestinationRequests = 0;

$invalidDestinationClient = new TransmissionRpcClient(
    static function (array $request) use (&$invalidDestinationRequests): array {
        $invalidDestinationRequests++;

        return [
            'status' => 200,
            'headers' => [],
            'body' => '{}',
        ];
    }
);

expectException(
    InvalidArgumentException::class,
    static fn () => $invalidDestinationClient->addTorrent($torrentData, '../outside'),
    'unsafe destination is rejected'
);

check($invalidDestinationRequests === 0, 'invalid destination causes no RPC request');

$uncertainClient = new TransmissionRpcClient(
    static function (array $request): array {
        throw new RuntimeException('simulated connection loss');
    }
);

expectException(
    TransmissionRpcUncertainException::class,
    static fn () => $uncertainClient->addTorrent($torrentData, ''),
    'transport failure is classified as uncertain'
);

$malformedResponseClient = new TransmissionRpcClient(
    static fn (array $request): array => [
        'status' => 200,
        'headers' => [],
        'body' => 'not-json',
    ]
);

expectException(
    TransmissionRpcUncertainException::class,
    static fn () => $malformedResponseClient->addTorrent($torrentData, ''),
    'malformed response is classified as uncertain'
);

$rpcFailureClient = new TransmissionRpcClient(
    static fn (array $request): array => [
        'status' => 200,
        'headers' => [],
        'body' => json_encode([
            'result' => 'invalid request',
            'arguments' => [],
        ], JSON_THROW_ON_ERROR),
    ]
);

expectException(
    RuntimeException::class,
    static fn () => $rpcFailureClient->addTorrent($torrentData, ''),
    'explicit Transmission RPC error is a definite failure'
);

echo "\n{$assertions} assertions passed.\n";
