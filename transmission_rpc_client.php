<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\TransmissionRpcClient;

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

$requests = [];

$client = new TransmissionRpcClient(
    static function (array $request) use (&$requests): array {
        $requests[] = $request;

        if (count($requests) === 1) {
            return [
                'status' => 409,
                'headers' => [
                    'X-Transmission-Session-Id' => 'test-session-token',
                ],
                'body' => '',
            ];
        }

        return [
            'status' => 200,
            'headers' => [],
            'body' => json_encode([
                'result' => 'success',
                'arguments' => [
                    'version' => 'test-version',
                    'rpc-version' => 17,
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }
);

$result = $client->sessionGet();

check(count($requests) === 2, '409 response triggers exactly one retry');
check(
    !in_array('X-Transmission-Session-Id: test-session-token', $requests[0]['headers'], true),
    'first request has no session ID'
);
check(
    in_array('X-Transmission-Session-Id: test-session-token', $requests[1]['headers'], true),
    'retry includes negotiated session ID'
);
check(
    $result['version'] === 'test-version',
    'successful response arguments are returned'
);
check(
    $requests[0]['username'] !== '' && $requests[0]['password'] !== '',
    'credentials are loaded for the request'
);

echo "\n{$assertions} assertions passed.\n";
