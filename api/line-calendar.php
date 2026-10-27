<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/line-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
$user = line_user_by_key($key);
echo json_encode(line_calendar($user), JSON_UNESCAPED_UNICODE);
