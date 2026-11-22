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
$file = __DIR__ . '/../data/reservation-times.json';
$defaultSettings = [
    'enabled' => false,
    'timeSlots' => [
        ['startTime' => '11:30', 'endTime' => '12:45']
    ],
    'message' => '予約時間: 11:30-12:45'
];

function readJsonSafe($file) {
    $loaded = cafeteria_load($file);
    if ($loaded['state'] === 'ok' && is_array($loaded['data'])) {
        return $loaded['data'];
    }
    return [];
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
        $data = $loaded['state'] === 'ok' ? $loaded['data'] : $defaultSettings;
        if ($loaded['state'] === 'empty') {
            writeJsonSafe($file, $data);
        }
        // 後方互換性: 古い形式（startTime/endTime）を新しい形式（timeSlots）に変換
        if (isset($data['startTime']) && isset($data['endTime']) && !isset($data['timeSlots'])) {
            $data['timeSlots'] = [
                ['startTime' => $data['startTime'], 'endTime' => $data['endTime']]
            ];
            unset($data['startTime']);
            unset($data['endTime']);
            // 変換したデータを保存
            writeJsonSafe($file, $data);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        // 後方互換性: 古い形式（startTime/endTime）を新しい形式（timeSlots）に変換
        if (isset($input['startTime']) && isset($input['endTime']) && !isset($input['timeSlots'])) {
            $input['timeSlots'] = [
                ['startTime' => $input['startTime'], 'endTime' => $input['endTime']]
            ];
            unset($input['startTime']);
            unset($input['endTime']);
        }
        
        // 新しい形式のバリデーション
        if (!isset($input['timeSlots']) || !is_array($input['timeSlots']) || empty($input['timeSlots'])) {
            http_response_code(400);
            echo json_encode(['error' => 'timeSlots配列が必要です'], JSON_UNESCAPED_UNICODE);
            break;
        }
        
        // 各時間帯のバリデーション
        foreach ($input['timeSlots'] as $index => $slot) {
            if (!isset($slot['startTime']) || !isset($slot['endTime'])) {
                http_response_code(400);
                echo json_encode(['error' => "timeSlots[$index]にstartTimeとendTimeが必要です"], JSON_UNESCAPED_UNICODE);
                break 2;
            }
            if ($slot['startTime'] >= $slot['endTime']) {
                http_response_code(400);
                echo json_encode(['error' => "timeSlots[$index]の開始時間は終了時間より早く設定してください"], JSON_UNESCAPED_UNICODE);
                break 2;
            }
        }
        
        // enabledが設定されていない場合はtrueに設定
        if (!isset($input['enabled'])) {
            $input['enabled'] = true;
        }
        
        // メッセージが設定されていない場合は自動生成
        if (!isset($input['message']) || empty($input['message'])) {
            $timeStrings = array_map(function($slot) {
                return "{$slot['startTime']}-{$slot['endTime']}";
            }, $input['timeSlots']);
            $input['message'] = '予約時間: ' . implode(', ', $timeStrings);
        }
        
        if (!writeJsonSafe($file, $input)) {
            http_response_code(500);
            echo json_encode(['error' => 'Supabaseに保存できませんでした。' . cafeteria_last_error()], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['success' => true, 'message' => '予約時間設定を更新しました'], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
}
?>



