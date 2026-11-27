<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/recommend-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'この方法では開けません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(70);
$food = isset($_GET['food']) ? trim((string) $_GET['food']) : '';
$result = recommend_ensure_image($food);
if (empty($result['ok'])) {
    http_response_code((int) ($result['status'] ?? 400));
}
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
