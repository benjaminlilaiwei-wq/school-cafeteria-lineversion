<?php
require_once __DIR__ . '/env.php';
date_default_timezone_set('Asia/Tokyo');

if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        if ($length === null) {
            return substr($string, $start);
        }
        return substr($string, $start, $length);
    }
}

function line_data_dir() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function line_read_json($file, $fallback) {
    if (!file_exists($file)) {
        return $fallback;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $fallback;
}

function line_write_json($file, $data) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if (!is_string($json) || $json === '') {
        return false;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $tmp = $file . '.' . str_replace('.', '', uniqid('tmp', true));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (@rename($tmp, $file)) {
        return true;
    }
    @unlink($file);
    if (@rename($tmp, $file)) {
        return true;
    }
    $written = @file_put_contents($file, $json, LOCK_EX);
    @unlink($tmp);
    return $written !== false;
}

function line_cut($text, $len) {
    $text = (string) $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $len);
    }
    return substr($text, 0, $len);
}

function line_settings() {
    $file = line_data_dir() . '/line-settings.json';
    $defaults = [
        'addUrl' => '',
        'liffId' => '',
        'earlyEnabled' => true,
        'earlyCount' => 7,
        'earlyMinutes' => 30,
        'sendHour' => 7,
        'monthlyLimit' => 200,
    ];
    $saved = line_read_json($file, []);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function line_save_settings($settings) {
    $current = line_settings();
    $next = $current;
    if (isset($settings['addUrl'])) {
        $next['addUrl'] = trim((string) $settings['addUrl']);
    }
    if (isset($settings['liffId'])) {
        $next['liffId'] = trim((string) $settings['liffId']);
    }
    $next['earlyEnabled'] = !empty($settings['earlyEnabled']);
    $count = isset($settings['earlyCount']) ? (int) $settings['earlyCount'] : (int) $current['earlyCount'];
    $next['earlyCount'] = max(1, min(7, $count));
    $minutes = isset($settings['earlyMinutes']) ? (int) $settings['earlyMinutes'] : (int) $current['earlyMinutes'];
    if (!in_array($minutes, [15, 30, 60], true)) {
        $minutes = 30;
    }
    $next['earlyMinutes'] = $minutes;
    $hour = isset($settings['sendHour']) ? (int) $settings['sendHour'] : (int) $current['sendHour'];
    $next['sendHour'] = max(6, min(10, $hour));
    $next['monthlyLimit'] = 200;
    return line_write_json(line_data_dir() . '/line-settings.json', $next);
}

function line_secret_from_file() {
    $file = __DIR__ . '/line-secret.php';
    $data = file_exists($file) ? include $file : [];
    if (!is_array($data)) {
        $data = [];
    }
    return [
        'channelAccessToken' => (string) ($data['channelAccessToken'] ?? ''),
        'channelSecret' => (string) ($data['channelSecret'] ?? ''),
    ];
}

function line_secret() {
    $data = line_secret_from_file();
    $token = app_env('LINE_CHANNEL_ACCESS_TOKEN');
    $secret = app_env('LINE_CHANNEL_SECRET');
    if ($token === '') {
        $token = $data['channelAccessToken'];
    }
    if ($secret === '') {
        $secret = $data['channelSecret'];
    }
    return [
        'channelAccessToken' => $token,
        'channelSecret' => $secret,
    ];
}

function line_save_secret($token, $secret) {
    $current = line_secret_from_file();
    $token = trim((string) $token);
    $secret = trim((string) $secret);
    if ($token !== '' && $token !== '********') {
        $current['channelAccessToken'] = $token;
    }
    if ($secret !== '' && $secret !== '********') {
        $current['channelSecret'] = $secret;
    }
    $php = "<?php\nreturn " . var_export($current, true) . ";\n";
    return @file_put_contents(__DIR__ . '/line-secret.php', $php, LOCK_EX) !== false;
}

function line_with_user_lock($callback) {
    static $held = false;
    if ($held) {
        return $callback();
    }
    $fp = fopen(line_data_dir() . '/line-users.lock', 'c');
    if ($fp === false) {
        return $callback();
    }
    flock($fp, LOCK_EX);
    $held = true;
    try {
        return $callback();
    } finally {
        $held = false;
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function line_decode_user_file($file) {
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    $clean = [];
    foreach ($json as $id => $user) {
        if (!is_array($user)) {
            continue;
        }
        $clean[(string) $id] = $user;
    }
    return $clean;
}

function line_users_from_disk() {
    $file = line_data_dir() . '/line-users.json';
    $bak = $file . '.bak';
    if (!is_file($file)) {
        $restored = line_decode_user_file($bak);
        if (is_array($restored) && count($restored) > 0) {
            line_write_json($file, $restored);
            return $restored;
        }
        return [];
    }
    $decoded = line_decode_user_file($file);
    if (is_array($decoded)) {
        return $decoded;
    }
    $restored = line_decode_user_file($bak);
    if (is_array($restored)) {
        line_write_json($file, $restored);
        return $restored;
    }
    return null;
}

function line_users() {
    $users = line_with_user_lock(function () {
        return line_users_from_disk();
    });
    return is_array($users) ? $users : [];
}

function line_users_status() {
    return line_with_user_lock(function () {
        $users = line_users_from_disk();
        if (!is_array($users)) {
            return ['ok' => false, 'users' => []];
        }
        return ['ok' => true, 'users' => $users];
    });
}

function line_write_users_file($users) {
    $file = line_data_dir() . '/line-users.json';
    $current = line_decode_user_file($file);
    if (is_array($current) && count($current) > 0) {
        line_write_json($file . '.bak', $current);
    }
    return line_write_json($file, $users);
}

function line_save_users($users) {
    if (!is_array($users)) {
        return false;
    }
    return line_with_user_lock(function () use ($users) {
        $disk = line_users_from_disk();
        if (!is_array($disk)) {
            $file = line_data_dir() . '/line-users.json';
            if (count($users) === 0 || (is_file($file) && (int) @filesize($file) > 2)) {
                return false;
            }
            return line_write_users_file($users);
        }
        foreach ($users as $id => $user) {
            if (is_array($user)) {
                $disk[(string) $id] = $user;
            }
        }
        return line_write_users_file($disk);
    });
}

function line_remove_user($userId) {
    $userId = (string) $userId;
    if ($userId === '') {
        return false;
    }
    return line_with_user_lock(function () use ($userId) {
        $users = line_users_from_disk();
        if (!is_array($users)) {
            return false;
        }
        unset($users[$userId]);
        return line_write_users_file($users);
    });
}

function line_budget() {
    $file = line_data_dir() . '/line-push.json';
    $month = date('Y-m');
    $data = line_read_json($file, []);
    if (!is_array($data) || ($data['month'] ?? '') !== $month) {
        $data = ['month' => $month, 'sent' => 0, 'days' => [], 'lastError' => ''];
    }
    if (!isset($data['days']) || !is_array($data['days'])) {
        $data['days'] = [];
    }
    $data['sent'] = (int) ($data['sent'] ?? 0);
    return $data;
}

function line_save_budget($data) {
    return line_write_json(line_data_dir() . '/line-push.json', $data);
}

function line_site_base() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return '';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    $dir = rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

function line_like_words() {
    return [
        'ごはん' => ['ごはん', 'ご飯', '白米'],
        'カレー' => ['カレー'],
        '丼' => ['丼', 'どん'],
        'どんぶり' => ['丼', 'どん', 'カツ丼', '親子'],
        'チャーハン' => ['チャーハン', '炒飯', 'やきめし'],
        'パン' => ['パン'],
        '麺' => ['麺', 'ラーメン', 'うどん', 'そば', 'パスタ'],
        'ラーメン' => ['ラーメン'],
        'うどん' => ['うどん'],
        'そば' => ['そば', '蕎麦'],
        'パスタ' => ['パスタ'],
        '和食' => ['煮', '味噌', '焼き魚', '肉じゃが', '生姜', '定食', '煮付け'],
        '肉' => ['肉', '豚', '牛', '鶏', 'チキン', 'ハンバーグ', '唐揚', 'から揚', 'からあげ', '生姜', 'カツ', '焼肉', 'ステーキ'],
        'から揚げ' => ['から揚', '唐揚', 'からあげ'],
        'とんかつ' => ['とんかつ', '豚カツ', 'ヒレカツ'],
        'ハンバーグ' => ['ハンバーグ'],
        '生姜焼き' => ['生姜', 'しょうが焼'],
        '焼肉' => ['焼肉', '焼き肉'],
        '鶏肉' => ['鶏', 'チキン'],
        '魚' => ['魚', 'サバ', 'さば', '鮭', 'イワシ', 'いわし', '刺身', '煮魚', '南蛮', 'あじ', 'アジ'],
        '焼き魚' => ['焼き魚', '塩焼', 'サバ', 'さば'],
        '刺身' => ['刺身'],
        '煮魚' => ['煮魚', '煮付け', '味噌煮'],
        'サバ' => ['サバ', 'さば'],
        '揚げ物' => ['揚', 'フライ', 'カツ', '唐揚', 'から揚', 'コロッケ', '磯部'],
        '野菜' => ['野菜', 'サラダ', '煮物', 'ひじき'],
        '卵' => ['卵', '玉子', 'たまご', 'オム'],
        'サラダ' => ['サラダ'],
        '汁物' => ['汁', 'スープ', 'みそ汁', '味噌汁'],
        '辛いもの' => ['辛', 'ラー油', 'キムチ', 'からい'],
    ];
}

function line_allergy_words() {
    return [
        '卵' => ['卵', '玉子', 'たまご'],
        '乳' => ['乳', 'チーズ', '牛乳', 'バター'],
        '小麦' => ['小麦', 'パン'],
        'えび' => ['えび', 'エビ', '海老'],
        'かに' => ['かに', 'カニ', '蟹'],
        'そば' => ['そば', '蕎麦'],
        '落花生' => ['落花生', 'ピーナッツ'],
        'くるみ' => ['くるみ', 'クルミ'],
    ];
}

function line_words_match($food, $label, $dict) {
    $keys = $dict[$label] ?? [$label];
    foreach ($keys as $word) {
        if ($word !== '' && mb_strpos($food, $word) !== false) {
            return true;
        }
    }
    return false;
}

function line_food_matches($food, $label) {
    return line_words_match($food, $label, line_like_words());
}

function line_allergy_matches($food, $label) {
    return line_words_match($food, $label, line_allergy_words());
}

function line_score_user($user, $food) {
    $score = 0;
    foreach (($user['likes'] ?? []) as $like) {
        if (line_food_matches($food, $like)) {
            $score += 3;
        }
    }
    foreach (($user['avoids'] ?? []) as $avoid) {
        if ($avoid !== '' && $avoid !== 'とくにない' && line_food_matches($food, $avoid)) {
            $score -= 5;
        }
    }
    foreach (($user['allergies'] ?? []) as $allergy) {
        if ($allergy !== '' && line_allergy_matches($food, $allergy)) {
            $score -= 8;
        }
    }
    $times = $user['timesPerWeek'] ?? '';
    if ($times === '週2〜3回') {
        $score += 1;
    } elseif ($times === 'ほぼ毎日') {
        $score += 2;
    }
    return $score;
}

function line_daily_food($date) {
    $rows = line_read_json(line_data_dir() . '/daily-menu.json', []);
    foreach ($rows as $row) {
        if (is_array($row) && ($row['date'] ?? '') === $date) {
            return (string) ($row['food'] ?? '');
        }
    }
    return '';
}

function line_holiday($date) {
    $rows = line_read_json(line_data_dir() . '/holidays.json', []);
    foreach ($rows as $row) {
        if (is_array($row) && ($row['date'] ?? '') === $date) {
            return (string) ($row['reason'] ?? '休み');
        }
    }
    return null;
}

function line_events() {
    $map = [];
    $rows = line_read_json(line_data_dir() . '/events.json', []);
    foreach ($rows as $row) {
        if (is_array($row) && !empty($row['date'])) {
            $map[$row['date']] = (string) ($row['message'] ?? '');
        }
    }
    return $map;
}

function line_menus() {
    $rows = line_read_json(line_data_dir() . '/menu.json', []);
    return is_array($rows) ? $rows : [];
}

function line_slots() {
    $times = line_read_json(line_data_dir() . '/reservation-times.json', []);
    if (!is_array($times) || empty($times['enabled'])) {
        return null;
    }
    $slots = $times['timeSlots'] ?? [];
    if (!is_array($slots) || count($slots) === 0) {
        return null;
    }
    $clean = [];
    foreach ($slots as $slot) {
        $start = trim((string) ($slot['startTime'] ?? ''));
        $end = trim((string) ($slot['endTime'] ?? ''));
        if ($start !== '' && $end !== '') {
            $clean[] = ['startTime' => $start, 'endTime' => $end];
        }
    }
    return count($clean) ? $clean : null;
}

function line_sub_minutes($hhmm, $minutes) {
    $parts = explode(':', $hhmm);
    $total = ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0)) - (int) $minutes;
    if ($total < 0) {
        $total = 0;
    }
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

function line_is_early_user($userId, $date = null) {
    $date = $date ?: date('Y-m-d');
    $budget = line_budget();
    $ids = $budget['days'][$date]['sentIds'] ?? [];
    return is_array($ids) && in_array($userId, $ids, true);
}

function line_can_reserve($userId) {
    $slots = line_slots();
    if ($slots === null) {
        return ['ok' => true, 'mode' => 'open', 'message' => ''];
    }
    $now = date('H:i');
    foreach ($slots as $slot) {
        if ($now >= $slot['startTime'] && $now <= $slot['endTime']) {
            return ['ok' => true, 'mode' => 'normal', 'message' => ''];
        }
    }
    $settings = line_settings();
    $early = !empty($settings['earlyEnabled']) && line_is_early_user($userId);
    if ($early) {
        foreach ($slots as $slot) {
            $earlyStart = line_sub_minutes($slot['startTime'], (int) $settings['earlyMinutes']);
            if ($now >= $earlyStart && $now < $slot['startTime']) {
                return ['ok' => true, 'mode' => 'early', 'message' => ''];
            }
        }
    }
    $next = $slots[0]['startTime'];
    foreach ($slots as $slot) {
        if ($slot['startTime'] > $now) {
            $next = $slot['startTime'];
            break;
        }
    }
    if ($early) {
        $earlyStart = line_sub_minutes($next, (int) $settings['earlyMinutes']);
        return [
            'ok' => false,
            'mode' => 'wait',
            'message' => 'まだ予約の時間の前だよ。' . $earlyStart . ' から予約できるよ。',
        ];
    }
    return [
        'ok' => false,
        'mode' => 'wait',
        'message' => 'まだ予約の時間の前だよ。予約できるのは ' . $next . ' からだよ。',
    ];
}

function line_hours_text() {
    $slots = line_slots();
    if ($slots === null) {
        return '予約の時間は、いま制限なしだよ。';
    }
    $parts = [];
    foreach ($slots as $slot) {
        $parts[] = $slot['startTime'] . '〜' . $slot['endTime'];
    }
    return '予約できる時間は ' . implode('、', $parts) . ' だよ。';
}

function line_quick($items) {
    $out = [];
    foreach ($items as $item) {
        $out[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => line_cut($item[0], 20),
                'data' => $item[1],
                'displayText' => line_cut($item[2] ?? $item[0], 20),
            ],
        ];
    }
    return ['items' => $out];
}

function line_home_quick() {
    return line_quick([
        ['きょうの定食', 'home:today', 'きょうの定食'],
        ['予約する', 'home:reserve', '予約する'],
        ['今週の定食', 'home:week', '今週の定食'],
        ['予約を確認', 'home:check', '予約を確認'],
        ['質問する', 'home:ask', '質問する'],
        ['答えを直す', 'home:redo', '答えを直す'],
    ]);
}

function line_text($text, $quick = null) {
    $message = ['type' => 'text', 'text' => line_cut($text, 4900)];
    if ($quick) {
        $message['quickReply'] = $quick;
    }
    return $message;
}

function line_flex_message($alt, $bubble) {
    return [
        'type' => 'flex',
        'altText' => line_cut($alt, 400),
        'contents' => $bubble,
    ];
}

function line_postback_button($label, $data, $primary = false) {
    $shown = line_cut($label, 20);
    $button = [
        'type' => 'button',
        'style' => $primary ? 'primary' : 'secondary',
        'height' => 'md',
        'flex' => 1,
        'action' => [
            'type' => 'postback',
            'label' => $shown,
            'data' => $data,
            'displayText' => $shown,
        ],
    ];
    if ($primary) {
        $button['color'] = '#06C755';
    }
    return $button;
}

function line_button_rows($buttons, $columns) {
    if (!count($buttons)) {
        return [];
    }
    if ($columns < 2) {
        return [[
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => $buttons,
        ]];
    }
    $rows = [];
    $chunk = [];
    foreach ($buttons as $button) {
        $chunk[] = $button;
        if (count($chunk) === $columns) {
            $rows[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => $chunk,
            ];
            $chunk = [];
        }
    }
    if (count($chunk)) {
        $chunk[] = ['type' => 'filler', 'flex' => 1];
        $rows[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'contents' => $chunk,
        ];
    }
    return $rows;
}

function line_survey_card($stepLabel, $title, $note, $buttons, $columns, $leadingButtons = [], $footerButton = null) {
    $contents = [[
        'type' => 'text',
        'text' => $note,
        'size' => 'sm',
        'color' => '#555555',
        'wrap' => true,
    ]];
    foreach ($leadingButtons as $button) {
        $contents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [$button],
        ];
    }
    foreach (line_button_rows($buttons, $columns) as $row) {
        $contents[] = $row;
    }
    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#FFE3C2',
            'paddingTop' => '14px',
            'paddingBottom' => '14px',
            'paddingStart' => '16px',
            'paddingEnd' => '16px',
            'contents' => [
                ['type' => 'text', 'text' => $stepLabel, 'size' => 'sm', 'color' => '#9A4B12'],
                ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'xl', 'color' => '#3D2914', 'wrap' => true],
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'md',
            'contents' => $contents,
        ],
    ];
    if ($footerButton) {
        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [$footerButton],
        ];
    }
    return line_flex_message($stepLabel . ' ' . $title, $bubble);
}

