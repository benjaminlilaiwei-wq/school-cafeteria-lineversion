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
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return @file_put_contents($file, $json, LOCK_EX);
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

function line_users() {
    $users = line_read_json(line_data_dir() . '/line-users.json', []);
    return is_array($users) ? $users : [];
}

function line_save_users($users) {
    return line_write_json(line_data_dir() . '/line-users.json', $users);
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
        'カレー' => ['カレー'],
        '肉' => ['肉', '豚', '牛', '鶏', 'チキン', 'ハンバーグ', '唐揚', 'から揚', 'からあげ', '生姜', 'カツ', '焼肉', 'ステーキ'],
        '魚' => ['魚', 'サバ', 'さば', '鮭', 'イワシ', 'いわし', '刺身', '煮魚', '南蛮', 'あじ', 'アジ'],
        '麺' => ['麺', 'ラーメン', 'うどん', 'そば', 'パスタ'],
        '揚げ物' => ['揚', 'フライ', 'カツ', '唐揚', 'から揚', 'コロッケ', '磯部'],
        '和食' => ['煮', '味噌', '焼き魚', '肉じゃが', '生姜', '定食', '煮付け'],
        '野菜' => ['野菜', 'サラダ', '煮物', 'ひじき'],
        'どんぶり' => ['丼', 'どん', 'カツ丼', '親子'],
    ];
}

function line_food_matches($food, $label) {
    $words = line_like_words();
    $keys = $words[$label] ?? [$label];
    foreach ($keys as $word) {
        if ($word !== '' && mb_strpos($food, $word) !== false) {
            return true;
        }
    }
    return false;
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
        'height' => 'sm',
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
            'backgroundColor' => '#E8F8EE',
            'paddingTop' => '14px',
            'paddingBottom' => '14px',
            'paddingStart' => '16px',
            'paddingEnd' => '16px',
            'contents' => [
                ['type' => 'text', 'text' => $stepLabel, 'size' => 'xs', 'color' => '#3A7D44'],
                ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'xl', 'color' => '#17301C', 'wrap' => true],
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
    if (($user['step'] ?? '') !== 'done' || count($messages) === 0) {
        return $messages;
    }
    foreach ($messages as $message) {
        if (($message['type'] ?? '') === 'flex') {
            return $messages;
        }
    }
    $messages[] = line_home_menu('メニュー', 'カードのボタンから選んでね。');
    return $messages;
}

function line_labels_in_text($text, $labels) {
    $found = [];
    foreach ($labels as $label) {
        if ($label !== '' && mb_strpos($text, $label) !== false) {
            $found[] = $label;
        }
    }
    return $found;
}

function line_is_done_word($text) {
    return (bool) preg_match('/^(ok|okay|決定|完了|以上|おわり|終わり|これで)$/ui', trim($text));
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
            if (($row['date'] ?? '') === $today && trim((string) ($row['name'] ?? '')) === $name) {
                $num = sprintf('%03d', (int) ($row['reservationNumber'] ?? 0));
                return ['ok' => false, 'message' => 'きょうはもう予約してあるよ。番号は ' . $num . ' だよ。'];
            }
        }
        $used = [];
        foreach ($existing as $row) {
            if (($row['date'] ?? '') === $today) {
                $used[(int) ($row['reservationNumber'] ?? 0)] = true;
            }
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
}

