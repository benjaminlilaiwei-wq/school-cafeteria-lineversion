<?php
require_once __DIR__ . '/env.php';

function cafeteria_ids() {
    return [
        'allergies.json' => 1,
        'attendance.json' => 2,
        'attendance-data.json' => 2,
        'daily-menu.json' => 3,
        'events.json' => 4,
        'holidays.json' => 5,
        'last-stock-reset.json' => 6,
        'menu-advice-cache.json' => 7,
        'menu.json' => 8,
        'reservations-times.json' => 9,
        'reservation-times.json' => 9,
        'sales-data.json' => 10,
        'reviews.json' => 11,
        'line-users.json' => 12,
        'reservations-archive.json' => 13,
    ];
}

function cafeteria_id_from_path($path) {
    $base = basename(str_replace('\\', '/', (string) $path));
    $ids = cafeteria_ids();
    return $ids[$base] ?? null;
}

function cafeteria_last_error() {
    return (string) ($GLOBALS['cafeteria_last_error'] ?? '');
}

function cafeteria_configured() {
    return app_env('SUPABASE_URL') !== '' && app_env('SUPABASE_KEY') !== '';
}

function cafeteria_endpoint() {
    $url = rtrim(app_env('SUPABASE_URL'), '/');
    if (preg_match('#/rest/v1$#', $url)) {
        return $url;
    }
    return $url . '/rest/v1';
}

function cafeteria_http($method, $path, $body, $extraHeaders = []) {
    $GLOBALS['cafeteria_last_error'] = '';
    if (!cafeteria_configured()) {
        $GLOBALS['cafeteria_last_error'] = 'SUPABASE_URL または SUPABASE_KEY が未設定です';
        return [false, 0, ''];
    }
    $key = app_env('SUPABASE_KEY');
    $url = cafeteria_endpoint() . $path;
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ], $extraHeaders);
    $result = cafeteria_exec($url, $method, $headers, $body, true);
    if ($result[1] === 0) {
        $result = cafeteria_exec($url, $method, $headers, $body, false);
    }
    if (!$result[0]) {
        $GLOBALS['cafeteria_last_error'] = 'Supabase HTTP ' . $result[1] . ' ' . substr((string) $result[2], 0, 240);
    }
    return $result;
}

function cafeteria_exec($url, $method, $headers, $body, $verify) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $raw !== false && $code >= 200 && $code < 300;
        return [$ok, $code, $raw === false ? '' : (string) $raw];
    }
    $header = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $header,
            'content' => $body === null ? '' : $body,
            'timeout' => 12,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    $ok = $raw !== false && $code >= 200 && $code < 300;
    return [$ok, $code, $raw === false ? '' : (string) $raw];
}

function cafeteria_cache_get($id) {
    if (!isset($GLOBALS['cafeteria_cache']) || !is_array($GLOBALS['cafeteria_cache'])) {
        return null;
    }
    return $GLOBALS['cafeteria_cache'][$id] ?? null;
}

function cafeteria_cache_set($id, $state, $data) {
    if (!isset($GLOBALS['cafeteria_cache']) || !is_array($GLOBALS['cafeteria_cache'])) {
        $GLOBALS['cafeteria_cache'] = [];
    }
    $GLOBALS['cafeteria_cache'][$id] = ['state' => $state, 'data' => $data];
}

function cafeteria_normalize_data($data) {
    if ($data === null) {
        return null;
    }
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }
        $data = $decoded;
    }
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

function cafeteria_load($path) {
    $id = cafeteria_id_from_path($path);
    if ($id === null) {
        return ['state' => 'error', 'data' => null];
    }
    $cached = cafeteria_cache_get($id);
    if (is_array($cached)) {
        return $cached;
    }
    [$ok, $code, $raw] = cafeteria_http('GET', '/cafeteria?id=eq.' . $id . '&select=data', null);
    if (!$ok) {
        return ['state' => 'error', 'data' => null];
    }
    $rows = json_decode($raw, true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0]) || !array_key_exists('data', $rows[0]) || $rows[0]['data'] === null) {
        $result = ['state' => 'empty', 'data' => null];
        cafeteria_cache_set($id, 'empty', null);
        return $result;
    }
    $data = cafeteria_normalize_data($rows[0]['data']);
    if ($data === null) {
        $result = ['state' => 'empty', 'data' => null];
        cafeteria_cache_set($id, 'empty', null);
        return $result;
    }
    cafeteria_cache_set($id, 'ok', $data);
    return ['state' => 'ok', 'data' => $data];
}

function cafeteria_read($path, $fallback) {
    $loaded = cafeteria_load($path);
    if ($loaded['state'] === 'ok') {
        return $loaded['data'];
    }
    return $fallback;
}

function cafeteria_encode($data) {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    return is_string($json) ? $json : '';
}

function cafeteria_write($path, $data) {
    $id = cafeteria_id_from_path($path);
    if ($id === null) {
        $GLOBALS['cafeteria_last_error'] = '対応する id がありません';
        return false;
    }
    if (!is_array($data)) {
        $GLOBALS['cafeteria_last_error'] = '保存する data が配列ではありません';
        return false;
    }
    $json = cafeteria_encode(['data' => $data]);
    if ($json === '') {
        $GLOBALS['cafeteria_last_error'] = 'JSONに変換できませんでした';
        return false;
    }
    [$ok, $code, $raw] = cafeteria_http('PATCH', '/cafeteria?id=eq.' . $id, $json, ['Prefer: return=representation']);
    $rows = json_decode($raw, true);
    if ($ok && ($code === 204 || (is_array($rows) && count($rows) > 0))) {
        cafeteria_cache_set($id, 'ok', $data);
        return true;
    }
    $insert = cafeteria_encode(['id' => $id, 'data' => $data]);
    if ($insert === '') {
        return false;
    }
    [$inserted] = cafeteria_http('POST', '/cafeteria', $insert, ['Prefer: return=minimal']);
    if ($inserted) {
        cafeteria_cache_set($id, 'ok', $data);
        return true;
    }
    return false;
}