function line_home_menu($title, $note) {
    return line_survey_card('メニュー', $title, $note, [
        line_postback_button('予約する', 'home:reserve', true),
        line_postback_button('予約を確認', 'home:check', false),
        line_postback_button('きょうの定食', 'home:today', false),
        line_postback_button('今週の定食', 'home:week', false),
        line_postback_button('質問する', 'home:ask', false),
        line_postback_button('答えを直す', 'home:redo', false),
    ], 2);
}

function line_attach_home($messages, $user) {
    if (line_rich_menu_ready() || ($user['step'] ?? '') !== 'done' || count($messages) === 0) {
        return $messages;
    }
    foreach ($messages as $message) {
        if (($message['type'] ?? '') === 'flex') {
            return $messages;
        }
    }
    $messages[] = line_home_menu('メニュー', '色のついたボタンから選んでね。');
    return $messages;
}

function line_calendar_url($user) {
    $base = line_site_base();
    $key = is_array($user) ? (string) ($user['viewKey'] ?? '') : '';
    if ($base === '' || $key === '') {
        return '';
    }
    return $base . '/line.html?key=' . rawurlencode($key);
}

function line_uri_button($label, $url) {
    return [
        'type' => 'button',
        'style' => 'primary',
        'color' => '#14B8A6',
        'height' => 'md',
        'action' => [
            'type' => 'uri',
            'label' => line_cut($label, 20),
            'uri' => $url,
        ],
    ];
}

