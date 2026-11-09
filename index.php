<?php

declare(strict_types=1);

use TorrentGate\Http\UploadController;
use TorrentGate\UploadSessionManager;

require dirname(__DIR__) . '/vendor/autoload.php';

// Render the application page for the normal browser entry point.
// Explicit action URLs continue through the JSON API below.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' &&
    ($requestPath === '/' || $requestPath === '/index.php') &&
    !array_key_exists('action', $_GET)
) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    require dirname(__DIR__) . '/templates/app.php';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function sendJsonResponse(int $status, array $body): never
{
    http_response_code($status);

    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Convert PHP shorthand sizes such as 27M into bytes.
 */
function iniSizeInBytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

if (!session_start()) {
    sendJsonResponse(500, [
        'ok' => false,
        'error' => 'The session could not be started.',
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = $_GET['action'] ?? 'state';

if (!is_string($action)) {
    sendJsonResponse(400, [
        'ok' => false,
        'error' => 'Invalid action.',
    ]);
}

$post = [];
$files = [];

if ($method === 'POST') {
    $contentLength = isset($_SERVER['CONTENT_LENGTH'])
        ? (int) $_SERVER['CONTENT_LENGTH']
        : 0;

    $postLimit = iniSizeInBytes((string) ini_get('post_max_size'));

    if ($postLimit > 0 && $contentLength > $postLimit) {
        sendJsonResponse(413, [
            'ok' => false,
            'error' => 'The request exceeds the configured POST size limit.',
        ]);
    }

    $contentType = strtolower(
        trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
    );

    if ($action === 'upload') {
        if ($contentType !== 'multipart/form-data') {
            sendJsonResponse(415, [
                'ok' => false,
                'error' => 'Uploads must use multipart/form-data.',
            ]);
        }

        $files = $_FILES;
    } else {
        if ($contentType !== 'application/json') {
            sendJsonResponse(415, [
                'ok' => false,
                'error' => 'This action requires application/json.',
            ]);
        }

        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || strlen($rawBody) > 16384) {
            sendJsonResponse(413, [
                'ok' => false,
                'error' => 'The request body is too large.',
            ]);
        }

        try {
            $decoded = json_decode(
                $rawBody,
                false,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            sendJsonResponse(400, [
                'ok' => false,
                'error' => 'The request contains invalid JSON.',
            ]);
        }

        if (!$decoded instanceof stdClass) {
            sendJsonResponse(400, [
                'ok' => false,
                'error' => 'The request JSON must be an object.',
            ]);
        }

        $post = (array) $decoded;
    }
} elseif ($method !== 'GET') {
    sendJsonResponse(405, [
        'ok' => false,
        'error' => 'Method not allowed.',
    ]);
}

$csrfHeader = $_SERVER['HTTP_X_TORRENTGATE_CSRF'] ?? null;

if (!is_string($csrfHeader)) {
    $csrfHeader = null;
}

try {
    $manager = new UploadSessionManager(
        '/var/lib/torrentgate/temporary'
    );

    $controller = new UploadController($manager);

    $result = $controller->handle(
        $method,
        $action,
        $_SESSION,
        $post,
        $files,
        $csrfHeader
    );

    sendJsonResponse($result['status'], $result['body']);
} catch (Throwable $exception) {
    error_log(
        'TorrentGate front controller error: ' .
        $exception::class . ': ' . $exception->getMessage()
    );

    sendJsonResponse(500, [
        'ok' => false,
        'error' => 'The request could not be completed.',
    ]);
}
