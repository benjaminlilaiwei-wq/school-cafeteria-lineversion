<?php
require_once __DIR__ . '/line-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(line_preview(), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$savedSettings = line_save_settings($input);
$savedSecret = line_save_secret($input['channelAccessToken'] ?? '', $input['channelSecret'] ?? '');
echo json_encode([
    'ok' => $savedSettings !== false && $savedSecret,
    'preview' => line_preview(),
], JSON_UNESCAPED_UNICODE);