function line_rich_menu_log($stage, $detail = '') {
    // Keep rich-menu diagnostics separate from the messaging budget's lastError.
    $file = line_data_dir() . '/rich-menu-debug.json';
    $rows = line_read_json($file, []);
    if (!is_array($rows)) $rows = [];
    $rows[] = [
        'time' => date('c'),
        'stage' => (string) $stage,
        'detail' => line_cut((string) $detail, 500),
    ];
    line_write_json($file, array_slice($rows, -30));
}

function line_menu_version() {
    return 'v6';
}

function line_rich_menu_ready() {
    $saved = line_read_json(line_data_dir() . '/rich-menu.json', []);
    return is_array($saved) && ($saved['version'] ?? '') === line_menu_version() && ($saved['id'] ?? '') !== '' && !empty($saved['defaultCleared']);
}

function line_clear_default_menu() {
    $res = line_http('https://api.line.me/v2/bot/user/all/richmenu', 'DELETE', null, '');
    return $res[0] || (int) ($res[2] ?? 0) === 404;
}

function line_remember_menu($id) {
    line_write_json(line_data_dir() . '/rich-menu.json', [
        'version' => line_menu_version(),
        'id' => $id,
        'defaultCleared' => line_clear_default_menu(),
    ]);
}

function line_survey_finished($user) {
    return in_array((string) ($user['step'] ?? ''), ['done', 'pick', 'confirm'], true);
}

function line_sync_user_menu($user) {
    $saved = line_read_json(line_data_dir() . '/rich-menu.json', []);
    $menuId = is_array($saved) ? (string) ($saved['id'] ?? '') : '';
    $userId = (string) ($user['userId'] ?? '');
    if ($menuId === '' || $userId === '' || ($saved['version'] ?? '') !== line_menu_version()) {
        line_rich_menu_log('link_skipped', 'menuId=' . ($menuId !== '' ? 'present' : 'missing') . ', userId=' . ($userId !== '' ? 'present' : 'missing') . ', version=' . (string) ($saved['version'] ?? ''));
        return;
    }
    $want = line_survey_finished($user);
    if (!empty($user['menuOn']) === $want && ($user['menuVersion'] ?? '') === line_menu_version()) {
        return;
    }
    $base = 'https://api.line.me/v2/bot/user/' . rawurlencode($userId) . '/richmenu';
    if ($want) {
        $res = line_http($base . '/' . rawurlencode($menuId), 'POST', '{}', 'application/json');
        if (!$res[0]) {
            line_rich_menu_log('link_failed', 'HTTP ' . $res[2] . ' ' . $res[1]);
            return;
        }
        line_rich_menu_log('link_ok', 'HTTP ' . $res[2]);
    } else {
        $res = line_http($base, 'DELETE', null, '');
        if (!$res[0] && (int) ($res[2] ?? 0) !== 404) {
            return;
        }
    }
    $users = line_users();
    if (!isset($users[$userId]) || !is_array($users[$userId])) {
        return;
    }
    $users[$userId]['menuOn'] = $want;
    $users[$userId]['menuVersion'] = line_menu_version();
    line_save_users($users);
}

function line_http($url, $method, $body, $contentType) {
    $token = line_secret()['channelAccessToken'];
    if ($token === '' || !function_exists('curl_init')) {
        return [false, 'トークンが未設定です', 0];
    }
    $headers = ['Authorization: Bearer ' . $token];
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false || $code === 0) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    curl_close($ch);
    return [$code >= 200 && $code < 300, (string) $res, $code];
}

function line_ensure_rich_menu() {
    $saved = line_read_json(line_data_dir() . '/rich-menu.json', []);
    if (line_rich_menu_ready()) {
        return;
    }
    if (line_secret()['channelAccessToken'] === '') {
        line_rich_menu_log('token_missing');
        return;
    }
    if (is_array($saved) && (int) ($saved['retryAfter'] ?? 0) > time()) {
        line_rich_menu_log('retry_wait', date('c', (int) $saved['retryAfter']));
        return;
    }
    $list = line_http('https://api.line.me/v2/bot/richmenu/list', 'GET', null, '');
    if (!$list[0]) line_rich_menu_log('list_failed', 'HTTP ' . $list[2] . ' ' . $list[1]);
    $existing = '';
    if ($list[0]) {
        $decoded = json_decode($list[1], true);
        foreach (($decoded['richmenus'] ?? []) as $menu) {
            if (is_array($menu) && ($menu['name'] ?? '') === 'cafeteria-v6' && !empty($menu['richMenuId'])) {
                $existing = (string) $menu['richMenuId'];
                break;
            }
        }
    }
    if ($existing !== '') {
        line_rich_menu_log('existing_found', 'cafeteria-v6');
        line_remember_menu($existing);
        return;
    }
    $image = __DIR__ . '/../images/rich-menu.png';
    if (!is_file($image)) {
        line_rich_menu_log('image_missing', $image);
        return;
    }
    $padX = 18;
    $gapX = 14;
    $padY = 16;
    $gapY = 11;
    $cellW = 812;
    $cellH = 400;
    $actions = [
        ['home:reserve', '予約する'],
        ['home:check', '予約を確認'],
        ['home:today', 'きょうの定食'],
        ['home:calendar', 'カレンダー'],
        ['home:ask', '質問する'],
        ['home:redo', '答えを直す'],
    ];
    $areas = [];
    foreach ($actions as $i => $action) {
        $col = $i % 3;
        $row = intdiv($i, 3);
        $areas[] = [
            'bounds' => [
                'x' => $padX + $col * ($cellW + $gapX),
                'y' => $padY + $row * ($cellH + $gapY),
                'width' => $cellW,
                'height' => $cellH,
            ],
            'action' => [
                'type' => 'postback',
                'data' => $action[0],
                'displayText' => $action[1],
            ],
        ];
    }
    $created = line_api('https://api.line.me/v2/bot/richmenu', [
        'size' => ['width' => 2500, 'height' => 843],
        'selected' => false,
        'name' => 'cafeteria-v6',
        'chatBarText' => 'メニュー',
        'areas' => $areas,
    ]);
    if (!$created[0]) {
        line_rich_menu_log('create_failed', 'HTTP ' . $created[2] . ' ' . $created[1]);
        line_write_json(line_data_dir() . '/rich-menu.json', ['version' => '', 'id' => '', 'retryAfter' => time() + 600]);
        return;
    }
    $meta = json_decode($created[1], true);
    $id = is_array($meta) ? (string) ($meta['richMenuId'] ?? '') : '';
    if ($id === '') {
        line_rich_menu_log('create_no_id', $created[1]);
        return;
    }
    $bin = file_get_contents($image);
    $uploaded = line_http('https://api-data.line.me/v2/bot/richmenu/' . rawurlencode($id) . '/content', 'POST', $bin, 'image/png');
    if (!$uploaded[0]) {
        line_rich_menu_log('upload_failed', 'HTTP ' . $uploaded[2] . ' ' . $uploaded[1] . ', bytes=' . strlen((string) $bin));
        line_http('https://api.line.me/v2/bot/richmenu/' . rawurlencode($id), 'DELETE', null, '');
        line_write_json(line_data_dir() . '/rich-menu.json', ['version' => '', 'id' => '', 'retryAfter' => time() + 600]);
        return;
    }
    line_rich_menu_log('upload_ok', 'bytes=' . strlen((string) $bin));
    line_remember_menu($id);
}

