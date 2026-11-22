<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/supabase-store.php';
$file = __DIR__ . '/../data/attendance-data.json';

function readJsonSafe($file) {
    return cafeteria_read($file, []);
}

function writeJsonSafe($file, $data) {
    return cafeteria_write($file, $data);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $loaded = cafeteria_load($file);
        if ($loaded['state'] === 'error') {
            http_response_code(500);
            echo json_encode(['error' => 'Supabaseから読めませんでした。' . cafeteria_last_error()], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode($loaded['state'] === 'ok' ? $loaded['data'] : [], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        if (!isset($input['attendance']) || !is_array($input['attendance'])) {
            http_response_code(400);
            echo json_encode(['error' => 'attendance配列が必要です'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        if (!writeJsonSafe($file, $input)) {
            http_response_code(500);
            echo json_encode(['error' => 'Supabaseに保存できませんでした。' . cafeteria_last_error()], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['success' => true, 'message' => '来客数データを保存しました'], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>
