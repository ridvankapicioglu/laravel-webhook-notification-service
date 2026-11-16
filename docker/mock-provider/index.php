<?php

/*
 * Local stand-in for webhook.site, so `docker compose up` works without any external setup.
 * It answers exactly like the configured webhook.site URL in the assessment:
 *   202 {"messageId": "...", "status": "accepted", "timestamp": "..."}
 *
 * MOCK_FAILURE_RATE (0..1) makes a share of the requests fail with 500/503/429,
 * which is handy to watch the retry logic work.
 */

// Arrival time in milliseconds, logged with every request so the rate limit can be checked from outside.
$receivedAt = sprintf('%.3f', microtime(true));

$failureRate = (float) (getenv('MOCK_FAILURE_RATE') ?: 0);
$latencyMs = (int) (getenv('MOCK_LATENCY_MS') ?: 50);

usleep($latencyMs * 1000);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok']);

    return;
}

$body = json_decode((string) file_get_contents('php://input'), true);

if (! is_array($body) || empty($body['to']) || empty($body['channel']) || ! isset($body['content'])) {
    http_response_code(400);
    echo json_encode(['error' => 'to, channel and content are required']);

    return;
}

if ($failureRate > 0 && mt_rand() / mt_getrandmax() < $failureRate) {
    $status = [500, 503, 429][array_rand([500, 503, 429])];
    http_response_code($status);

    if ($status === 429) {
        header('Retry-After: 5');
    }

    file_put_contents('php://stderr', "[mock-provider] {$receivedAt} {$status} {$body['channel']} {$body['to']}\n");
    echo json_encode(['error' => 'simulated failure']);

    return;
}

$bytes = random_bytes(16);
$bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
$bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);
$messageId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

file_put_contents('php://stderr', "[mock-provider] {$receivedAt} 202 {$body['channel']} {$body['to']} {$messageId}\n");

http_response_code(202);
echo json_encode([
    'messageId' => $messageId,
    'status' => 'accepted',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
]);
