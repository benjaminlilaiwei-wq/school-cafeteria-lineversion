<?php
// Simple JSON storage API for menus
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
$file = __DIR__ . '/../data/menu.json';
$lastResetFile = __DIR__ . '/../data/last-stock-reset.json';

function read_json($file) {
  return cafeteria_read($file, []);
}

function write_json($file, $data) {
  return cafeteria_write($file, $data);
}

// 日付が変わったとき、または手動リセット時にメニュー残数を initialStock に戻す
function maybe_reset_stock_by_day($menus, $file, $lastResetFile, $force = false) {
  $today = date('Y-m-d');
  $lastDate = '';
  if (!$force) {
    $loaded = cafeteria_load($lastResetFile);
    if ($loaded['state'] === 'error') {
      return $menus;
    }
    if ($loaded['state'] === 'ok' && is_array($loaded['data']) && !empty($loaded['data']['date'])) {
      $lastDate = $loaded['data']['date'];
    }
  }
  if (!$force && $lastDate === $today) {
    return $menus;
  }
  $changed = false;
  foreach ($menus as $i => $m) {
    $initial = isset($m['initialStock']) ? (int)$m['initialStock'] : (isset($m['stock']) ? (int)$m['stock'] : 0);
    $current = isset($m['stock']) ? (int)$m['stock'] : 0;
    if ($current !== $initial) {
      $menus[$i]['stock'] = $initial;
      $changed = true;
    }
  }
  if ($changed || $force || $lastDate !== $today) {
    write_json($file, $menus);
    write_json($lastResetFile, ['date' => $today]);
  }
  return $menus;
}

switch ($_SERVER['REQUEST_METHOD']) {
  case 'GET':
    $loaded = cafeteria_load($file);
    if ($loaded['state'] === 'error') {
      http_response_code(500);
      echo json_encode([ 'error' => 'Supabaseから読めませんでした。' . cafeteria_last_error() ], JSON_UNESCAPED_UNICODE);
      break;
    }
    $menus = $loaded['state'] === 'ok' ? $loaded['data'] : [];
    $forceReset = isset($_GET['reset']) && $_GET['reset'] === '1';
    $menus = maybe_reset_stock_by_day($menus, $file, $lastResetFile, $forceReset);
    echo json_encode($menus, JSON_UNESCAPED_UNICODE);
    break;
  case 'POST':
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
      http_response_code(400);
      echo json_encode([ 'error' => 'Invalid JSON array' ], JSON_UNESCAPED_UNICODE);
      break;
    }
    if (!write_json($file, $data)) {
      http_response_code(500);
      echo json_encode([ 'error' => 'Supabaseに保存できませんでした。' . cafeteria_last_error() ], JSON_UNESCAPED_UNICODE);
      break;
    }
    echo json_encode([ 'ok' => true ], JSON_UNESCAPED_UNICODE);
    break;
  default:
    http_response_code(405);
    echo json_encode([ 'error' => 'Method not allowed' ], JSON_UNESCAPED_UNICODE);
}
?>