function line_api($url, $payload) {
    $token = line_secret()['channelAccessToken'];
    if ($token === '') {
        return [false, 'トークンが未設定です', 0];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = "Content-Type: application/json\r\nAuthorization: Bearer " . $token . "\r\n";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false || $code === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        curl_close($ch);
        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
            $budget = line_budget();
            $budget['lastError'] = line_cut('LINE API ' . $code . ' ' . (string) $res, 300);
            line_save_budget($budget);
        }
        return [$ok, (string) $res, $code];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $body,
            'timeout' => 12,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($res === false || $code === 0) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $res = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    }
    $ok = $code >= 200 && $code < 300;
    if (!$ok) {
        $budget = line_budget();
        $budget['lastError'] = line_cut('LINE API ' . $code . ' ' . (string) $res, 300);
        line_save_budget($budget);
    }
    return [$ok, (string) $res, $code];
}

function line_deliver($replyToken, $to, $messages, $allowPush) {
    $messages = array_slice(array_values($messages), 0, 5);
    if (!count($messages)) {
        return [false, 'empty', 0];
    }
    $result = [false, 'reply token empty', 0];
    if ($replyToken !== '') {
        $result = line_reply($replyToken, $messages);
        if ($result[0]) {
            $budget = line_budget();
            $budget['lastError'] = '';
            line_save_budget($budget);
            return $result;
        }
    }
    if ($allowPush && $to !== '') {
        return line_push_one($to, $messages);
    }
    return $result;
}

function line_reply($token, $messages) {
    if (!$token) {
        return [false, 'reply token empty', 0];
    }
    return line_api('https://api.line.me/v2/bot/message/reply', [
        'replyToken' => $token,
        'messages' => array_slice($messages, 0, 5),
    ]);
}

function line_push_notice($to, $messages, $plain) {
    $pushed = line_push_one($to, $messages);
    if (!empty($pushed[0]) || (int) ($pushed[2] ?? 0) !== 400) {
        return $pushed;
    }
    return line_push_one($to, [line_text($plain)]);
}

function line_push_one($to, $messages) {
    $settings = line_settings();
    $budget = line_budget();
    if ($budget['sent'] >= (int) $settings['monthlyLimit']) {
        return [false, '今月の200通に達しました', 0];
    }
    $result = line_api('https://api.line.me/v2/bot/message/push', [
        'to' => $to,
        'messages' => array_slice($messages, 0, 5),
    ]);
    if ($result[0]) {
        $budget['sent']++;
        $budget['lastError'] = '';
        line_save_budget($budget);
    } else {
        $budget['lastError'] = line_cut($result[1], 300);
        line_save_budget($budget);
    }
    return $result;
}

function line_header($name) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return trim((string) $value);
            }
        }
    }
    return '';
}

function line_verify_signature($body) {
    $secret = line_secret()['channelSecret'];
    if ($secret === '') {
        return false;
    }
    $header = line_header('X-Line-Signature');
    if ($header === '') {
        return false;
    }
    $hash = base64_encode(hash_hmac('sha256', $body, $secret, true));
    return hash_equals($hash, $header);
}

function line_ensure_sales() {
    if (!defined('SALES_DATA_INTERNAL')) {
        define('SALES_DATA_INTERNAL', true);
    }
    require_once __DIR__ . '/sales-data.php';
}

function line_with_lock($callback) {
    $fp = fopen(line_data_dir() . '/line.lock', 'c');
    if ($fp === false) {
        return $callback();
    }
    flock($fp, LOCK_EX);
    try {
        return $callback();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function line_create_reservation($name, $food) {
    try {
        return line_with_lock(function () use ($name, $food) {
        line_ensure_sales();
        $today = date('Y-m-d');
        $holiday = line_holiday($today);
        if ($holiday !== null) {
            return ['ok' => false, 'message' => 'きょうは休みだよ。理由: ' . $holiday];
        }
        $menus = line_menus();
        $index = null;
        foreach ($menus as $i => $menu) {
            if (($menu['name'] ?? '') === $food) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return ['ok' => false, 'message' => 'そのメニューはいま出せないよ。別のものを選んでね。'];
        }
        $stock = $menus[$index]['stock'] ?? 0;
        if ((int) $stock !== -1 && (int) $stock <= 0) {
            return ['ok' => false, 'message' => 'そのメニューは売り切れだよ。'];
        }
        $existing = getAllReservations();
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['date'] ?? '') === $today && trim((string) ($row['name'] ?? '')) === $name) {
                $num = sprintf('%03d', (int) ($row['reservationNumber'] ?? 0));
                return ['ok' => false, 'message' => 'きょうはもう予約してあるよ。番号は ' . $num . ' だよ。'];
            }
        }
        $used = [];
        foreach ($existing as $row) {
            if (!is_array($row) || ($row['date'] ?? '') !== $today) {
                continue;
            }
            $used[(int) ($row['reservationNumber'] ?? 0)] = true;
        }
        $number = null;
        for ($n = 1; $n <= 999; $n++) {
            if (!isset($used[$n])) {
                $number = $n;
                break;
            }
        }
        if ($number === null) {
            return ['ok' => false, 'message' => 'きょうの予約がいっぱいだよ。食堂の人に聞いてね。'];
        }
        if ((int) $stock !== -1) {
            $menus[$index]['stock'] = (int) $stock - 1;
            line_write_json(line_data_dir() . '/menu.json', $menus);
        }
        $reservation = [
            'id' => (int) round(microtime(true) * 1000),
            'date' => $today,
            'time' => date('H:i'),
            'name' => $name,
            'studentClass' => '',
            'people' => 1,
            'food' => $food,
            'reservationNumber' => $number,
            'source' => 'line',
            'verified' => false,
        ];
        if (!addReservationDetail($reservation)) {
            return ['ok' => false, 'message' => '予約を保存できなかったよ。もう一度押してね。'];
        }
        return ['ok' => true, 'number' => $number, 'food' => $food, 'name' => $name];
        });
    } catch (Throwable $e) {
        $budget = line_budget();
        $budget['lastError'] = line_cut($e->getMessage(), 300);
        line_save_budget($budget);
        return ['ok' => false, 'message' => '予約を保存できなかったよ。もう一度押してね。'];
    }
}

function line_find_reservation($name, $date) {
    line_ensure_sales();
    foreach (getAllReservations() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['date'] ?? '') === $date && trim((string) ($row['name'] ?? '')) === $name) {
            return $row;
        }
    }
    return null;
}

function line_user_by_key($key) {
    if ($key === '') {
        return null;
    }
    foreach (line_users() as $user) {
        if (is_array($user) && isset($user['viewKey']) && hash_equals((string) $user['viewKey'], $key)) {
            return $user;
        }
    }
    return null;
}

function line_calendar($user) {
    $today = date('Y-m-d');
    $year = (int) date('Y');
    $month = (int) date('n');
    $last = (int) date('t');
    $events = line_events();
    $days = [];
    for ($day = 1; $day <= $last; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $food = line_daily_food($date);
        $holiday = line_holiday($date);
        $liked = false;
        if ($user && $food !== '' && $holiday === null) {
            $liked = line_score_user($user, $food) > 0;
        }
        $days[] = [
            'date' => $date,
            'day' => $day,
            'food' => $food,
            'holiday' => $holiday,
            'event' => $events[$date] ?? '',
            'liked' => $liked,
            'today' => $date === $today,
        ];
    }
    $name = '';
    if (is_array($user) && isset($user['name'])) {
        $name = (string) $user['name'];
    }
    return [
        'year' => $year,
        'month' => $month,
        'name' => $name,
        'days' => $days,
    ];
}

function line_week_text($user) {
    $lines = [];
    $start = new DateTime('today');
    for ($i = 0; $i < 7; $i++) {
        $date = $start->format('Y-m-d');
        $label = $start->format('n/j');
        $holiday = line_holiday($date);
        if ($holiday !== null) {
            $lines[] = $label . '　休み（' . $holiday . '）';
        } else {
            $food = line_daily_food($date);
            if ($food === '') {
                $lines[] = $label . '　定食はまだないよ';
            } else {
                $mark = ($user && line_score_user($user, $food) > 0) ? '　★好きそう' : '';
                $lines[] = $label . '　' . $food . $mark;
            }
        }
        $start->modify('+1 day');
    }
    return "これからの定食だよ。\n★は、好きそうな日だよ。\n\n" . implode("\n", $lines);
}

function line_calendar_message($user) {
    $note = line_week_text($user);
    $url = line_calendar_url($user);
    if ($url === '') {
        return [line_text($note)];
    }
    return [line_survey_card('カレンダー', 'これからの定食', $note, [], 1, [], line_uri_button('カレンダーを開く', $url))];
}

