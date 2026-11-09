<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TorrentGate\TransmissionRpcClient;

try {
    $client = new TransmissionRpcClient();
    $session = $client->sessionGet();

    foreach (['version', 'rpc-version', 'rpc-version-minimum'] as $key) {
        if (array_key_exists($key, $session)) {
            printf("%s: %s\n", $key, (string) $session[$key]);
        }
    }

    echo "Authenticated Transmission RPC connection successful.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "RPC test failed: " . $e->getMessage() . "\n");
    exit(1);
}
