<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/recommend-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    recommend_stamp_standard_ids();
    $assets = recommend_load_doc(recommend_assets_file(), ['menus' => [], 'standard' => []]);
    $assets = recommend_normalize_assets(is_array($assets) ? $assets : []);
    $items = [];
    foreach (recommend_standard_catalog() as $item) {
        $record = $assets['standard'][$item['id']] ?? null;
        $payload = is_array($record) ? recommend_image_payload($record) : recommend_image_payload(null);
        $ready = ($payload['status'] ?? '') === 'ready';
        $items[] = [
            'id' => $item['id'],
            'name' => $item['name'],
            'path' => $item['file'],
            'ready' => $ready,
            'url' => $ready ? (string) $payload['url'] : '',
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'この方法では開けません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = trim((string) ($_POST['id'] ?? ''));
$file = $_FILES['file'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '写真ファイルを選んでください。画像のアドレスは登録できません。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$tmp = (string) ($file['tmp_name'] ?? '');
$binary = ($tmp !== '' && is_uploaded_file($tmp)) ? @file_get_contents($tmp) : false;
$result = recommend_save_standard_file($id, is_string($binary) ? $binary : '');
if (empty($result['ok'])) {
    http_response_code(400);
}
echo json_encode([
    'ok' => !empty($result['ok']),
    'error' => (string) ($result['error'] ?? ''),
    'id' => $id,
    'image' => $result['image'] ?? recommend_image_payload(null),
], JSON_UNESCAPED_UNICODE);