function line_today_text($user) {
    $today = date('Y-m-d');
    $holiday = line_holiday($today);
    if ($holiday !== null) {
        return 'きょうは休みだよ。理由: ' . $holiday;
    }
    $food = line_daily_food($today);
    $text = $food !== '' ? 'きょうの定食は「' . $food . '」だよ。' : 'きょうの定食は、まだ決まっていないよ。';
    if ($user && $food !== '' && line_score_user($user, $food) > 0) {
        $text .= "\n★ 好きそうだよ。";
    }
    return $text . "\n" . line_hours_text() . "\n予約は、下のメニューの緑「予約する」から。";
}

function line_allergy_text($user = null) {
    $data = line_read_json(line_data_dir() . '/allergies.json', []);
    $rows = $data['allergies'] ?? [];
    $todayFood = line_daily_food(date('Y-m-d'));
    $hits = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $menu = (string) ($row['menu'] ?? '');
        if ($todayFood !== '' && (mb_strpos($todayFood, $menu) !== false || mb_strpos($menu, $todayFood) !== false)) {
            $hits[] = $menu . ': ' . implode('、', $row['allergens'] ?? []);
        }
    }
    $mine = [];
    if (is_array($user)) {
        foreach (($user['allergies'] ?? []) as $allergy) {
            $allergy = trim((string) $allergy);
            if ($allergy !== '') {
                $mine[] = $allergy;
            }
        }
    }
    $text = count($hits)
        ? "きょう関係しそうなアレルギーだよ。\n" . implode("\n", $hits)
        : "きょうの定食のアレルギーは、ここにまだ入っていないよ。";
    if (count($mine)) {
        $text .= "\nあなたが登録したアレルギー: " . implode('、', $mine);
    }
    return $text . "\n心配なときは食堂の人に聞いてね。";
}

function line_answer($text, $user) {
    if (preg_match('/アレルギ/u', $text)) {
        return line_allergy_text($user);
    }
    if (preg_match('/予約(を)?確認|番号/u', $text)) {
        return line_check_text($user);
    }
    if (preg_match('/予約/u', $text)) {
        return null;
    }
    if (preg_match('/今週|カレンダー/u', $text)) {
        return line_week_text($user);
    }
    if (preg_match('/今日|きょう|定食|メニュー|売切|売り切/u', $text)) {
        return line_today_text($user);
    }
    if (preg_match('/休み|営業|開い/u', $text)) {
        $holiday = line_holiday(date('Y-m-d'));
        if ($holiday !== null) {
            return 'きょうは休みだよ。理由: ' . $holiday;
        }
        return "きょうは営業しているよ。\n" . line_hours_text();
    }
    if (preg_match('/時間|何時/u', $text)) {
        return line_hours_text();
    }
    if (preg_match('/混|こみ|込/u', $text)) {
        return "混みぐあいは、その日の定食と今までの人数から食堂のページでも見られるよ。\n早めに行きたい日は、予約しておくと安心だよ。";
    }
    return false;
}

function line_check_text($user) {
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        return '先にアンケートでフルネームを入れてね。';
    }
    $row = line_find_reservation($name, date('Y-m-d'));
    if (!$row) {
        return $name . 'さんの、きょうの予約はまだないよ。';
    }
    $num = sprintf('%03d', (int) ($row['reservationNumber'] ?? 0));
    return $name . "さんの予約だよ。\nメニュー: " . ($row['food'] ?? '') . "\n番号: " . $num;
}

function line_reserve_prompt($user) {
    $check = line_can_reserve($user['userId'] ?? '');
    if (!$check['ok']) {
        return [line_text($check['message'])];
    }
    $holiday = line_holiday(date('Y-m-d'));
    if ($holiday !== null) {
        return [line_text('きょうは休みだから、予約はできないよ。理由: ' . $holiday)];
    }
    $choices = [];
    foreach (line_menus() as $menu) {
        $stock = $menu['stock'] ?? 0;
        if ((int) $stock === -1 || (int) $stock > 0) {
            $choices[] = (string) ($menu['name'] ?? '');
        }
    }
    $choices = array_values(array_filter($choices));
    if (!count($choices)) {
        return [line_text('いま予約できるメニューがないよ。')];
    }
    $users = line_users();
    $id = $user['userId'];
    $users[$id]['choices'] = array_slice($choices, 0, 10);
    $users[$id]['step'] = 'pick';
    line_save_users($users);
    $bubbles = [];
    foreach (array_slice($choices, 0, 10) as $name) {
        $stockText = '残りの数は、選んだあとに確認できるよ。';
        foreach (line_menus() as $menu) {
            if (($menu['name'] ?? '') === $name) {
                $stock = $menu['stock'] ?? 0;
                $stockText = ((int) $stock === -1) ? '数の制限なし' : ('残り ' . (int) $stock);
            }
        }
        $bubbles[] = [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'md',
                'contents' => [
                    ['type' => 'text', 'text' => 'メニュー', 'size' => 'xs', 'color' => '#3A7D44'],
                    ['type' => 'text', 'text' => $name, 'weight' => 'bold', 'size' => 'xl', 'wrap' => true, 'color' => '#17301C'],
                    ['type' => 'text', 'text' => $stockText, 'size' => 'sm', 'color' => '#666666', 'wrap' => true],
                    line_postback_button('これを予約', 'pick:' . rawurlencode($name), true),
                ],
            ],
        ];
    }
    $lead = ($user['name'] ?? '') . 'さんの名前で取るよ。横に送って選んでね。';
    if ($check['mode'] === 'early') {
        $lead = "きょうは早めに予約できるよ。\n" . $lead;
    }
    return [
        line_text($lead),
        line_flex_message('メニューを選んでね', ['type' => 'carousel', 'contents' => $bubbles]),
    ];
}

function line_confirm_prompt($user) {
    $food = (string) ($user['pendingFood'] ?? '');
    $name = (string) ($user['name'] ?? '');
    return [line_survey_card('確認', 'この内容で予約する？', $name . "さん\n" . $food, [
        line_postback_button('予約を確定', 'ok:' . rawurlencode($food), true),
        line_postback_button('やめる', 'book:no', false),
    ], 1)];
}

function line_book_food($user, $food) {
    $food = trim($food);
    $name = trim((string) ($user['name'] ?? ''));
    $check = line_can_reserve($user['userId'] ?? '');
    if (!$check['ok']) {
        $user['step'] = 'done';
        line_store_user($user);
        return [line_text($check['message'])];
    }
    if ($name === '' || $food === '') {
        $user['step'] = 'done';
        line_store_user($user);
        return [line_text('もう一度、下のメニューの「予約する」から選んでね。')];
    }
    $made = line_create_reservation($name, $food);
    $user['step'] = 'done';
    $user['pendingFood'] = $made['ok'] ? '' : $food;
    line_store_user($user);
    if (!$made['ok']) {
        return [line_text($made['message'])];
    }
    $num = sprintf('%03d', (int) $made['number']);
    return [line_survey_card('予約できた', '番号 ' . $num, $name . "さん\n" . $food . "\n\n受け取るとき、名前とこの番号を言ってね。", [], 1)];
}

