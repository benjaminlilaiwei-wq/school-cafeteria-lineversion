<?php
require_once __DIR__ . '/line-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

$settings = line_settings();
$secret = line_secret();
echo json_encode([
    'addUrl' => $settings['addUrl'],
    'liffId' => $settings['liffId'],
    'ready' => $secret['channelAccessToken'] !== '' && $settings['addUrl'] !== '',
], JSON_UNESCAPED_UNICODE);