function line_find_reservation($name, $date) {
    line_ensure_sales();
    foreach (getAllReservations() as $row) {
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
    $text = "これからの定食だよ。\n★は、アンケートから好きそうな日だよ。\n\n" . implode("\n", $lines);
    $key = $user['viewKey'] ?? '';
    $base = line_site_base();
    if ($key !== '' && $base !== '') {
        $text .= "\n\n星つきのカレンダー\n" . $base . '/line.html?key=' . rawurlencode($key);
    }
    return $text;
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
    $lines = [];
    foreach (line_menus() as $menu) {
        $name = (string) ($menu['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $stock = $menu['stock'] ?? 0;
        $stockText = ((int) $stock === -1) ? 'いつでも出せる' : ('残り' . (int) $stock);
        $lines[] = '・' . $name . '（' . $stockText . '）';
    }
    if (count($lines)) {
        $text .= "\n\nいま予約できるもの\n" . implode("\n", array_slice($lines, 0, 12));
    }
    $text .= "\n\n" . line_hours_text();
    return $text;
}

function line_allergy_text() {
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
    if (!count($hits)) {
        return "きょうの定食のアレルギーは、ここにまだ入っていないよ。\n心配なときは食堂の人に聞いてね。";
    }
    return "きょう関係しそうなアレルギーだよ。\n" . implode("\n", $hits) . "\n心配なときは食堂の人に聞いてね。";
}

function line_answer($text, $user) {
    if (preg_match('/アレルギ/u', $text)) {
        return line_allergy_text();
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
    return "定食、予約、休み、アレルギー、時間について聞けるよ。\n下のカードからも選べるよ。";
}

function line_check_text($user) {
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        return '先に名前を教えてね。';
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
    $users[$id]['choices'] = $choices;
    $users[$id]['step'] = 'pick';
    line_save_users($users);
    $buttons = [];
    foreach (array_slice($choices, 0, 10) as $i => $name) {
        $buttons[] = line_postback_button(line_cut($name, 20), 'pick:' . $i, false);
    }
    $lead = '名前「' . ($user['name'] ?? '') . '」で取るよ。';
    if ($check['mode'] === 'early') {
        $lead = "きょうは早めに予約できるよ。\n" . $lead;
    }
    return [line_survey_card('予約', 'どれにする？', $lead, $buttons, 1)];
}

function line_confirm_prompt($user) {
    $food = (string) ($user['pendingFood'] ?? '');
    $name = (string) ($user['name'] ?? '');
    return [line_survey_card('予約', $food !== '' ? $food : '確認', $name . 'さんの名前で予約する？', [
        line_postback_button('予約する', 'book:yes', true),
        line_postback_button('やめる', 'book:no', false),
    ], 1)];
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
        $score = line_score_user($user, $food);
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
        $result['reason'] = '早予約のお知らせはオフです。';
        return $result;
    }
    if (line_secret()['channelAccessToken'] === '') {
        $result['ok'] = false;
        $result['reason'] = 'LINEのトークンがまだありません。';
        return $result;
    }
    $weekday = (int) date('N');
    if ($weekday >= 6) {
        $result['reason'] = '土日は送りません。';
        return $result;
    }
    $holiday = line_holiday($today);
    if ($holiday !== null) {
        $result['reason'] = 'きょうは休みなので送りません。';
        return $result;
    }
    if (!$ignoreHour && (int) date('G') < (int) $settings['sendHour']) {
        $result['reason'] = '送りは ' . (int) $settings['sendHour'] . ' 時からです。';
        return $result;
    }
    $food = line_daily_food($today);
    $result['food'] = $food;
    if ($food === '') {
        $result['reason'] = 'きょうの定食がまだないので送りません。';
        return $result;
    }
    return line_with_lock(function () use ($settings, $today, $food, $result) {
        $budget = line_budget();
        $day = $budget['days'][$today] ?? ['sentIds' => [], 'tries' => 0, 'closed' => false];
        if (!empty($day['closed'])) {
            $result['reason'] = 'きょうはもう送っています。';
            $result['monthSent'] = $budget['sent'];
            $result['sent'] = count($day['sentIds']);
            return $result;
        }
        if ((int) ($day['tries'] ?? 0) >= 3) {
            $day['closed'] = true;
            $budget['days'][$today] = $day;
            line_save_budget($budget);
            $result['reason'] = 'きょうは送るのを途中でやめました。LINEの設定を確認してください。';
            return $result;
        }
        $day['tries'] = (int) ($day['tries'] ?? 0) + 1;
        $picked = line_pick_users($food, (int) $settings['earlyCount']);
        $already = $day['sentIds'] ?? [];
        $base = line_site_base();
        foreach ($picked as $row) {
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
            if ($alreadyOpen) {
                $text = $name . "さん、おはよう。\nきょうの定食は「" . $food . "」。\n好きそうだよ。「予約する」を押してね。";
            } else {
                $text = $name . "さん、おはよう。\nきょうの定食は「" . $food . "」。\n好きそうだから、ふつうの人より" . (int) $settings['earlyMinutes'] . "分早く予約できるよ。\n「予約する」を押してね。";
            }
            $key = $row['user']['viewKey'] ?? '';
            if ($key !== '' && $base !== '') {
                $text .= "\n\n星つきのカレンダー\n" . $base . '/line.html?key=' . rawurlencode($key);
            }
            $messages = [line_survey_card('おはよう', $food, $text, [
                line_postback_button('予約する', 'home:reserve', true),
            ], 1)];
            $pushed = line_push_one($row['id'], $messages);
            $budget = line_budget();
            if ($pushed[0]) {
                $already[] = $row['id'];
                $result['sent']++;
                $result['people'][] = ['name' => $name, 'score' => $row['score']];
            } else {
                $result['ok'] = false;
                $result['reason'] = 'LINEへ送れませんでした。トークンを確認してください。';
                break;
            }
        }
        if ($result['sent'] === 0 && $result['reason'] === '') {
            $result['reason'] = '好きそうな人がいないので、きょうは送りません。';
        }
        $stopForToday = $result['ok'] || (int) $day['tries'] >= 3;
        if (!$result['ok'] && $result['sent'] > 0) {
            $stopForToday = true;
        }
        $day['sentIds'] = $already;
        $day['closed'] = $stopForToday;
        $day['food'] = $food;
        $budget['days'][$today] = $day;
        line_save_budget($budget);
        $result['monthSent'] = $budget['sent'];
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
        'users' => line_user_summaries(),
        'registered' => count(line_user_summaries()),
    ];
}

function line_user_summaries() {
    $list = [];
    foreach (line_users() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $list[] = [
            'name' => (string) ($user['name'] ?? ''),
            'likes' => $user['likes'] ?? [],
            'times' => (string) ($user['timesPerWeek'] ?? ''),
            'avoids' => $user['avoids'] ?? [],
            'done' => ($user['step'] ?? '') === 'done',
            'active' => !empty($user['active']),
        ];
    }
    return $list;
}

function line_name_prompt() {
    return [line_survey_card('1 / 4', '名前', "予約に使う名前を、このトークに送ってね。\nひらがなでも漢字でも大丈夫。\n例: 田中", [], 1)];
}

function line_avoid_labels() {
    return ['魚', '肉', 'からいもの', '野菜', '乳製品'];
}

function line_start_survey($user) {
    $user['step'] = 'name';
    line_store_user($user);
    return line_name_prompt();
}

function line_likes_prompt($user) {
    $likes = $user['likes'] ?? [];
    $now = count($likes) ? ('選んでいるもの: ' . implode('、', $likes)) : 'まだ選んでいないよ';
    $buttons = [];
    foreach (array_keys(line_like_words()) as $label) {
        $on = in_array($label, $likes, true);
        $buttons[] = line_postback_button(($on ? '✓ ' : '') . $label, 'like:' . $label, $on);
    }
    return [line_survey_card(
        '2 / 4',
        '好きな食べ方',
        "緑が、いま選んでいるもの。いくつでも押せるよ。\n一度に答えるなら「肉 麺 カレー」と送ってね。\n" . $now,
        $buttons,
        2,
        [],
        line_postback_button('これで決定', 'likes:done', true)
    )];
}

function line_times_prompt() {
    $buttons = [];
    foreach (['ほとんど来ない', '週1回', '週2〜3回', 'ほぼ毎日'] as $label) {
        $buttons[] = line_postback_button($label, 'times:' . $label, false);
    }
    return [line_survey_card('3 / 4', '週に何回来る？', '近いものを1つ押してね。言葉で送っても大丈夫。', $buttons, 1)];
}

function line_avoid_prompt($user) {
    $avoids = $user['avoids'] ?? [];
    $now = count($avoids) ? ('選んでいるもの: ' . implode('、', $avoids)) : 'まだ選んでいないよ';
    $buttons = [];
    foreach (line_avoid_labels() as $label) {
        $on = in_array($label, $avoids, true);
        $buttons[] = line_postback_button(($on ? '✓ ' : '') . $label, 'avoid:' . $label, $on);
    }
    return [line_survey_card(
        '4 / 4',
        '苦手なもの',
        "苦手なものを全部押してね。なければ上の「とくにない」。\n一度に答えるなら「魚 乳製品」と送ってね。\n" . $now,
        $buttons,
        2,
        [line_postback_button('とくにない', 'avoid:とくにない', false)],
        line_postback_button('これで決定', 'avoid:done', true)
    )];
}

function line_times_label_from_text($text) {
    $map = [
        'ほとんど来ない' => ['ほとんど来ない', '来ない'],
        '週2〜3回' => ['週2〜3回', '週2', '週3'],
        '週1回' => ['週1回', '週1', '週一'],
        'ほぼ毎日' => ['ほぼ毎日', '毎日'],
    ];
    foreach ($map as $label => $words) {
        foreach ($words as $word) {
            if (mb_strpos($text, $word) !== false) {
                return $label;
            }
        }
    }
    return '';
}

function line_finish_survey($user) {
    if (empty($user['viewKey'])) {
        $user['viewKey'] = bin2hex(random_bytes(8));
    }
    $user['step'] = 'done';
    $user['active'] = true;
    line_store_user($user);
    $name = (string) ($user['name'] ?? '');
    return [line_home_menu($name !== '' ? $name . 'さん' : '登録できたよ', "登録できたよ。\n好きそうな定食には、カレンダーで星がつくよ。\n予約は、緑の「予約する」から。")];
}

function line_resume_prompt($user) {
    $step = $user['step'] ?? 'name';
    if ($step === 'likes') {
        return line_likes_prompt($user);
    }
    if ($step === 'times') {
        return line_times_prompt();
    }
    if ($step === 'avoid') {
        return line_avoid_prompt($user);
    }
    if ($step === 'pick') {
        return line_reserve_prompt($user);
    }
    if ($step === 'confirm') {
        return line_confirm_prompt($user);
    }
    if ($step === 'done') {
        return [line_home_menu('メニュー', 'カードのボタンから選んでね。')];
    }
    return line_name_prompt();
}

function line_handle_text($user, $text) {
    $text = trim($text);
    $step = $user['step'] ?? 'name';
    if ($step === '' || $step === 'new') {
        $step = 'name';
    }
    if ($step === 'name') {
        $name = trim(str_replace(["\r", "\n"], '', $text));
        $name = line_cut($name, 20);
        if ($name === '') {
            return line_name_prompt();
        }
        $user['name'] = $name;
        $user['likes'] = [];
        $user['step'] = 'likes';
        line_store_user($user);
        return line_likes_prompt($user);
    }
    if ($step === 'likes') {
        $found = line_labels_in_text($text, array_keys(line_like_words()));
        if (line_is_done_word($text) && count($user['likes'] ?? [])) {
            $user['step'] = 'times';
            line_store_user($user);
            return line_times_prompt();
        }
        if (count($found) >= 2) {
            $user['likes'] = array_slice($found, 0, 8);
            $user['step'] = 'times';
            line_store_user($user);
            return line_times_prompt();
        }
        if (count($found) === 1) {
            $likes = $user['likes'] ?? [];
            if (!in_array($found[0], $likes, true) && count($likes) < 8) {
                $likes[] = $found[0];
            }
            $user['likes'] = $likes;
            $user['step'] = 'likes';
            line_store_user($user);
        }
        return line_likes_prompt($user);
    }
    if ($step === 'times') {
        $label = line_times_label_from_text($text);
        if ($label === '') {
            return line_times_prompt();
        }
        $user['timesPerWeek'] = $label;
        $user['step'] = 'avoid';
        $user['avoids'] = [];
        line_store_user($user);
        return line_avoid_prompt($user);
    }
    if ($step === 'avoid') {
        if (mb_strpos($text, 'とくにない') !== false || mb_strpos($text, '特にない') !== false || trim($text) === 'ない') {
            $user['avoids'] = [];
            return line_finish_survey($user);
        }
        $found = line_labels_in_text($text, line_avoid_labels());
        if (line_is_done_word($text)) {
            return line_finish_survey($user);
        }
        if (count($found) >= 2) {
            $user['avoids'] = $found;
            return line_finish_survey($user);
        }
        if (count($found) === 1) {
            $avoids = $user['avoids'] ?? [];
            if (!in_array($found[0], $avoids, true)) {
                $avoids[] = $found[0];
            }
            $user['avoids'] = $avoids;
            $user['step'] = 'avoid';
            line_store_user($user);
        }
        return line_avoid_prompt($user);
    }
    if ($step === 'pick' || $step === 'confirm') {
        return line_resume_prompt($user);
    }
    if (preg_match('/予約/u', $text) && !preg_match('/確認|番号/u', $text)) {
        return line_reserve_prompt($user);
    }
    $answer = line_answer($text, $user);
    return [line_text($answer === null ? line_today_text($user) : $answer)];
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
    $users = line_users();
    if (!isset($users[$userId]) || !is_array($users[$userId])) {
        $users[$userId] = [
            'userId' => $userId,
            'displayName' => $displayName,
            'name' => '',
            'likes' => [],
            'avoids' => [],
            'timesPerWeek' => '',
            'step' => 'new',
            'active' => true,
            'viewKey' => bin2hex(random_bytes(8)),
        ];
        line_save_users($users);
    }
    $users[$userId]['active'] = true;
    if ($displayName !== '') {
        $users[$userId]['displayName'] = $displayName;
    }
    $users[$userId]['userId'] = $userId;
    return $users[$userId];
}

function line_handle_postback($user, $data) {
    if (strpos($data, 'like:') === 0) {
        $label = substr($data, 5);
        $likes = $user['likes'] ?? [];
        if (in_array($label, $likes, true)) {
            $likes = array_values(array_filter($likes, function ($item) use ($label) {
                return $item !== $label;
            }));
        } elseif (isset(line_like_words()[$label]) && count($likes) < 8) {
            $likes[] = $label;
        }
        $user['likes'] = $likes;
        $user['step'] = 'likes';
        line_store_user($user);
        return line_likes_prompt($user);
    }
    if ($data === 'likes:done') {
        if (!count($user['likes'] ?? [])) {
            return line_likes_prompt($user);
        }
        $user['step'] = 'times';
        line_store_user($user);
        return line_times_prompt();
    }
    if (strpos($data, 'times:') === 0) {
        $user['timesPerWeek'] = substr($data, 6);
        $user['step'] = 'avoid';
        $user['avoids'] = [];
        line_store_user($user);
        return line_avoid_prompt($user);
    }
    if ($data === 'avoid:done') {
        if (!is_array($user['avoids'] ?? null)) {
            $user['avoids'] = [];
        }
        return line_finish_survey($user);
    }
    if ($data === 'avoid:とくにない') {
        $user['avoids'] = [];
        return line_finish_survey($user);
    }
    if (strpos($data, 'avoid:') === 0) {
        $label = substr($data, 6);
        if (!in_array($label, line_avoid_labels(), true)) {
            return line_avoid_prompt($user);
        }
        $avoids = $user['avoids'] ?? [];
        if (in_array($label, $avoids, true)) {
            $avoids = array_values(array_filter($avoids, function ($item) use ($label) {
                return $item !== $label;
            }));
        } elseif (count($avoids) < 5) {
            $avoids[] = $label;
        }
        $user['avoids'] = $avoids;
        $user['step'] = 'avoid';
        line_store_user($user);
        return line_avoid_prompt($user);
    }
    if ($data === 'home:redo') {
        $user['step'] = 'name';
        $user['likes'] = [];
        $user['avoids'] = [];
        line_store_user($user);
        return line_name_prompt();
    }
    if ($data === 'home:today') {
        return [line_text(line_today_text($user))];
    }
    if ($data === 'home:week') {
        return [line_text(line_week_text($user))];
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
    if (strpos($data, 'pick:') === 0) {
        $index = (int) substr($data, 5);
        $choices = $user['choices'] ?? [];
        if (!isset($choices[$index])) {
            return line_reserve_prompt($user);
        }
        $user['pendingFood'] = $choices[$index];
        $user['step'] = 'confirm';
        line_store_user($user);
        return line_confirm_prompt($user);
    }
    if ($data === 'book:no') {
        $user['step'] = 'done';
        $user['pendingFood'] = '';
        line_store_user($user);
        return [line_home_menu('予約はやめたよ', 'またいつでもどうぞ。')];
    }
    if ($data === 'book:yes') {
        $check = line_can_reserve($user['userId'] ?? '');
        if (!$check['ok']) {
            $user['step'] = 'done';
            line_store_user($user);
            return [line_text($check['message'])];
        }
        $food = (string) ($user['pendingFood'] ?? '');
        $name = trim((string) ($user['name'] ?? ''));
        $user['step'] = 'done';
        $user['pendingFood'] = '';
        line_store_user($user);
        if ($name === '' || $food === '') {
            return [line_home_menu('もう一度選んでね', '緑の「予約する」からやり直せるよ。')];
        }
        $made = line_create_reservation($name, $food);
        if (!$made['ok']) {
            return [line_text($made['message'])];
        }
        $num = sprintf('%03d', (int) $made['number']);
        return [line_text("予約ができました！\n番号は " . $num . " だよ。\n受け取るとき、名前とこの番号を教えてね。\nメニュー: " . $food)];
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
    if ($type === 'unfollow') {
        $users = line_users();
        if (isset($users[$userId])) {
            $users[$userId]['active'] = false;
            line_save_users($users);
        }
        return;
    }
    $user = line_load_user($userId, '');
    $messages = [];
    if ($type === 'follow') {
        if (($user['step'] ?? '') === 'done') {
            $messages = [line_text(($user['name'] ?? '') . 'さん、また来てくれてありがとう。')];
        } else {
            $messages = line_start_survey($user);
            $user['step'] = 'name';
            line_store_user($user);
        }
    } elseif ($type === 'message') {
        $msgType = $event['message']['type'] ?? '';
        if ($msgType === 'text') {
            $messages = line_handle_text($user, (string) ($event['message']['text'] ?? ''));
        } else {
            $messages = line_resume_prompt($user);
        }
    } elseif ($type === 'postback') {
        $messages = line_handle_postback(line_load_user($userId, ''), (string) ($event['postback']['data'] ?? ''));
    }
    if (count($messages)) {
        $fresh = line_load_user($userId, '');
        line_deliver($replyToken, $userId, line_attach_home($messages, $fresh), $type === 'follow');
    }
}