function line_pick_users($food, $limit) {
    $budget = line_budget();
    $recent = [];
    $dates = array_keys($budget['days']);
    sort($dates);
    foreach (array_slice($dates, -3) as $date) {
        foreach (($budget['days'][$date]['sentIds'] ?? []) as $id) {
            $recent[$id] = true;
        }
    }
    $ranked = [];
    foreach (line_users() as $id => $user) {
        if (!is_array($user) || ($user['step'] ?? '') !== 'done' || empty($user['active'])) {
            continue;
        }
        $score = 2 + line_score_user($user, $food);
        if (isset($recent[$id])) {
            $score -= 2;
        }
        if ($score <= 0) {
            continue;
        }
        $ranked[] = ['id' => $id, 'user' => $user, 'score' => $score];
    }
    usort($ranked, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($ranked, 0, $limit);
}

function line_send_early($ignoreHour) {
    $settings = line_settings();
    $today = date('Y-m-d');
    $result = [
        'ok' => true,
        'sent' => 0,
        'reason' => '',
        'monthSent' => line_budget()['sent'],
        'limit' => 200,
        'food' => '',
        'people' => [],
    ];
    if (empty($settings['earlyEnabled'])) {
        $result['reason'] = 'ラッキー予約はオフです。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    if (line_secret()['channelAccessToken'] === '') {
        $result['ok'] = false;
        $result['reason'] = 'LINEのトークンがまだありません。管理画面の「チャネルアクセストークン」を保存してください。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    $weekday = (int) date('N');
    if ($weekday >= 6) {
        $result['reason'] = '土日は送りません。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    $holiday = line_holiday($today);
    if ($holiday !== null) {
        $result['reason'] = 'きょうは休みなので送りません。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    if (!$ignoreHour && (int) date('G') < (int) $settings['sendHour']) {
        $result['reason'] = '送りは ' . (int) $settings['sendHour'] . ' 時からです。今すぐ試すときは「今すぐ送る」を押してください。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    $food = line_daily_food($today);
    $result['food'] = $food;
    if ($food === '') {
        $result['reason'] = 'きょうの定食がまだないので送りません。';
        $result['lastError'] = (string) (line_budget()['lastError'] ?? '');
        return $result;
    }
    return line_with_lock(function () use ($settings, $today, $food, $result, $ignoreHour) {
        $budget = line_budget();
        $day = $budget['days'][$today] ?? ['sentIds' => [], 'tries' => 0, 'closed' => false];
        $already = $day['sentIds'] ?? [];
        if (!empty($day['closed']) && count($already) > 0) {
            $result['reason'] = 'きょうはもう送っています。';
            $result['monthSent'] = $budget['sent'];
            $result['sent'] = count($already);
            $result['lastError'] = (string) ($budget['lastError'] ?? '');
            return $result;
        }
        if (!empty($day['closed']) && count($already) === 0) {
            $day['closed'] = false;
        }
        if ($ignoreHour && count($already) === 0) {
            $day['tries'] = 0;
        }
        if ((int) ($day['tries'] ?? 0) >= 3 && !$ignoreHour) {
            $day['closed'] = true;
            $budget['days'][$today] = $day;
            line_save_budget($budget);
            $result['ok'] = false;
            $result['reason'] = 'きょうは3回送ろうとして届きませんでした。トークンを確認して「今すぐ送る」を押してください。';
            $result['lastError'] = (string) ($budget['lastError'] ?? '');
            return $result;
        }
        $picked = line_pick_users($food, (int) $settings['earlyCount']);
        $base = line_site_base();
        $attempted = false;
        foreach ($picked as $row) {
            $budget = line_budget();
            if ($budget['sent'] >= 200) {
                $result['reason'] = '今月の200通に達したので、ここまでです。';
                break;
            }
            if (in_array($row['id'], $already, true)) {
                continue;
            }
            $name = $row['user']['name'] ?? 'みんな';
            $slots = line_slots();
            $now = date('H:i');
            $alreadyOpen = $slots === null;
            if (is_array($slots)) {
                foreach ($slots as $slot) {
                    if ($now >= $slot['startTime'] && $now <= $slot['endTime']) {
                        $alreadyOpen = true;
                    }
                }
            }
            $minutes = (int) $settings['earlyMinutes'];
            if ($alreadyOpen) {
                $text = $name . "さん、ラッキー予約だよ。\nきょうの定食は「" . $food . "」。\nいまから予約できるよ。「予約する」を押してね。";
            } else {
                $text = $name . "さん、ラッキー予約だよ。\nきょうの定食は「" . $food . "」。\nふつうより" . $minutes . "分早く予約できるよ。\n「予約する」を押してね。";
            }
            $key = $row['user']['viewKey'] ?? '';
            if ($key !== '' && $base !== '') {
                $text .= "\n\n星つきのカレンダー\n" . $base . '/line.html?key=' . rawurlencode($key);
            }
            $messages = [line_survey_card('ラッキー予約', $food, $text, [
                line_postback_button('予約する', 'home:reserve', true),
            ], 1)];
            $attempted = true;
            $pushed = line_push_notice($row['id'], $messages, $text);
            $budget = line_budget();
            if ($pushed[0]) {
                $already[] = $row['id'];
                $result['sent']++;
                $result['people'][] = ['name' => $name, 'score' => $row['score']];
            } else {
                $result['ok'] = false;
                $result['reason'] = 'LINEが受け取りませんでした。' . line_cut((string) ($pushed[1] ?? ''), 180);
                break;
            }
        }
        if (!$attempted && $result['reason'] === '') {
            $result['reason'] = count($picked) === 0
                ? 'アンケートが終わった人がいないか、きょうの定食が苦手・アレルギーに当たる人だけなので送りません。'
                : '選んだ人には、きょうもう送っています。';
        }
        if ($attempted && !$result['ok']) {
            $day['tries'] = (int) ($day['tries'] ?? 0) + 1;
        }
        $day['sentIds'] = array_values($already);
        $day['closed'] = $result['sent'] > 0 && $result['ok'];
        if (!$result['ok'] && (int) ($day['tries'] ?? 0) >= 3 && !$ignoreHour) {
            $day['closed'] = true;
        }
        $day['food'] = $food;
        $budget = line_budget();
        $budget['days'][$today] = $day;
        line_save_budget($budget);
        $result['monthSent'] = $budget['sent'];
        $result['lastError'] = (string) ($budget['lastError'] ?? '');
        return $result;
    });
}

function line_preview() {
    $today = date('Y-m-d');
    $food = line_daily_food($today);
    $settings = line_settings();
    $people = [];
    if ($food !== '') {
        foreach (line_pick_users($food, (int) $settings['earlyCount']) as $row) {
            $people[] = [
                'name' => $row['user']['name'] ?? '',
                'likes' => $row['user']['likes'] ?? [],
                'score' => $row['score'],
            ];
        }
    }
    $budget = line_budget();
    $secret = line_secret();
    $userStatus = line_users_status();
    return [
        'food' => $food,
        'people' => $people,
        'monthSent' => (int) $budget['sent'],
        'limit' => 200,
        'sentToday' => count($budget['days'][$today]['sentIds'] ?? []),
        'lastError' => (string) ($budget['lastError'] ?? ''),
        'hasToken' => $secret['channelAccessToken'] !== '',
        'hasSecret' => $secret['channelSecret'] !== '',
        'settings' => line_settings(),
        'users' => line_user_summaries($userStatus['users']),
        'usersReadable' => $userStatus['ok'],
        'registered' => count($userStatus['users']),
    ];
}

function line_user_summaries($users = null) {
    if ($users === null) {
        $users = line_users();
    }
    $list = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $list[] = [
            'name' => (string) ($user['name'] ?? ''),
            'likes' => $user['likes'] ?? [],
            'times' => (string) ($user['timesPerWeek'] ?? ''),
            'avoids' => $user['avoids'] ?? [],
            'allergies' => $user['allergies'] ?? [],
            'done' => ($user['step'] ?? '') === 'done',
            'active' => !empty($user['active']),
            'createdAt' => (string) ($user['createdAt'] ?? ''),
            'updatedAt' => (string) ($user['updatedAt'] ?? ''),
        ];
    }
    return $list;
}

function line_normalize_name($name) {
    $name = str_replace(["\r", "\n", "\t"], ' ', (string) $name);
    $name = preg_replace('/[\s\x{3000}]+/u', ' ', $name);
    return trim((string) $name);
}

function line_full_name_error($name) {
    $name = line_normalize_name($name);
    if ($name === '') {
        return '名前が空白だよ。姓と名を入れてね。例: 瀬尾 太郎';
    }
    if (mb_strlen($name) > 20) {
        return '名前は20文字以内のフルネームにしてね。例: 瀬尾 太郎';
    }
    $parts = preg_split('/[\s・･·]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        $parts = [];
    }
    $letterParts = 0;
    foreach ($parts as $part) {
        if (preg_match('/\p{L}/u', $part)) {
            $letterParts++;
        }
    }
    $letters = preg_replace('/[^\p{L}]/u', '', $name);
    $letterCount = mb_strlen((string) $letters);
    if ($letterCount === 0) {
        return '名前は文字で入れてね。例: 瀬尾 太郎';
    }
    if ($letterParts < 2 && $letterCount < 3) {
        return 'フルネームで入れてね。姓だけは送れないよ。例: 瀬尾 太郎';
    }
    return '';
}

function line_times_labels() {
    return ['ほとんど来ない', '週1回', '週2〜3回', 'ほぼ毎日'];
}

function line_like_groups() {
    return [
        ['title' => 'ごはん・主食', 'items' => ['ごはん', 'カレー', '丼', 'チャーハン', 'パン', '麺']],
        ['title' => '麺', 'items' => ['ラーメン', 'うどん', 'そば', 'パスタ']],
        ['title' => '肉', 'items' => ['から揚げ', 'とんかつ', 'ハンバーグ', '生姜焼き', '焼肉', '鶏肉']],
        ['title' => '魚', 'items' => ['焼き魚', '刺身', '煮魚', 'サバ']],
        ['title' => 'その他のおかず', 'items' => ['揚げ物', '野菜', '卵', 'サラダ', '汁物', '辛いもの']],
    ];
}

function line_clean_choice($label) {
    $label = line_normalize_name($label);
    $label = str_replace(['<', '>', '"', "'"], '', $label);
    if (preg_match_all('/./u', $label, $chars) && count($chars[0]) > 20) {
        $label = implode('', array_slice($chars[0], 0, 20));
    }
    if ($label === '' || $label === 'とくにない') {
        return '';
    }
    if (!preg_match('/\p{L}|\p{N}/u', $label)) {
        return '';
    }
    return $label;
}

function line_collect_choices($input, $limit) {
    $out = [];
    foreach ((array) $input as $label) {
        $label = line_clean_choice($label);
        if ($label === '' || in_array($label, $out, true)) {
            continue;
        }
        $out[] = $label;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function line_survey_url($user) {
    $base = line_site_base();
    $key = is_array($user) ? (string) ($user['viewKey'] ?? '') : '';
    if ($base === '' || $key === '') {
        return '';
    }
    return $base . '/survey.html?key=' . rawurlencode($key);
}

function line_is_survey_return_word($text) {
    return (bool) preg_match('/^(答えた|答えました|回答した|終わった|おわった|完了)$/u', trim((string) $text));
}

function line_survey_invite($user) {
    $name = trim((string) ($user['name'] ?? ''));
    $note = "アンケートはこのボタンのページで答えてね。\n名前は空白だと送れないよ。姓と名を入れてね。\n例: 瀬尾 太郎\n好きなもの、苦手、アレルギーはボタンで選べるよ。ないものは自分で書けるよ。\n送り終わったら、このトークに戻るだけで大丈夫。「答えた」と送らなくていいよ。";
    if ($name !== '') {
        $note = "いまの名前: " . $name . "\n" . $note;
    }
    $url = line_survey_url($user);
    if ($url === '') {
        return [line_text($note . "\nページのリンクが作れなかったよ。もう一度開き直してね。")];
    }
    return [line_survey_card('アンケート', 'ボタンで答える', $note, [], 1, [], line_uri_button('アンケートを開く', $url))];
}

function line_survey_ack_messages($user) {
    $name = (string) ($user['name'] ?? '');
    $likes = implode('、', is_array($user['likes'] ?? null) ? $user['likes'] : []);
    $avoids = is_array($user['avoids'] ?? null) ? $user['avoids'] : [];
    $allergies = is_array($user['allergies'] ?? null) ? $user['allergies'] : [];
    $avoidText = count($avoids) ? implode('、', $avoids) : 'とくにない';
    $allergyText = count($allergies) ? implode('、', $allergies) : 'なし';
    $times = (string) ($user['timesPerWeek'] ?? '');
    $when = (string) ($user['updatedAt'] ?? '');
    if ($when !== '') {
        $when = substr(str_replace('T', ' ', $when), 0, 16);
    }
    $note = ($name !== '' ? $name . "さん\n" : '')
        . '好き: ' . ($likes !== '' ? $likes : '未設定')
        . "\n来る回数: " . ($times !== '' ? $times : '未設定')
        . "\n苦手: " . $avoidText
        . "\nアレルギー: " . $allergyText
        . "\n\nこの答えは、日付が変わっても消えないよ。\n「答えた」と送らなくていいよ。";
    if ($when !== '') {
        $note .= "\n最後に保存したとき: " . $when;
    }
    $url = line_calendar_url($user);
    if ($url === '') {
        return [line_text('アンケートを保存したよ。' . "\n" . $note)];
    }
    return [line_survey_card('保存済み', $name !== '' ? $name . 'さん' : 'アンケート', $note, [], 1, [], line_uri_button('カレンダーを開く', $url))];
}

function line_with_ack($user, $messages) {
    if (($user['step'] ?? '') !== 'done' || empty($user['needsLineAck'])) {
        return $messages;
    }
    $fresh = line_users();
    $id = (string) ($user['userId'] ?? '');
    if ($id !== '' && isset($fresh[$id]) && is_array($fresh[$id])) {
        $fresh[$id]['needsLineAck'] = false;
        line_save_users($fresh);
        $user = $fresh[$id];
    }
    return array_merge(line_survey_ack_messages($user), $messages);
}

function line_survey_public($user) {
    return [
        'ok' => true,
        'name' => (string) ($user['name'] ?? ''),
        'likes' => array_values(is_array($user['likes'] ?? null) ? $user['likes'] : []),
        'avoids' => array_values(is_array($user['avoids'] ?? null) ? $user['avoids'] : []),
        'allergies' => array_values(is_array($user['allergies'] ?? null) ? $user['allergies'] : []),
        'timesPerWeek' => (string) ($user['timesPerWeek'] ?? ''),
        'done' => ($user['step'] ?? '') === 'done',
        'createdAt' => (string) ($user['createdAt'] ?? ''),
        'updatedAt' => (string) ($user['updatedAt'] ?? ''),
        'groups' => line_like_groups(),
        'timeChoices' => line_times_labels(),
        'avoidChoices' => line_avoid_labels(),
        'allergyChoices' => line_allergy_labels(),
        'addUrl' => (string) (line_settings()['addUrl'] ?? ''),
    ];
}

function line_apply_survey($user, $input) {
    if (!is_array($input)) {
        return [false, '送れなかったよ。もう一度押してね。', $user];
    }
    $name = line_normalize_name($input['name'] ?? '');
    $error = line_full_name_error($name);
    if ($error !== '') {
        return [false, $error, $user];
    }
    $likes = line_collect_choices($input['likes'] ?? [], 12);
    if (!count($likes)) {
        return [false, '好きな食べ方を、1つ以上選ぶか、自分で書いてね。', $user];
    }
    $times = (string) ($input['timesPerWeek'] ?? '');
    if (!in_array($times, line_times_labels(), true)) {
        return [false, '週に何回来るか、1つ押してね。', $user];
    }
    $avoids = line_collect_choices($input['avoids'] ?? [], 8);
    $allergies = line_collect_choices($input['allergies'] ?? [], 8);
    if (empty($user['createdAt'])) {
        $user['createdAt'] = date('c');
    }
    if (empty($user['viewKey'])) {
        $user['viewKey'] = bin2hex(random_bytes(8));
    }
    $user['name'] = $name;
    $user['likes'] = $likes;
    $user['timesPerWeek'] = $times;
    $user['avoids'] = $avoids;
    $user['allergies'] = $allergies;
    $user['updatedAt'] = date('c');
    $user['step'] = 'done';
    $user['active'] = true;
    $user['needsLineAck'] = false;
    $user['pendingFood'] = '';
    line_store_user($user);
    return [true, '', $user];
}

function line_avoid_labels() {
    return ['魚', '肉', 'からいもの', '野菜', '乳製品', '卵', 'きのこ', '納豆'];
}

function line_allergy_labels() {
    return ['卵', '乳', '小麦', 'えび', 'かに', 'そば', '落花生', 'くるみ'];
}

function line_start_survey($user) {
    $user['step'] = 'survey';
    if (empty($user['viewKey'])) {
        $user['viewKey'] = bin2hex(random_bytes(8));
    }
    line_store_user($user);
    return line_survey_invite($user);
}

function line_resume_prompt($user) {
    $step = $user['step'] ?? 'survey';
    if ($step === 'pick') {
        return line_reserve_prompt($user);
    }
    if ($step === 'confirm') {
        return line_confirm_prompt($user);
    }
    if ($step === 'done') {
        return [line_home_menu('メニュー', '色のついたボタンから選んでね。')];
    }
    return line_survey_invite($user);
}

function line_locked_prompt($user) {
    $messages = line_resume_prompt($user);
    array_unshift($messages, line_text('先にアンケートを終わらせてね。'));
    return $messages;
}

function line_handle_text($user, $text) {
    $text = trim($text);
    $step = $user['step'] ?? 'survey';
    if (in_array($step, ['', 'new', 'name', 'likes', 'times', 'avoid', 'survey'], true)) {
        $user['step'] = 'survey';
        line_store_user($user);
        return line_survey_invite($user);
    }
    if (!line_survey_finished($user)) {
        return line_survey_invite($user);
    }
    if ($step === 'pick' || $step === 'confirm') {
        return line_resume_prompt($user);
    }
    if (preg_match('/予約/u', $text) && !preg_match('/確認|番号/u', $text)) {
        return line_reserve_prompt($user);
    }
    $answer = line_answer($text, $user);
    if ($answer === null) {
        return [line_text(line_today_text($user))];
    }
    if ($answer === false) {
        return [line_text(line_ai_reply($text, $user))];
    }
    return [line_text($answer)];
}

function line_openai_settings() {
    static $openai = null;
    if (is_array($openai)) {
        return $openai;
    }
    $config = [];
    $path = __DIR__ . '/ollama-config.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }
    $config = apply_openai_env($config);
    $openai = $config['openai'] ?? [];
    if (!is_array($openai)) {
        $openai = [];
    }
    return $openai;
}

function line_crowd_hint() {
    $file = __DIR__ . '/crowd-predict.php';
    if (!is_file($file)) {
        return '混雑の人数は自分で計算しない。聞かれたら、食堂のページで見てね、と返す。';
    }
    require_once $file;
    if (!function_exists('crowd_prediction_payload')) {
        return '混雑の人数は自分で計算しない。聞かれたら、食堂のページで見てね、と返す。';
    }
    try {
        $payload = crowd_prediction_payload(date('Y-m-d'));
    } catch (Throwable $e) {
        return '混雑の人数は自分で計算しない。聞かれたら、食堂のページで見てね、と返す。';
    }
    if (!is_array($payload) || ($payload['predictionNumber'] ?? null) === null) {
        return '今日の混雑人数は出していない。聞かれたら食堂のページを見てね、と返す。人数は自分で計算しない。';
    }
    return '今日の来客見込みはサーバー計算で約' . $payload['predictionNumber'] . '人。この数字を変えない。自分で計算し直さない。';
}

function line_ai_reply($text, $user) {
    $fallback = "定食、予約、休み、アレルギー、時間は下のメニューから聞けるよ。\nいまはそれ以外の質問にうまく答えられないよ。";
    $openai = line_openai_settings();
    $key = trim((string) ($openai['api_key'] ?? ''));
    if ($key === '' || empty($openai['enabled'])) {
        return $fallback;
    }
    $today = date('Y-m-d');
    $food = line_daily_food($today);
    $holiday = line_holiday($today);
    $name = trim((string) ($user['name'] ?? ''));
    $allergies = [];
    foreach (($user['allergies'] ?? []) as $allergy) {
        $allergy = trim((string) $allergy);
        if ($allergy !== '') {
            $allergies[] = $allergy;
        }
    }
    $context = "今日: " . $today . "\n";
    if ($holiday !== null && $holiday !== '') {
        $context .= "今日は休み。理由: " . $holiday . "\n";
    } else {
        $context .= "今日の定食: " . ($food !== '' ? $food : '未定') . "\n";
    }
    $context .= line_hours_text() . "\n";
    $context .= line_crowd_hint() . "\n";
    if ($name !== '') {
        $context .= "相手の名前: " . $name . "\n";
    }
    if (count($allergies)) {
        $context .= "相手が登録したアレルギー: " . implode('、', $allergies) . "\n";
    }
    $system = "あなたは学校食堂OliMealの案内係です。生徒の質問に、短い日本語で答えてください。"
        . "食堂の定食、予約、休み、アレルギーのほか、学校生活の短い質問にも答えてよいです。"
        . "医務、個人情報、危険な頼みには答えず、先生に聞いてねと返してください。"
        . "混雑の人数は、渡された数字以外を作らないでください。"
        . "回答は300文字以内です。\n" . $context;
    $model = (string) ($openai['model'] ?? 'gpt-4o-mini');
    if ($model === '' || strpos($model, 'gpt-3.5') === 0) {
        $model = 'gpt-4o-mini';
    }
    $base = rtrim((string) ($openai['base_url'] ?? ''), '/');
    if ($base === '') {
        $base = 'https://api.openai.com/v1';
    }
    $body = json_encode([
        'model' => $model,
        'temperature' => 0.4,
        'max_tokens' => 350,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500)],
        ],
    ], JSON_UNESCAPED_UNICODE);
    $url = $base . '/chat/completions';
    $raw = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($raw === false || $code === 0) {
        $contextHttp = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $contextHttp);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    }
    if ($raw === false || $code < 200 || $code >= 300) {
        return $fallback;
    }
    $json = json_decode((string) $raw, true);
    $reply = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    if ($reply === '') {
        return $fallback;
    }
    return function_exists('mb_substr') ? mb_substr($reply, 0, 500) : substr($reply, 0, 500);
}

function line_store_user($user) {
    $users = line_users();
    $id = $user['userId'] ?? '';
    if ($id === '') {
        return;
    }
    $users[$id] = $user;
    line_save_users($users);
}

function line_load_user($userId, $displayName) {
    $loaded = line_with_user_lock(function () use ($userId, $displayName) {
        $users = line_users_from_disk();
        if (!is_array($users)) {
            return null;
        }
        if (!isset($users[$userId]) || !is_array($users[$userId])) {
            $users[$userId] = [
                'userId' => $userId,
                'displayName' => $displayName,
                'name' => '',
                'likes' => [],
                'avoids' => [],
                'allergies' => [],
                'timesPerWeek' => '',
                'step' => 'new',
                'active' => true,
                'viewKey' => bin2hex(random_bytes(8)),
                'createdAt' => date('c'),
                'updatedAt' => '',
            ];
        } else {
            $users[$userId]['active'] = true;
            if ($displayName !== '') {
                $users[$userId]['displayName'] = $displayName;
            }
            $users[$userId]['userId'] = $userId;
        }
        line_write_users_file($users);
        return $users[$userId];
    });
    if (is_array($loaded)) {
        return $loaded;
    }
    return [
        'userId' => $userId,
        'displayName' => $displayName,
        'name' => '',
        'likes' => [],
        'avoids' => [],
        'allergies' => [],
        'timesPerWeek' => '',
        'step' => 'new',
        'active' => true,
        'viewKey' => '',
        'createdAt' => date('c'),
        'updatedAt' => '',
    ];
}

function line_handle_postback($user, $data) {
    if (strpos($data, 'like:') === 0 || $data === 'likes:done' || strpos($data, 'times:') === 0 || strpos($data, 'avoid:') === 0) {
        return line_survey_invite($user);
    }
    if (!line_survey_finished($user)) {
        return line_locked_prompt($user);
    }
    if ($data === 'home:redo') {
        $saved = line_survey_ack_messages($user);
        $url = line_survey_url($user);
        if ($url === '') {
            return $saved;
        }
        $saved[] = line_text('直すときだけ、最初のアンケートのページを開いてね。予約や定食は、このトークのメニューのまま。');
        $saved[] = line_survey_card('メニュー', 'アンケートを直す', '名前や好きな食べ方を直すページだよ。', [], 1, [], line_uri_button('アンケートを直す', $url));
        return $saved;
    }
    if ($data === 'home:today') {
        return [line_text(line_today_text($user))];
    }
    if ($data === 'home:week' || $data === 'home:calendar') {
        return line_calendar_message($user);
    }
    if ($data === 'home:check') {
        return [line_text(line_check_text($user))];
    }
    if ($data === 'home:ask') {
        return [line_text("聞きたいことを、このまま送ってね。\n定食、予約、休み、アレルギー、時間が分かるよ。")];
    }
    if ($data === 'home:reserve') {
        return line_reserve_prompt($user);
    }
    if (strpos($data, 'ok:') === 0) {
        return line_book_food($user, rawurldecode(substr($data, 3)));
    }
    if (strpos($data, 'pick:') === 0) {
        $raw = substr($data, 5);
        $food = '';
        if ($raw !== '' && ctype_digit($raw)) {
            $choices = $user['choices'] ?? [];
            $food = (string) ($choices[(int) $raw] ?? '');
        } else {
            $food = rawurldecode($raw);
        }
        if ($food === '') {
            return line_reserve_prompt($user);
        }
        $user['pendingFood'] = $food;
        $user['step'] = 'confirm';
        line_store_user($user);
        return line_confirm_prompt($user);
    }
    if ($data === 'book:no') {
        $user['step'] = 'done';
        $user['pendingFood'] = '';
        line_store_user($user);
        return [line_text('予約はやめたよ。また下のメニューからどうぞ。')];
    }
    if ($data === 'book:yes') {
        return line_book_food($user, (string) ($user['pendingFood'] ?? ''));
    }
    return line_resume_prompt($user);
}

function line_handle_event($event) {
    $source = $event['source']['type'] ?? '';
    $userId = $event['source']['userId'] ?? '';
    $replyToken = $event['replyToken'] ?? '';
    $type = $event['type'] ?? '';
    if ($source !== 'user' || $userId === '') {
        if ($replyToken) {
            line_reply($replyToken, [line_text('個人のトークで使ってね。')]);
        }
        return;
    }
    try {
        line_ensure_rich_menu();
    } catch (Throwable $e) {
        $budget = line_budget();
        $budget['lastError'] = line_cut($e->getMessage(), 300);
        line_save_budget($budget);
    }
    if ($type === 'unfollow') {
        line_http('https://api.line.me/v2/bot/user/' . rawurlencode($userId) . '/richmenu', 'DELETE', null, '');
        line_remove_user($userId);
        return;
    }
    $user = line_load_user($userId, '');
    $messages = [];
    if ($type === 'follow') {
        $user['active'] = true;
        $user['menuOn'] = false;
        $kept = ($user['step'] ?? '') === 'done' && line_full_name_error((string) ($user['name'] ?? '')) === '';
        if ($kept) {
            $user['needsLineAck'] = false;
            line_store_user($user);
            $messages = line_survey_ack_messages($user);
        } else {
            $messages = line_start_survey($user);
        }
    } elseif ($type === 'message') {
        $msgType = $event['message']['type'] ?? '';
        if ($msgType === 'text') {
            $text = (string) ($event['message']['text'] ?? '');
            if (line_survey_finished($user) && line_is_survey_return_word($text)) {
                $user['needsLineAck'] = false;
                line_store_user($user);
                $messages = line_survey_ack_messages($user);
            } else {
                $messages = line_with_ack($user, line_handle_text($user, $text));
            }
        } else {
            $messages = line_with_ack($user, line_resume_prompt($user));
        }
    } elseif ($type === 'postback') {
        $loaded = line_load_user($userId, '');
        $messages = line_with_ack($loaded, line_handle_postback($loaded, (string) ($event['postback']['data'] ?? '')));
    }
    $fresh = line_load_user($userId, '');
    line_sync_user_menu($fresh);
    if (count($messages)) {
        $fresh = line_load_user($userId, '');
        line_deliver($replyToken, $userId, line_attach_home($messages, $fresh), $type === 'follow');
    }
}
