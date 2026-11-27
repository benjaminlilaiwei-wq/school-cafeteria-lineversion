<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/recommend-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'この方法では送れません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 4000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '送れませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$input = json_decode($raw, true);
$result = recommend_submit_feedback(is_array($input) ? $input : []);
if (empty($result['ok'])) {
    http_response_code((int) ($result['status'] ?? 400));
}
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
