<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/line-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = line_secret();
    echo 'LINE webhook'
        . "\n" . 'token: ' . ($secret['channelAccessToken'] !== '' ? 'saved' : 'missing')
        . "\n" . 'secret: ' . ($secret['channelSecret'] !== '' ? 'saved' : 'missing');
    exit;
}

$body = file_get_contents('php://input');
if (!line_verify_signature($body)) {
    $budget = line_budget();
    $budget['lastError'] = '署名が一致しません。チャネルシークレットと、LINEに登録したWebhookを確認してください。';
    line_save_budget($budget);
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$data = json_decode($body, true);
if (is_array($data) && isset($data['events']) && is_array($data['events'])) {
    foreach ($data['events'] as $event) {
        if (!is_array($event)) {
            continue;
        }
        try {
            line_handle_event($event);
        } catch (Throwable $e) {
            $budget = line_budget();
            $budget['lastError'] = line_cut($e->getMessage(), 300);
            line_save_budget($budget);
            $token = $event['replyToken'] ?? '';
            $userId = $event['source']['userId'] ?? '';
            line_deliver($token, $userId, [line_text('うまく受け取れなかったよ。もう一度送ってね。')], ($event['type'] ?? '') === 'follow');
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
