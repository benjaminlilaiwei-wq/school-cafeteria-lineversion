<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/line-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

function line_survey_fail($code, $error) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $user = line_user_by_key($key);
    if (!$user) {
        line_survey_fail(404, 'このリンクでは開けないよ。LINEのトークから開いてね。');
    }
    echo json_encode(line_survey_public($user), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    line_survey_fail(405, 'この方法では送れないよ。');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    line_survey_fail(400, '送れなかったよ。もう一度押してね。');
}
$user = line_user_by_key((string) ($data['key'] ?? ''));
if (!$user) {
    line_survey_fail(404, 'このリンクでは開けないよ。LINEのトークから開いてね。');
}
[$ok, $error, $saved] = line_apply_survey($user, $data);
if (!$ok) {
    line_survey_fail(400, $error);
}
try {
    line_ensure_rich_menu();
    line_sync_user_menu($saved);
} catch (Throwable $e) {
    $budget = line_budget();
    $budget['lastError'] = line_cut($e->getMessage(), 300);
    line_save_budget($budget);
}
$payload = line_survey_public($saved);
$payload['saved'] = true;
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
