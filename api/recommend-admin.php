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

echo json_encode(recommend_admin_stats(), JSON_UNESCAPED_UNICODE);
