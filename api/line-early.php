<?php
require_once __DIR__ . '/line-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

$action = $_GET['action'] ?? 'tick';
if ($action === 'preview') {
    echo json_encode(line_preview(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'send' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $result = line_send_early(true);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}
$result = line_send_early(false);
echo json_encode([
    'ok' => !empty($result['ok']),
    'sent' => (int) ($result['sent'] ?? 0),
    'reason' => (string) ($result['reason'] ?? ''),
], JSON_UNESCAPED_UNICODE);
