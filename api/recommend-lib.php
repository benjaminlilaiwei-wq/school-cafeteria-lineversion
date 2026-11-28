<?php
/**
 * 個人別おすすめ。
 *
 * アンケートと評価は Supabase の cafeteria 行（recommend-feedback）に保存する。
 * 紹介文と画像のメタデータは cafeteria 行（recommend-assets）に保存する。
 * 選んだ画像ファイルだけを Supabase Storage の menu-images に置く。
 *
 * 画像の選び方（コード上の method）:
 * - openai-vision: 設定モデルが画像入力できるとき（gpt-4o 系）。候補を base64 で1回だけ送り、確信が高い1枚を選ぶ。
 * - score: 画像入力が使えない、または API が失敗したとき。タグ・解像度・料理名の一致で採点し、点数が低いものは不採用。
 * Pixabay の人気順の先頭を、そのまま採用はしない。
 */
require_once __DIR__ . '/line-lib.php';

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen($string);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) {
        return strtolower($string);
    }
}

function recommend_assets_file() {
    return line_data_dir() . '/recommend-assets.json';
}

function recommend_feedback_file() {
    return line_data_dir() . '/recommend-feedback.json';
}

function recommend_feedback_default() {
    return ['votes' => [], 'impressions' => []];
}

function recommend_lock($callback) {
    static $held = false;
    if ($held) {
        return $callback();
    }
    $fp = @fopen(line_data_dir() . '/recommend.lock', 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
    }
    $held = true;
    try {
        return $callback();
    } finally {
        $held = false;
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

function recommend_load_doc($file, $default) {
    $loaded = cafeteria_load($file);
    if ($loaded['state'] === 'error') {
        return null;
    }
    if ($loaded['state'] !== 'ok' || !is_array($loaded['data'])) {
        return $default;
    }
    return $loaded['data'];
}

function recommend_save_doc($file, $data) {
    return line_write_json($file, $data);
}

function recommend_normalize_assets($data) {
    if (!is_array($data)) {
        $data = [];
    }
    if (!isset($data['menus']) || !is_array($data['menus'])) {
        $data['menus'] = [];
    }
    return $data;
}

function recommend_normalize_feedback($data) {
    if (!is_array($data)) {
        $data = recommend_feedback_default();
    }
    if (!isset($data['votes']) || !is_array($data['votes'])) {
        $data['votes'] = [];
    }
    if (!isset($data['impressions']) || !is_array($data['impressions'])) {
        $data['impressions'] = [];
    }
    return $data;
}

function recommend_merge_menu($food, $mutator) {
    return recommend_lock(function () use ($food, $mutator) {
        $assets = recommend_load_doc(recommend_assets_file(), ['menus' => []]);
        if ($assets === null) {
            return null;
        }
        $assets = recommend_normalize_assets($assets);
        $row = $assets['menus'][$food] ?? [];
        if (!is_array($row)) {
            $row = [];
        }
        $next = $mutator($row);
        if (!is_array($next)) {
            return null;
        }
        $assets['menus'][$food] = $next;
        if (!recommend_save_doc(recommend_assets_file(), $assets)) {
            return null;
        }
        return $next;
    });
}

function recommend_merge_feedback($mutator) {
    return recommend_lock(function () use ($mutator) {
        $data = recommend_load_doc(recommend_feedback_file(), recommend_feedback_default());
        if ($data === null) {
            return null;
        }
        $data = recommend_normalize_feedback($data);
        $next = $mutator($data);
        if (!is_array($next)) {
            return null;
        }
        if (!recommend_save_doc(recommend_feedback_file(), $next)) {
            return null;
        }
        return $next;
    });
}

function recommend_request($url, $method, $headers, $body, $timeout) {
    if (!function_exists('curl_init')) {
        return [false, 0, ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
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

function recommend_openai_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $loaded = [];
    $path = __DIR__ . '/ollama-config.php';
    if (is_file($path)) {
        $value = include $path;
        if (is_array($value)) {
            $loaded = $value;
        }
    }
    $loaded = apply_openai_env($loaded);
    $openai = is_array($loaded['openai'] ?? null) ? $loaded['openai'] : [];
    $model = trim((string) ($openai['model'] ?? ''));
    // おすすめ文と画像比較は、画像を見られる現行モデルに揃える。
    // 設定が空、または廃止された gpt-3.5 のときは gpt-4o-mini を使う。
    if ($model === '' || stripos($model, 'gpt-3.5') !== false) {
        $model = 'gpt-4o-mini';
    }
    $base = trim((string) ($openai['base_url'] ?? ''));
    $config = [
        'enabled' => !empty($openai['enabled']) && trim((string) ($openai['api_key'] ?? '')) !== '',
        'key' => trim((string) ($openai['api_key'] ?? '')),
        'model' => $model,
        'base' => rtrim($base !== '' ? $base : 'https://api.openai.com/v1', '/'),
    ];
    return $config;
}

function recommend_model_accepts_images($model) {
    $model = strtolower((string) $model);
    return strpos($model, 'gpt-4o') !== false
        || strpos($model, 'gpt-4.1') !== false
        || strpos($model, 'gpt-5') !== false;
}

function recommend_message_text($content) {
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }
    $text = '';
    foreach ($content as $part) {
        if (is_string($part)) {
            $text .= $part;
        } elseif (is_array($part) && isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }
    return $text;
}

function recommend_openai_chat($content, $timeout, $maxTokens) {
    $config = recommend_openai_config();
    if (!$config['enabled']) {
        return null;
    }
    $payload = [
        'model' => $config['model'],
        'temperature' => 0,
        'max_tokens' => $maxTokens,
        'messages' => [
            ['role' => 'system', 'content' => 'あなたは学校食堂の案内係です。指示された形式だけを返してください。'],
            ['role' => 'user', 'content' => $content],
        ],
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['key'],
    ];
    $url = $config['base'] . '/chat/completions';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($body) || $body === '') {
        return null;
    }
    [$ok, $code, $raw] = recommend_request($url, 'POST', $headers, $body, $timeout);
    if (!$ok && $code === 400 && strpos($raw, 'temperature') !== false) {
        unset($payload['temperature']);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        [$ok, $code, $raw] = recommend_request($url, 'POST', $headers, $body, $timeout);
    }
    if (!$ok) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    $text = recommend_message_text($json['choices'][0]['message']['content'] ?? '');
    $text = trim($text);
    return $text === '' ? null : $text;
}

function recommend_parse_json_text($text) {
    $text = trim((string) $text);
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $text, $match)) {
        $text = $match[1];
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end < $start) {
        return null;
    }
    $json = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($json) ? $json : null;
}

function recommend_core_name($food) {
    $name = trim((string) $food);
    $name = preg_replace('/（[^）]*）/u', '', $name);
    $name = preg_replace('/\([^)]*\)/u', '', $name);
    $name = str_replace('定食', '', (string) $name);
    $name = trim((string) $name);
    if (mb_strlen($name) > 100) {
        $name = mb_substr($name, 0, 100);
    }
    return $name;
}

function recommend_query_pairs() {
    return [
        '鶏の照り焼き' => ['Japanese chicken teriyaki set meal', 'chicken teriyaki'],
        '照り焼き' => ['Japanese chicken teriyaki', 'teriyaki chicken'],
        '豚マヨネーズ' => ['pork mayonnaise rice', 'grilled pork mayonnaise'],
        '豚マヨ' => ['pork mayonnaise rice', 'grilled pork mayonnaise'],
        'カツオのたたき' => ['seared bonito tataki', 'bonito tataki'],
        'カツオたたき' => ['seared bonito tataki', 'bonito tataki'],
        'カツオ' => ['seared bonito fish', 'bonito tataki'],
        'かつお' => ['seared bonito fish', 'bonito tataki'],
        'とんかつ' => ['tonkatsu pork cutlet', 'Japanese pork cutlet'],
        '豚カツ' => ['tonkatsu pork cutlet', 'Japanese pork cutlet'],
        'ヒレカツ' => ['pork fillet cutlet', 'tonkatsu'],
        'チキンカツ' => ['chicken cutlet', 'chicken katsu'],
        '唐揚げ' => ['Japanese karaage fried chicken', 'karaage chicken'],
        'から揚げ' => ['Japanese karaage fried chicken', 'karaage chicken'],
        'からあげ' => ['Japanese karaage fried chicken', 'karaage chicken'],
        '生姜焼き' => ['Japanese ginger pork set meal', 'ginger pork'],
        'しょうが焼' => ['Japanese ginger pork', 'ginger pork'],
        'チキンソテー' => ['chicken saute set meal', 'chicken saute'],
        'サバの味噌煮' => ['Japanese mackerel miso', 'mackerel simmered miso'],
        '味噌煮' => ['Japanese simmered fish miso', 'mackerel miso'],
        'ハンバーグ' => ['Japanese hambagu steak', 'hamburg steak'],
        'ハヤシライス' => ['hayashi rice', 'hashed beef rice'],
        'ハヤシ' => ['hayashi rice', 'hashed beef rice'],
        'ちくわの磯部' => ['Japanese chikuwa isobe fry', 'isobe fry'],
        '磯部' => ['Japanese isobe fry', 'seaweed fried fish cake'],
        '南蛮漬け' => ['Japanese nanban fried fish', 'nanban chicken'],
        '南蛮' => ['Japanese nanban fried fish', 'nanban'],
        'イワシ' => ['Japanese grilled sardine', 'grilled sardine'],
        '魚の煮付け' => ['Japanese simmered fish', 'simmered fish soy'],
        '煮付け' => ['Japanese simmered fish', 'simmered fish'],
        '焼き魚' => ['Japanese grilled fish', 'grilled fish'],
        '鮭の塩焼き' => ['grilled salted salmon', 'salmon salt grill'],
        '鯖の塩焼き' => ['grilled salted mackerel', 'mackerel salt grill'],
        '刺身' => ['Japanese sashimi', 'sashimi'],
        'カツカレー' => ['Japanese katsu curry', 'pork cutlet curry'],
        'カレーうどん' => ['curry udon', 'Japanese curry noodles'],
        'カレーライス' => ['Japanese curry rice', 'curry rice'],
        '天ぷらうどん' => ['tempura udon', 'udon tempura'],
        '天ぷらそば' => ['tempura soba', 'soba tempura'],
        'かけうどん' => ['udon noodle soup', 'kake udon'],
        '肉うどん' => ['meat udon', 'beef udon'],
        'かけそば' => ['soba noodle soup', 'kake soba'],
        '肉そば' => ['meat soba noodles', 'soba pork'],
        '醤油ラーメン' => ['shoyu ramen', 'soy sauce ramen'],
        '豚骨ラーメン' => ['tonkotsu ramen', 'pork bone ramen'],
        '親子丼' => ['oyakodon chicken rice', 'chicken egg rice bowl'],
        'カレー' => ['Japanese curry rice', 'curry rice'],
        'カツ' => ['Japanese pork cutlet', 'tonkatsu'],
        'ラーメン' => ['Japanese ramen', 'ramen noodles'],
        'うどん' => ['Japanese udon', 'udon noodles'],
        'そば' => ['Japanese soba noodles', 'soba noodles'],
        'チャーハン' => ['Japanese fried rice', 'chahan fried rice'],
        '炒飯' => ['Japanese fried rice', 'chahan'],
        'やきめし' => ['Japanese fried rice', 'yakimeshi'],
        'オムライス' => ['Japanese omurice', 'omurice'],
        'ホットドッグ' => ['hot dog', 'sausage bread'],
        'ホットドック' => ['hot dog', 'sausage bread'],
        '納豆' => ['natto rice', 'fermented soybeans rice'],
        '丼' => ['Japanese rice bowl', 'donburi'],
        'サラダ' => ['vegetable salad', 'green salad'],
        'たたき' => ['seared fish tataki', 'bonito tataki'],
    ];
}

function recommend_matched_query($food) {
    $pairs = recommend_query_pairs();
    uksort($pairs, function ($a, $b) {
        return mb_strlen($b) <=> mb_strlen($a);
    });
    foreach ($pairs as $ja => $phrases) {
        if (mb_strpos($food, $ja) !== false) {
            return $phrases;
        }
    }
    return [];
}

function recommend_english_query($food) {
    $phrases = recommend_matched_query($food);
    if (!count($phrases)) {
        return '';
    }
    return mb_substr((string) $phrases[0], 0, 100);
}

function recommend_search_plan($food) {
    $plan = [];
    $core = recommend_core_name($food);
    if ($core !== '') {
        $plan[] = ['lang' => 'ja', 'q' => $core];
    }
    foreach (recommend_matched_query($food) as $phrase) {
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            continue;
        }
        $plan[] = ['lang' => 'en', 'q' => mb_substr($phrase, 0, 100)];
        if (count($plan) >= 3) {
            break;
        }
    }
    return $plan;
}

function recommend_blurb_acceptable($food, $text) {
    $text = trim((string) $text);
    $length = mb_strlen($text);
    if ($length < 12 || $length > 220) {
        return false;
    }
    $core = recommend_core_name($food);
    if ($core !== '' && mb_strpos($text, $core) === false && mb_strpos($text, $food) === false) {
        return false;
    }
    $blocked = [
        '絶品', '最高', '必食', '至福', '間違いない',
        '卵', '小麦', '乳', 'えび', 'エビ', 'かに', 'カニ', 'そば', '落花生', 'くるみ', 'チーズ',
        '甘辛', 'タレ', '味噌', '塩', '醤油', 'ソース', 'ご飯', 'ごはん', '味噌汁', 'みそ汁',
        'サラダ', 'スープ', '野菜', '特製', '自家製', '人気', 'バランス', '付け合わせ', '副菜',
        '主菜', 'バター', '牛乳', '揚げ', '煮込', '炒', 'グリル', 'ハーブ', 'スパイス',
        'ニンニク', 'しょうが', '生姜', '玉ねぎ', 'たまねぎ', '人参', 'じゃがいも', 'ポテト', 'キャベツ',
    ];
    foreach ($blocked as $word) {
        if ($word !== '' && mb_strpos($text, $word) !== false && mb_strpos($food, $word) === false) {
            return false;
        }
    }
    return true;
}

function recommend_generate_blurb($food) {
    $prompt = '次の2文だけを返してください。新しい名詞、味、食材、付け合わせ、調理の説明は足さないでください。'
        . '1文目は「' . $food . 'です。」'
        . '2文目は「この日の学校食堂の定食です。」';
    $text = recommend_openai_chat($prompt, 12, 220);
    if (!is_string($text)) {
        return null;
    }
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    $text = trim($text, " \t\n\r\0\x0B\"'「」");
    if (!recommend_blurb_acceptable($food, $text)) {
        return null;
    }
    return $text;
}

function recommend_fallback_blurb($food) {
    return $food . 'です。この日の学校食堂の定食です。';
}

function recommend_menu_pitch($food) {
    $food = trim((string) $food);
    if ($food === '') {
        return '';
    }
    $hooks = [
        '豚カツ' => ['衣がさくさくで、ソースの甘じょっぱさがご飯を呼ぶ味です', '熱いうちに切ると、肉の脂がふわっと香ります'],
        'とんかつ' => ['衣がさくさくで、ソースの甘じょっぱさがご飯を呼ぶ味です', '熱いうちに切ると、肉の脂がふわっと香ります'],
        'チキンカツ' => ['鶏肉のあっさりした味と、衣の塩気が一緒に来ます', '噛むほど肉の甘みが出て、がっつりなのに重くありません'],
        'チキンソテー' => ['表面は香ばしく、中はしっとりした鶏肉の味です', 'ソースのコクが舌に残って、午後まで満足が続きます'],
        '唐揚げ' => ['胡椒がぴりっと効いて、噛むと肉の汁がじわっと出ます', 'からっとした衣の音が、昼休みの合図みたいに楽しいです'],
        'から揚げ' => ['胡椒がぴりっと効いて、噛むと肉の汁がじわっと出ます', 'からっとした衣の音が、昼休みの合図みたいに楽しいです'],
        '唐揚' => ['胡椒がぴりっと効いて、噛むと肉の汁がじわっと出ます', 'からっとした衣の音が、昼休みの合図みたいに楽しいです'],
        'から揚' => ['胡椒がぴりっと効いて、噛むと肉の汁がじわっと出ます', 'からっとした衣の音が、昼休みの合図みたいに楽しいです'],
        '生姜焼' => ['しょうがの香りが鼻に抜けて、甘じょっぱい味がご飯に合います', '一口ごとに肉の脂が甘くて、おかわりしたくなります'],
        'ハンバーグ' => ['肉の味が濃くて、切ると肉汁がじわっと広がります', 'ケチャップの甘酸っぱさが、高校生の昼にちょうどいいです'],
        '味噌煮' => ['味噌のコクが身にしみて、ほろほろとほぐれます', '塩気と甘みが一緒に来て、温かいご飯が進みます'],
        '磯部' => ['海の香りがふっと立って、味は軽くて後味がきれいです', '油の香ばしさと磯の風味で、気分が切り替わります'],
        '南蛮' => ['酢の酸味が先に来て、揚げた衣は中でしんなりしています', 'さっぱりなのに食べごたえがあって、眠くなりにくい昼です'],
        '煮付け' => ['醤油の甘じょっぱさが身の奥まで入っています', '箸でほぐれる柔らかさで、落ち着いて食べられます'],
        'イワシ' => ['青魚の旨みが強くて、香ばしい焼き目が食欲を起こします', '脂の甘みがあるので、ご飯と一緒だと止まらなくなります'],
        '親子丼' => ['出汁の香りがふわっと来て、鶏肉は柔らかく卵はとろりです', '甘じょっぱい味がご飯に絡んで、丼の底まで食べたくなります'],
        '塩焼' => ['塩だけの味だから、魚の脂と焼き目の香りがまっすぐ届きます', 'ほぐれる身をご飯にのせると、シンプルなのに満足します'],
        '鮭' => ['焼き色の香ばしさと、鮭の脂の甘みが一緒に来ます', 'ほぐれる身はご飯と相性がよく、食べ終わったあとがきれいです'],
        '鯖' => ['鯖の脂がのっていて、塩味がご飯を引き立てます', 'ほくほくの身は一口が大きいほど、昼ごはんらしくなります'],
        'サバ' => ['鯖の脂がのっていて、塩味がご飯を引き立てます', 'ほくほくの身は一口が大きいほど、昼ごはんらしくなります'],
        'ホットドッグ' => ['パンの外は香ばしく、ソーセージの塩気が口いっぱいに広がります', '片手で食べられるのに味がはっきりして、気分が上がります'],
        'ホットドック' => ['パンの外は香ばしく、ソーセージの塩気が口いっぱいに広がります', '片手で食べられるのに味がはっきりして、気分が上がります'],
        '鍋' => ['出汁の香りが先に届いて、具材ごとに味の濃さが違います', 'あつあつを分けながら食べると、友達と昼が長くなります'],
        'カレー' => ['スパイスの香りが立って、ルーの甘みと辛みが残ります', 'スプーンを進めるほどご飯が黄色くなって、最後まで楽しいです'],
        '納豆' => ['混ぜた瞬間に香りが開いて、ねばりと醤油の塩気がご飯に絡みます', '癖になる味で、午前の眠気が少し飛びます'],
        'カツ' => ['衣がさくさくで、肉の汁とソースが一緒に来ます', '熱いうちの一切れは、昼休みのごほうびになります'],
    ];
    uksort($hooks, function ($a, $b) {
        return mb_strlen($b) <=> mb_strlen($a);
    });
    $lead = 'この名前の味が、ちゃんと真ん中に来る定食です';
    $tail = '一口ごとに味が立って、午後の授業までお腹が落ち着きます';
    foreach ($hooks as $key => $pair) {
        if (mb_strpos($food, $key) !== false) {
            $lead = $pair[0];
            $tail = $pair[1];
            break;
        }
    }
    return $food . 'は、' . $lead . '。' . $tail . '。';
}

function recommend_menu_disliked($user, $food) {
    if (!is_array($user) || trim((string) $food) === '') {
        return false;
    }
    if (recommend_matched_avoids($user, $food)) {
        return true;
    }
    return recommend_allergy_hits($user, $food) !== [];
}

function recommend_choose_next_menu($rows, $isDisliked) {
    $fallback = null;
    $looking = false;
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        if ($date === '') {
            continue;
        }
        $food = trim((string) ($row['food'] ?? ''));
        if (!$looking) {
            $fallback = $row;
            if ($food === '' || !$isDisliked($food)) {
                return $row;
            }
            $looking = true;
            continue;
        }
        if ($food === '' || $isDisliked($food)) {
            continue;
        }
        return $row;
    }
    return $fallback;
}

function recommend_suggestion_day($user, $afterDate) {
    $stamp = strtotime($afterDate . ' +1 day');
    if ($stamp === false || $afterDate === '') {
        return null;
    }
    $span = recommend_pick_open_dates(date('Y-m-d', $stamp), function ($date) {
        return line_holiday($date) !== null;
    }, 21);
    $rows = [];
    foreach ($span as $day) {
        $rows[] = [
            'date' => $day[0],
            'when' => 'later',
            'food' => line_daily_food($day[0]),
        ];
    }
    if (!count($rows)) {
        return null;
    }
    if (!is_array($user)) {
        return $rows[0];
    }
    return recommend_choose_next_menu($rows, function ($food) use ($user) {
        return recommend_menu_disliked($user, $food);
    });
}

function recommend_week_rows($user) {
    $start = strtotime(date('Y-m-d') . ' 00:00:00');
    $rows = [];
    if ($start === false) {
        return $rows;
    }
    for ($i = 0; $i < 21 && count($rows) < 10; $i++) {
        $date = date('Y-m-d', strtotime('+' . $i . ' day', $start));
        if (line_holiday($date) !== null) {
            continue;
        }
        $food = trim((string) line_daily_food($date));
        $rows[] = [
            'date' => $date,
            'food' => $food,
            'disliked' => is_array($user) && $food !== '' && recommend_menu_disliked($user, $food),
        ];
    }
    return $rows;
}

function recommend_rank_rows($rows, $scoreOf) {
    $ranked = [];
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        if ($date === '') {
            continue;
        }
        $food = trim((string) ($row['food'] ?? ''));
        $ranked[] = [
            'date' => $date,
            'food' => $food,
            'disliked' => !empty($row['disliked']),
            'score' => $food === '' ? -1000 : (int) $scoreOf($food),
        ];
    }
    usort($ranked, function ($a, $b) {
        if ($a['disliked'] !== $b['disliked']) {
            return $a['disliked'] ? 1 : -1;
        }
        if ($a['score'] !== $b['score']) {
            return $a['score'] < $b['score'] ? 1 : -1;
        }
        return strcmp($a['date'], $b['date']);
    });
    return $ranked;
}

function recommend_choose_slots($rows, $today, $scoreOf) {
    $upcoming = [];
    foreach ((array) $rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        if ($date === '' || $date < (string) $today) {
            continue;
        }
        $upcoming[] = $row;
    }
    usort($upcoming, function ($a, $b) {
        return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
    });
    $ranked = recommend_rank_rows($upcoming, $scoreOf);
    $featured = null;
    foreach ($ranked as $row) {
        if ($row['food'] !== '' && !$row['disliked']) {
            $featured = $row;
            break;
        }
    }
    if ($featured === null) {
        foreach ($ranked as $row) {
            if ($row['food'] !== '') {
                $featured = $row;
                break;
            }
        }
    }
    $slots = [];
    $usedDates = [];
    if ($featured !== null) {
        $role = ($featured['date'] === $today) ? 'week-today' : 'week';
        $slots[] = ['role' => $role, 'row' => $featured];
        $usedDates[$featured['date']] = true;
    }
    $preferred = [];
    $fallback = [];
    foreach ($upcoming as $row) {
        $date = (string) ($row['date'] ?? '');
        if ($date === '' || isset($usedDates[$date])) {
            continue;
        }
        $food = trim((string) ($row['food'] ?? ''));
        if ($food !== '' && empty($row['disliked'])) {
            $preferred[] = $row;
        } else {
            $fallback[] = $row;
        }
    }
    $pickedDays = array_slice($preferred, 0, 2);
    if (count($pickedDays) < 2) {
        foreach ($fallback as $row) {
            if (count($pickedDays) >= 2) {
                break;
            }
            $pickedDays[] = $row;
        }
    }
    $next = $pickedDays[0] ?? null;
    $after = $pickedDays[1] ?? null;
    if ($next !== null) {
        $slots[] = ['role' => 'next', 'row' => $next];
    }
    if ($after !== null) {
        $slots[] = ['role' => 'after', 'row' => $after];
    }
    return $slots;
}

function recommend_slot_title($role) {
    if ($role === 'week' || $role === 'week-today') {
        return '⭐ 今週のイチオシ定食';
    }
    if ($role === 'next' || $role === 'today') {
        return '🍱 次の営業日のおすすめ';
    }
    return '🍚 その次の営業日のおすすめ';
}

function recommend_rank_score($user, $food, $votes, $impressions) {
    $score = is_array($user) ? recommend_personal_score($user, $food, $votes) : 0;
    if (!is_array($user) || $food === '') {
        return $score;
    }
    $userId = (string) ($user['userId'] ?? '');
    $repeats = recommend_repeat_count($impressions, $userId, $food);
    $tryNew = is_array($user['followups'] ?? null) ? (string) ($user['followups']['try_new'] ?? '') : '';
    if ($repeats >= 2) {
        $score -= $tryNew === 'yes' ? 2 : 1;
    } elseif ($repeats === 0 && $tryNew === 'yes') {
        $score += 1;
    }
    return $score;
}

function recommend_visible_dates($user) {
    $votes = [];
    $impressions = [];
    $feedback = recommend_load_doc(recommend_feedback_file(), recommend_feedback_default());
    if (is_array($feedback)) {
        $feedback = recommend_normalize_feedback($feedback);
        $votes = $feedback['votes'];
        $impressions = $feedback['impressions'];
    }
    $dates = [];
    $scoreOf = function ($food) use ($user, $votes, $impressions) {
        return recommend_rank_score($user, $food, $votes, $impressions);
    };
    foreach (recommend_choose_slots(recommend_week_rows($user), date('Y-m-d'), $scoreOf) as $slot) {
        $dates[(string) $slot['row']['date']] = true;
    }
    return $dates;
}

function recommend_blurb_for($food) {
    $food = trim((string) $food);
    if ($food === '') {
        return '';
    }
    $existing = recommend_lock(function () use ($food) {
        $assets = recommend_load_doc(recommend_assets_file(), ['menus' => []]);
        if ($assets === null) {
            return ['missing' => true];
        }
        $assets = recommend_normalize_assets($assets);
        $row = $assets['menus'][$food] ?? [];
        if (!is_array($row)) {
            $row = [];
        }
        $cached = trim((string) ($row['blurb'] ?? ''));
        $status = (string) ($row['blurbStatus'] ?? '');
        $at = (int) ($row['blurbAt'] ?? 0);
        if ($status === 'ok' && $cached !== '') {
            return ['text' => $cached];
        }
        if ($status === 'fallback' && $cached !== '' && $at > time() - 6 * 3600) {
            return ['text' => $cached];
        }
        return ['generate' => true];
    });
    if (!empty($existing['text'])) {
        return (string) $existing['text'];
    }
    if (!empty($existing['missing'])) {
        return recommend_fallback_blurb($food);
    }
    $text = recommend_generate_blurb($food);
    $status = 'ok';
    if ($text === null) {
        $text = recommend_fallback_blurb($food);
        $status = 'fallback';
    }
    recommend_merge_menu($food, function ($row) use ($text, $status) {
        if (($row['blurbStatus'] ?? '') === 'ok' && trim((string) ($row['blurb'] ?? '')) !== '') {
            return $row;
        }
        $row['blurb'] = $text;
        $row['blurbStatus'] = $status;
        $row['blurbAt'] = time();
        return $row;
    });
    return $text;
}

function recommend_skip_labels() {
    return ['和食' => true, 'ごはん' => true, '汁物' => true];
}

function recommend_specific_labels($food) {
    $skip = recommend_skip_labels();
    $labels = [];
    foreach (array_keys(line_like_words()) as $label) {
        if (isset($skip[$label])) {
            continue;
        }
        if (line_food_matches($food, $label)) {
            $labels[] = $label;
        }
    }
    usort($labels, function ($a, $b) {
        return mb_strlen($b) <=> mb_strlen($a);
    });
    return $labels;
}

function recommend_foods_related($left, $right) {
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }
    $have = array_flip(recommend_specific_labels($left));
    foreach (recommend_specific_labels($right) as $label) {
        if (isset($have[$label])) {
            return true;
        }
    }
    return false;
}

function recommend_shared_label($left, $right) {
    $have = array_flip(recommend_specific_labels($left));
    $shared = [];
    foreach (recommend_specific_labels($right) as $label) {
        if (isset($have[$label])) {
            $shared[] = $label;
        }
    }
    return $shared[0] ?? '';
}

function recommend_times_bonus($user) {
    $times = (string) ($user['timesPerWeek'] ?? '');
    if ($times === '週2〜3回') {
        return 1;
    }
    if ($times === 'ほぼ毎日') {
        return 2;
    }
    return 0;
}

function recommend_delta_from_votes($votes, $userId, $food) {
    if ($userId === '' || $food === '' || !is_array($votes)) {
        return 0;
    }
    $up = 0;
    $down = 0;
    foreach ($votes as $vote) {
        if (!is_array($vote) || (string) ($vote['userId'] ?? '') !== $userId) {
            continue;
        }
        $other = (string) ($vote['food'] ?? '');
        $same = $other === $food;
        if (!$same && !recommend_foods_related($other, $food)) {
            continue;
        }
        $kind = (string) ($vote['vote'] ?? '');
        $reason = (string) ($vote['reason'] ?? '');
        if ($kind === 'up') {
            $up++;
            continue;
        }
        if ($kind !== 'down') {
            continue;
        }
        if (($reason === 'had_before' || $reason === 'want_other') && !$same) {
            continue;
        }
        $down++;
        if ($reason === 'disliked_ingredient' || $reason === 'dislike_ingredient' || $reason === 'dislike_dish') {
            $down++;
        }
    }
    return min(3, $up) - min(3, $down);
}

function recommend_style_words() {
    return [
        '和食' => ['味噌', '煮付け', '焼き魚', '刺身', '生姜', '納豆', '磯部', '南蛮', '塩焼', '親子', 'さば', 'サバ', '鮭', 'イワシ', '煮魚'],
        '洋食' => ['ハンバーグ', 'カレー', 'オムライス', 'ハヤシ', 'パスタ', 'ソテー', 'グラタン', 'ドリア', 'コロッケ'],
        '中華' => ['チャーハン', '炒飯', 'やきめし', 'ラーメン', '餃子', '酢豚', '麻婆', '中華'],
        '辛い料理' => ['辛', 'ラー油', 'キムチ', 'からい', '麻婆'],
        'さっぱり' => ['刺身', '南蛮', '塩焼', 'サラダ', 'うどん', 'そば', '煮付け'],
        'こってり' => ['とんかつ', '豚カツ', 'マヨ', '唐揚', 'から揚', 'ハンバーグ', '豚骨', 'カレー'],
    ];
}

function recommend_style_matches($food, $style) {
    $words = recommend_style_words();
    $keys = $words[$style] ?? [$style];
    foreach ($keys as $word) {
        if ($word !== '' && mb_strpos($food, $word) !== false) {
            return true;
        }
    }
    return false;
}

function recommend_matched_styles($user, $food) {
    $matched = [];
    foreach ((array) ($user['styles'] ?? []) as $style) {
        $style = trim((string) $style);
        if ($style !== '' && recommend_style_matches($food, $style)) {
            $matched[] = $style;
        }
    }
    return $matched;
}

function recommend_style_bonus($user, $food) {
    $bonus = 0;
    foreach (recommend_matched_styles($user, $food) as $style) {
        $bonus += 2;
        if ($bonus >= 4) {
            break;
        }
    }
    return $bonus;
}

function recommend_followup_bonus($user, $food) {
    $followups = is_array($user['followups'] ?? null) ? $user['followups'] : [];
    $bonus = 0;
    $meat = line_food_matches($food, '肉');
    $fish = line_food_matches($food, '魚');
    $choice = (string) ($followups['meat_fish'] ?? '');
    if ($choice === 'meat' && $meat && !$fish) {
        $bonus += 1;
    } elseif ($choice === 'fish' && $fish && !$meat) {
        $bonus += 1;
    } elseif ($choice === 'both' && ($meat || $fish)) {
        $bonus += 1;
    }
    $spicy = recommend_style_matches($food, '辛い料理');
    if (($followups['spicy'] ?? '') === 'yes' && $spicy) {
        $bonus += 1;
    } elseif (($followups['spicy'] ?? '') === 'no' && $spicy) {
        $bonus -= 2;
    }
    $hearty = recommend_style_matches($food, 'こってり');
    if (($followups['hearty'] ?? '') === 'yes' && $hearty) {
        $bonus += 1;
    } elseif (($followups['hearty'] ?? '') === 'no' && $hearty) {
        $bonus -= 1;
    }
    return $bonus;
}

function recommend_personal_score($user, $food, $votes) {
    if (!is_array($user) || $food === '') {
        return 0;
    }
    $base = line_score_user($user, $food) - recommend_times_bonus($user);
    foreach ((array) ($user['allergies'] ?? []) as $allergy) {
        $allergy = trim((string) $allergy);
        if ($allergy !== '' && line_allergy_matches($food, $allergy)) {
            $base += 8;
        }
    }
    $base += recommend_style_bonus($user, $food);
    $base += recommend_followup_bonus($user, $food);
    return $base + recommend_delta_from_votes($votes, (string) ($user['userId'] ?? ''), $food);
}

function recommend_menu_allergens($food) {
    static $rows = null;
    if ($rows === null) {
        $data = line_read_json(line_data_dir() . '/allergies.json', []);
        $rows = is_array($data['allergies'] ?? null) ? $data['allergies'] : [];
    }
    $core = recommend_core_name($food);
    $partial = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $menu = trim((string) ($row['menu'] ?? ''));
        if ($menu === '') {
            continue;
        }
        $allergens = is_array($row['allergens'] ?? null) ? $row['allergens'] : [];
        if ($menu === $food || $menu === $core) {
            return $allergens;
        }
        if ($partial === null && mb_strlen($menu) >= 2 && (mb_strpos($food, $menu) !== false || ($core !== '' && mb_strpos($core, $menu) !== false))) {
            $partial = $allergens;
        }
    }
    return $partial;
}

function recommend_allergy_hits($user, $food) {
    $hits = [];
    $listed = recommend_menu_allergens($food);
    foreach ((array) ($user['allergies'] ?? []) as $allergy) {
        $allergy = trim((string) $allergy);
        if ($allergy === '') {
            continue;
        }
        $hit = line_allergy_matches($food, $allergy);
        if (!$hit && is_array($listed)) {
            foreach ($listed as $item) {
                $item = trim((string) $item);
                if ($item === '') {
                    continue;
                }
                if ($item === $allergy || mb_strpos($item, $allergy) !== false || mb_strpos($allergy, $item) !== false || line_allergy_matches($item, $allergy)) {
                    $hit = true;
                    break;
                }
            }
        }
        if ($hit) {
            $hits[] = $allergy;
        }
    }
    return $hits;
}

function recommend_allergy_unconfirmed($user, $food) {
    if (!is_array($user)) {
        return false;
    }
    $has = false;
    foreach ((array) ($user['allergies'] ?? []) as $allergy) {
        if (trim((string) $allergy) !== '') {
            $has = true;
            break;
        }
    }
    if (!$has || recommend_allergy_hits($user, $food)) {
        return false;
    }
    return recommend_menu_allergens($food) === null;
}

function recommend_score_label($fit, $score) {
    if ($fit === 'blocked') {
        return '対象外';
    }
    if ($fit === 'holiday') {
        return '休業日';
    }
    if ($fit === 'empty') {
        return '未定';
    }
    if ($fit === 'plain') {
        return 'おすすめ';
    }
    if ($score >= 5) {
        return 'とてもおすすめ';
    }
    if ($score >= 3) {
        return 'おすすめ';
    }
    if ($score >= 1) {
        return 'ややおすすめ';
    }
    if ($score < 0) {
        return '控えめ';
    }
    return '新しい出会い';
}

function recommend_matched_likes($user, $food) {
    $matched = [];
    foreach ((array) ($user['likes'] ?? []) as $like) {
        $like = trim((string) $like);
        if ($like !== '' && line_food_matches($food, $like)) {
            $matched[] = $like;
        }
    }
    usort($matched, function ($a, $b) {
        return mb_strlen($b) <=> mb_strlen($a);
    });
    return $matched;
}

function recommend_matched_avoids($user, $food) {
    $matched = [];
    foreach ((array) ($user['avoids'] ?? []) as $avoid) {
        $avoid = trim((string) $avoid);
        if ($avoid === '' || $avoid === 'とくにない') {
            continue;
        }
        if (line_food_matches($food, $avoid)) {
            $matched[] = $avoid;
        }
    }
    return $matched;
}

function recommend_feedback_example($votes, $userId, $food, $kind) {
    $found = null;
    foreach ((array) $votes as $vote) {
        if (!is_array($vote) || (string) ($vote['userId'] ?? '') !== $userId) {
            continue;
        }
        if ((string) ($vote['vote'] ?? '') !== $kind) {
            continue;
        }
        $other = (string) ($vote['food'] ?? '');
        if ($other !== $food && !recommend_foods_related($other, $food)) {
            continue;
        }
        $downReason = (string) ($vote['reason'] ?? '');
        if ($kind === 'down' && ($downReason === 'had_before' || $downReason === 'want_other') && $other !== $food) {
            continue;
        }
        $found = $vote;
    }
    return $found;
}

function recommend_reason_text($user, $food, $votes, $repeatCount) {
    $userId = (string) ($user['userId'] ?? '');
    $allergies = recommend_allergy_hits($user, $food);
    if ($allergies) {
        return 'アンケートでアレルギーに「' . implode('、', $allergies) . '」と答えているため、この定食はおすすめしません。';
    }
    $likes = recommend_matched_likes($user, $food);
    $avoids = recommend_matched_avoids($user, $food);
    $upFood = recommend_feedback_example($votes, $userId, $food, 'up');
    $downFood = recommend_feedback_example($votes, $userId, $food, 'down');
    $score = recommend_personal_score($user, $food, $votes);
    $styles = recommend_matched_styles($user, $food);
    if ($avoids && $score < 0) {
        $text = 'あなたは' . $avoids[0] . 'が苦手と答えているので、今回の' . $food . 'はおすすめ度を下げています。';
    } elseif (is_array($upFood)) {
        if ((string) ($upFood['food'] ?? '') === $food) {
            $text = '以前、この定食に「好き」と答えていたので、今回の' . $food . 'もおすすめです。';
        } else {
            $label = recommend_shared_label((string) ($upFood['food'] ?? ''), $food);
            if ($label !== '') {
                $text = '以前、' . $label . 'に高評価を付けていたので、今回の' . $food . 'もおすすめです。';
            } else {
                $text = '以前、似た定食に高評価を付けていたので、今回の' . $food . 'もおすすめです。';
            }
        }
    } elseif ($likes && $score > 0) {
        $shown = array_slice($likes, 0, 2);
        $text = 'あなたは' . implode('と', $shown) . 'が好きなので、今回の' . $food . 'は特におすすめです。';
    } elseif ($styles && $score > 0) {
        $text = 'あなたは' . $styles[0] . 'が好きなので、今回の' . $food . 'をおすすめします。';
    } elseif (is_array($downFood)) {
        $downName = (string) ($downFood['food'] ?? '');
        $downReason = (string) ($downFood['reason'] ?? '');
        if (($downReason === 'had_before' || $downReason === 'want_other') && $downName === $food) {
            $text = '以前、この定食は別の料理がよかったと答えていたので、少し控えめにしています。';
        } elseif ($downName === $food) {
            $text = '以前、この定食は好みじゃないと答えていたので、おすすめ度を少し下げています。';
        } else {
            $text = '以前、' . $downName . 'は好みじゃないと答えていたので、おすすめ度を少し下げています。';
        }
    } elseif ($score > 0) {
        $text = 'これまでの回答から、今回の' . $food . 'は合いそうです。';
    } else {
        $text = 'まだ好みの情報が少ないので、新しい定食として紹介しています。';
    }
    if ($repeatCount >= 2 && $score >= 0) {
        $text .= '同じ定食ばかりにならないよう、ほかの日も見ています。';
    }
    if (recommend_allergy_unconfirmed($user, $food)) {
        $text .= 'この定食の原材料データがないため、アレルギーが大丈夫とは判断できません。食べる前に確認してください。';
    }
    return $text;
}

function recommend_fit($user, $food, $votes) {
    if ($food === '') {
        return 'empty';
    }
    if (is_array($user) && recommend_allergy_hits($user, $food)) {
        return 'blocked';
    }
    if (!is_array($user)) {
        return 'plain';
    }
    $score = recommend_personal_score($user, $food, $votes);
    if ($score > 0) {
        return 'match';
    }
    if ($score < 0) {
        return 'low';
    }
    return 'explore';
}

function recommend_safe_page($url) {
    $url = (string) $url;
    if (preg_match('#^https://pixabay\.com/#', $url)) {
        return $url;
    }
    return '';
}

function recommend_image_payload($image) {
    $empty = [
        'status' => 'pending',
        'url' => '',
        'kind' => 'none',
        'method' => '',
        'pageUrl' => '',
        'author' => '',
        'credit' => '',
    ];
    if (!is_array($image)) {
        return $empty;
    }
    $status = (string) ($image['status'] ?? 'pending');
    if ($status === 'ready' && !empty($image['publicUrl'])) {
        return [
            'status' => 'ready',
            'url' => (string) $image['publicUrl'],
            'kind' => 'pixabay',
            'method' => (string) ($image['method'] ?? ''),
            'pageUrl' => recommend_safe_page($image['pageURL'] ?? ''),
            'author' => (string) ($image['user'] ?? ''),
            'credit' => 'Pixabay',
        ];
    }
    if ($status === 'skipped' || $status === 'error') {
        $empty['status'] = $status;
        $empty['method'] = (string) ($image['method'] ?? '');
        return $empty;
    }
    return $empty;
}

function recommend_image_plan_version() {
    return 2;
}

function recommend_image_log($food, $event, $detail) {
    $food = str_replace(["\r", "\n", "\t"], ' ', (string) $food);
    $detail = str_replace(["\r", "\n", "\t"], ' ', (string) $detail);
    if (preg_match('/key|token|secret|bearer|api[_-]?key/i', $detail)) {
        $detail = 'redacted';
    }
    $file = line_data_dir() . '/image-search.log';
    $line = date('c') . "\t" . $event . "\t" . $food . "\t" . $detail . "\n";
    $size = @filesize($file);
    if ($size !== false && $size > 200000) {
        $tail = @file_get_contents($file, false, null, max(0, $size - 120000));
        if (is_string($tail)) {
            $cut = strpos($tail, "\n");
            if ($cut !== false) {
                $tail = substr($tail, $cut + 1);
            }
            @file_put_contents($file, $tail . $line, LOCK_EX);
            return;
        }
    }
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function recommend_image_fresh($image) {
    if (!is_array($image)) {
        return false;
    }
    $status = (string) ($image['status'] ?? '');
    if ($status === 'ready' && !empty($image['publicUrl'])) {
        return true;
    }
    $until = (int) ($image['until'] ?? 0);
    if (($status === 'skipped' || $status === 'error') && $until > time()) {
        if ((int) ($image['plan'] ?? 0) < recommend_image_plan_version()) {
            return false;
        }
        return true;
    }
    return false;
}

function recommend_date_label($date) {
    $stamp = strtotime($date . ' 00:00:00');
    $names = ['日', '月', '火', '水', '木', '金', '土'];
    $index = (int) date('w', $stamp);
    return date('n月j日', $stamp) . '（' . $names[$index] . '）';
}

function recommend_pick_open_dates($startDate, $isClosed, $limit = 2) {
    $days = [];
    $cursor = strtotime($startDate . ' 00:00:00');
    if ($cursor === false) {
        return $days;
    }
    for ($i = 0; $i < 45 && count($days) < $limit; $i++) {
        $date = date('Y-m-d', $cursor);
        if (!$isClosed($date)) {
            $days[] = [$date, $date === $startDate ? 'today' : 'later'];
        }
        $cursor = strtotime('+1 day', $cursor);
    }
    return $days;
}

function recommend_days() {
    return recommend_pick_open_dates(date('Y-m-d'), function ($date) {
        return line_holiday($date) !== null;
    });
}

function recommend_day_word($date) {
    if ($date === date('Y-m-d')) {
        return '今日';
    }
    if ($date === date('Y-m-d', strtotime('+1 day'))) {
        return '明日';
    }
    return '';
}

function recommend_food_is_current($food) {
    $span = recommend_pick_open_dates(date('Y-m-d'), function ($date) {
        return line_holiday($date) !== null;
    }, 21);
    foreach ($span as $day) {
        if (line_daily_food($day[0]) === $food) {
            return true;
        }
    }
    return false;
}

function recommend_public_vote($row) {
    if (!is_array($row)) {
        return null;
    }
    return [
        'date' => (string) ($row['date'] ?? ''),
        'food' => (string) ($row['food'] ?? ''),
        'vote' => (string) ($row['vote'] ?? ''),
        'reason' => (string) ($row['reason'] ?? ''),
    ];
}

function recommend_repeat_count($impressions, $userId, $food) {
    $count = 0;
    foreach ((array) $impressions as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['userId'] ?? '') === $userId && (string) ($row['food'] ?? '') === $food) {
            $count++;
        }
    }
    return $count;
}

function recommend_my_vote($votes, $userId, $date, $food) {
    $fallback = null;
    foreach ((array) $votes as $vote) {
        if (!is_array($vote)) {
            continue;
        }
        if ((string) ($vote['userId'] ?? '') !== $userId || (string) ($vote['food'] ?? '') !== $food) {
            continue;
        }
        $public = recommend_public_vote($vote);
        if ((string) ($vote['date'] ?? '') === $date) {
            return $public;
        }
        $fallback = $public;
    }
    return $fallback;
}

function recommend_card($date, $when, $user, $votes, $impressions) {
    $label = recommend_date_label($date);
    $holiday = line_holiday($date);
    $base = [
        'date' => $date,
        'when' => $when,
        'label' => $label,
        'dayWord' => recommend_day_word($date),
        'food' => '',
        'holiday' => $holiday,
        'blurb' => '',
        'reason' => '',
        'fit' => 'holiday',
        'score' => null,
        'scoreLabel' => '休業日',
        'image' => recommend_image_payload(null),
        'vote' => null,
        'canVote' => false,
        'reserve' => 'later',
        'reserveName' => is_array($user) ? (string) ($user['name'] ?? '') : '',
    ];
    if ($holiday !== null) {
        $base['blurb'] = 'この日は休業日です。';
        $base['reason'] = '休業日のため、おすすめはありません。理由: ' . $holiday;
        $base['image'] = recommend_image_payload(['status' => 'skipped', 'method' => 'none']);
        return $base;
    }
    $food = line_daily_food($date);
    if ($food === '') {
        $base['fit'] = 'empty';
        $base['scoreLabel'] = '未定';
        $base['blurb'] = 'この日の定食は、まだ決まっていません。';
        $base['reason'] = '定食が登録されたあとで、あなた向けの理由を出します。';
        $base['image'] = recommend_image_payload(['status' => 'skipped', 'method' => 'none']);
        return $base;
    }
    $base['food'] = $food;
    $base['blurb'] = recommend_menu_pitch($food);
    $base['reserve'] = $when === 'today' ? 'today' : 'later';
    $userId = is_array($user) ? (string) ($user['userId'] ?? '') : '';
    if (!is_array($user) || $userId === '') {
        $base['fit'] = 'plain';
        $base['scoreLabel'] = 'おすすめ';
        $base['reason'] = 'あなた向けの理由を出すには、LINEのトークからこのページを開いてください。';
        $base['image'] = recommend_image_payload(null);
        $cached = recommend_cached_image($food);
        if ($cached !== null) {
            $base['image'] = $cached;
        }
        return $base;
    }
    $repeat = recommend_repeat_count($impressions, $userId, $food);
    $base['fit'] = recommend_fit($user, $food, $votes);
    $base['score'] = recommend_personal_score($user, $food, $votes);
    $base['scoreLabel'] = recommend_score_label($base['fit'], $base['score']);
    $base['reason'] = recommend_reason_text($user, $food, $votes, $repeat);
    $base['vote'] = recommend_my_vote($votes, $userId, $date, $food);
    $base['canVote'] = $base['fit'] !== 'blocked';
    if ($base['fit'] === 'blocked') {
        $base['reserve'] = 'later';
    }
    $base['image'] = recommend_image_payload(null);
    $cached = recommend_cached_image($food);
    if ($cached !== null) {
        $base['image'] = $cached;
    }
    return $base;
}

function recommend_cached_image($food) {
    $assets = recommend_load_doc(recommend_assets_file(), ['menus' => []]);
    if ($assets === null) {
        return null;
    }
    $assets = recommend_normalize_assets($assets);
    $row = $assets['menus'][$food] ?? [];
    if (!is_array($row)) {
        return null;
    }
    $image = $row['image'] ?? null;
    if (!is_array($image) || !recommend_image_fresh($image)) {
        return null;
    }
    return recommend_image_payload($image);
}

function recommend_menu_source_error() {
    $menu = cafeteria_load(line_data_dir() . '/daily-menu.json');
    if ($menu['state'] === 'error') {
        return cafeteria_last_error() !== '' ? cafeteria_last_error() : '定食を読めませんでした。';
    }
    $holiday = cafeteria_load(line_data_dir() . '/holidays.json');
    if ($holiday['state'] === 'error') {
        return cafeteria_last_error() !== '' ? cafeteria_last_error() : '休みの日を読めませんでした。';
    }
    return '';
}

function recommend_payload($user) {
    if (recommend_menu_source_error() !== '') {
        return [
            'ok' => true,
            'personalized' => is_array($user) && !empty($user['userId']),
            'name' => is_array($user) ? (string) ($user['name'] ?? '') : '',
            'cards' => [],
            'followup' => null,
            'menuError' => '保存している定食を読み込めませんでした。時間をおいて、もう一度開いてください。',
        ];
    }
    $votes = [];
    $impressions = [];
    $feedback = recommend_load_doc(recommend_feedback_file(), recommend_feedback_default());
    if (is_array($feedback)) {
        $feedback = recommend_normalize_feedback($feedback);
        $votes = $feedback['votes'];
        $impressions = $feedback['impressions'];
    }
    $cards = [];
    $slots = [];
    $today = date('Y-m-d');
    $scoreOf = function ($food) use ($user, $votes, $impressions) {
        return recommend_rank_score(is_array($user) ? $user : null, $food, $votes, $impressions);
    };
    foreach (recommend_choose_slots(recommend_week_rows(is_array($user) ? $user : null), $today, $scoreOf) as $picked) {
        $row = $picked['row'];
        $slots[] = [
            'date' => $row['date'],
            'when' => $row['date'] === $today ? 'today' : 'later',
            'listTitle' => recommend_slot_title($picked['role']),
            'prominent' => $picked['role'] === 'week' || $picked['role'] === 'week-today',
        ];
    }
    foreach ($slots as $slot) {
        try {
            $card = recommend_card($slot['date'], $slot['when'], is_array($user) ? $user : null, $votes, $impressions);
        } catch (Throwable $e) {
            $card = [
                'date' => $slot['date'],
                'when' => $slot['when'],
                'label' => recommend_date_label($slot['date']),
                'dayWord' => recommend_day_word($slot['date']),
                'food' => '',
                'holiday' => null,
                'blurb' => 'この日のおすすめを表示できませんでした。',
                'reason' => '',
                'fit' => 'empty',
                'image' => recommend_image_payload(['status' => 'error', 'method' => 'none']),
                'vote' => null,
                'canVote' => false,
                'reserve' => 'later',
                'reserveName' => '',
            ];
        }
        $card['listTitle'] = $slot['listTitle'];
        $card['prominent'] = !empty($slot['prominent']);
        if ($card['date'] !== $today || $card['food'] === '' || $card['fit'] === 'holiday') {
            $card['reserve'] = 'later';
        }
        $cards[] = $card;
    }
    if (is_array($user) && !empty($user['userId'])) {
        recommend_record_impressions($user, $cards);
    }
    return [
        'ok' => true,
        'personalized' => is_array($user) && !empty($user['userId']),
        'name' => is_array($user) ? (string) ($user['name'] ?? '') : '',
        'followup' => is_array($user) ? recommend_followup_question($user) : null,
        'cards' => $cards,
    ];
}

function recommend_user_mentions($user, $words) {
    $bag = array_merge(
        (array) ($user['likes'] ?? []),
        (array) ($user['avoids'] ?? []),
        (array) ($user['styles'] ?? [])
    );
    foreach ($bag as $label) {
        $label = (string) $label;
        foreach ($words as $word) {
            if ($label !== '' && $word !== '' && mb_strpos($label, $word) !== false) {
                return true;
            }
        }
    }
    return false;
}

function recommend_followup_question($user) {
    if (!is_array($user) || empty($user['userId']) || !line_survey_finished($user)) {
        return null;
    }
    $asked = strtotime((string) ($user['followupAskedAt'] ?? ''));
    if ($asked && $asked > time() - 7 * 86400) {
        return null;
    }
    $done = is_array($user['followups'] ?? null) ? $user['followups'] : [];
    $meat = recommend_user_mentions($user, ['肉', '豚', '鶏', 'から揚', '唐揚', 'カツ', 'ハンバーグ']);
    $fish = recommend_user_mentions($user, ['魚', 'サバ', '鮭', '刺身']);
    if (!isset($done['meat_fish']) && ($meat === $fish)) {
        return [
            'id' => 'meat_fish',
            'prompt' => '肉料理と魚料理、どちらが好き？',
            'choices' => [
                ['id' => 'meat', 'label' => '肉料理'],
                ['id' => 'fish', 'label' => '魚料理'],
                ['id' => 'both', 'label' => 'どっちも'],
                ['id' => 'skip', 'label' => '今は答えない'],
            ],
        ];
    }
    $knowsSpicy = recommend_user_mentions($user, ['辛']);
    if (!isset($done['spicy']) && !$knowsSpicy) {
        return [
            'id' => 'spicy',
            'prompt' => '辛い料理は好き？',
            'choices' => [
                ['id' => 'yes', 'label' => '好き'],
                ['id' => 'no', 'label' => '苦手'],
                ['id' => 'skip', 'label' => '今は答えない'],
            ],
        ];
    }
    if (!isset($done['hearty'])) {
        return [
            'id' => 'hearty',
            'prompt' => 'ボリュームのある料理は好き？',
            'choices' => [
                ['id' => 'yes', 'label' => '好き'],
                ['id' => 'no', 'label' => '軽めがいい'],
                ['id' => 'skip', 'label' => '今は答えない'],
            ],
        ];
    }
    if (!isset($done['try_new'])) {
        return [
            'id' => 'try_new',
            'prompt' => '新しい料理も紹介していい？',
            'choices' => [
                ['id' => 'yes', 'label' => '紹介して'],
                ['id' => 'no', 'label' => 'いつものがいい'],
                ['id' => 'skip', 'label' => '今は答えない'],
            ],
        ];
    }
    return null;
}

function recommend_record_impressions($user, $cards) {
    $userId = (string) ($user['userId'] ?? '');
    if ($userId === '') {
        return;
    }
    recommend_merge_feedback(function ($data) use ($userId, $cards) {
        $now = time();
        $kept = [];
        foreach ($data['impressions'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $at = (int) ($row['at'] ?? 0);
            if ($at > 0 && $now - $at > 120 * 86400) {
                continue;
            }
            $kept[] = $row;
        }
        $data['impressions'] = $kept;
        foreach ($cards as $card) {
            $food = (string) ($card['food'] ?? '');
            $date = (string) ($card['date'] ?? '');
            if ($food === '' || $date === '') {
                continue;
            }
            $exists = false;
            foreach ($data['impressions'] as $row) {
                if ((string) ($row['userId'] ?? '') === $userId
                    && (string) ($row['date'] ?? '') === $date
                    && (string) ($row['food'] ?? '') === $food) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $data['impressions'][] = [
                    'userId' => $userId,
                    'date' => $date,
                    'food' => $food,
                    'at' => $now,
                ];
            }
        }
        return $data;
    });
}

function recommend_reason_allowed($vote) {
    if ($vote === 'up') {
        return ['liked_food', 'want_to_try', 'fits_me', ''];
    }
    if ($vote === 'down') {
        return ['dislike_dish', 'dislike_ingredient', 'dislike_seasoning', 'want_other', 'other', 'disliked_ingredient', 'not_my_taste', 'had_before', 'not_interested', ''];
    }
    return [];
}

function recommend_fail($status, $error) {
    return ['ok' => false, 'status' => $status, 'error' => $error];
}

function recommend_submit_feedback($input) {
    $key = (string) ($input['key'] ?? '');
    if ($key === '' || strlen($key) > 80) {
        return recommend_fail(404, 'このリンクでは送れません。LINEのトークから開いてください。');
    }
    $user = line_user_by_key($key);
    if (!is_array($user) || empty($user['userId'])) {
        return recommend_fail(404, 'このリンクでは送れません。LINEのトークから開いてください。');
    }
    $date = (string) ($input['date'] ?? '');
    $food = trim((string) ($input['food'] ?? ''));
    $vote = (string) ($input['vote'] ?? '');
    $reason = trim((string) ($input['reason'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return recommend_fail(400, '日付が正しくありません。');
    }
    $allowed = recommend_visible_dates($user);
    if (!isset($allowed[$date])) {
        return recommend_fail(400, '表示中の定食だけ評価できます。');
    }
    if (line_holiday($date) !== null) {
        return recommend_fail(400, '休みの日は評価できません。');
    }
    if ($food === '' || mb_strlen($food) > 80 || line_daily_food($date) !== $food) {
        return recommend_fail(400, 'その定食は、この日のメニューと違います。');
    }
    if ($vote !== 'up' && $vote !== 'down') {
        return recommend_fail(400, '合っているか、あまり合わないかを選んでください。');
    }
    if ($reason !== '' && !in_array($reason, recommend_reason_allowed($vote), true)) {
        return recommend_fail(400, '理由をもう一度選んでください。');
    }
    $saved = recommend_merge_feedback(function ($data) use ($user, $date, $food, $vote, $reason) {
        $userId = (string) $user['userId'];
        $next = [
            'userId' => $userId,
            'date' => $date,
            'food' => $food,
            'vote' => $vote,
            'reason' => $reason,
            'updatedAt' => date('c'),
        ];
        $replaced = false;
        foreach ($data['votes'] as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['userId'] ?? '') === $userId && (string) ($row['food'] ?? '') === $food) {
                $data['votes'][$index] = $next;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $data['votes'][] = $next;
        }
        if (count($data['votes']) > 5000) {
            $data['votes'] = array_slice($data['votes'], -5000);
        }
        return $data;
    });
    if ($saved === null) {
        return recommend_fail(503, '保存できませんでした。時間をおいてもう一度押してください。');
    }
    $userId = (string) $user['userId'];
    return ['ok' => true, 'vote' => recommend_my_vote($saved['votes'], $userId, $date, $food)];
}

function recommend_reason_label($code) {
    $labels = [
        'liked_food' => '好きな料理だった',
        'want_to_try' => '食べてみたいと思った',
        'fits_me' => '自分に合っていると思った',
        'dislike_dish' => '料理が苦手',
        'dislike_ingredient' => '食材が苦手',
        'dislike_seasoning' => '味付けが好みではない',
        'want_other' => '今日は別の料理が食べたい',
        'disliked_ingredient' => '苦手な食材が含まれている',
        'not_my_taste' => '自分の好みとは違った',
        'had_before' => '以前にも食べたことがある',
        'not_interested' => 'あまり興味がない',
        'other' => 'その他',
        '' => '理由なし',
    ];
    return $labels[$code] ?? 'その他';
}

function recommend_admin_stats() {
    $feedback = recommend_load_doc(recommend_feedback_file(), recommend_feedback_default());
    if ($feedback === null) {
        return ['ok' => false, 'error' => '評価を読めませんでした。'];
    }
    $feedback = recommend_normalize_feedback($feedback);
    $people = [];
    foreach ($feedback['impressions'] as $row) {
        if (is_array($row) && !empty($row['userId'])) {
            $people[(string) $row['userId']] = true;
        }
    }
    $up = 0;
    $down = 0;
    $genres = [];
    $mismatches = [];
    foreach ($feedback['votes'] as $vote) {
        if (!is_array($vote)) {
            continue;
        }
        $kind = (string) ($vote['vote'] ?? '');
        if ($kind !== 'up' && $kind !== 'down') {
            continue;
        }
        if ($kind === 'up') {
            $up++;
        } else {
            $down++;
            $code = (string) ($vote['reason'] ?? '');
            $label = recommend_reason_label($code);
            if (!isset($mismatches[$label])) {
                $mismatches[$label] = 0;
            }
            $mismatches[$label]++;
        }
        $labels = recommend_specific_labels((string) ($vote['food'] ?? ''));
        if (!count($labels)) {
            $labels = ['その他'];
        }
        foreach ($labels as $label) {
            if (!isset($genres[$label])) {
                $genres[$label] = ['label' => $label, 'up' => 0, 'down' => 0];
            }
            $genres[$label][$kind]++;
        }
    }
    $genreList = array_values($genres);
    usort($genreList, function ($a, $b) {
        return ($b['up'] + $b['down']) <=> ($a['up'] + $a['down']);
    });
    $mismatchList = [];
    foreach ($mismatches as $label => $count) {
        $mismatchList[] = ['label' => $label, 'count' => $count];
    }
    usort($mismatchList, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    $total = $up + $down;
    $images = [];
    $assets = recommend_load_doc(recommend_assets_file(), ['menus' => []]);
    $menus = [];
    if (is_array($assets)) {
        $assets = recommend_normalize_assets($assets);
        $menus = $assets['menus'];
    }
    foreach (recommend_days() as $day) {
        if (line_holiday($day[0]) !== null) {
            continue;
        }
        $food = line_daily_food($day[0]);
        if ($food === '') {
            continue;
        }
        $image = is_array($menus[$food]['image'] ?? null) ? $menus[$food]['image'] : [];
        $images[] = [
            'date' => $day[0],
            'food' => $food,
            'status' => (string) ($image['status'] ?? 'pending'),
            'method' => (string) ($image['method'] ?? ''),
            'stored' => !empty($image['publicUrl']),
        ];
    }
    return [
        'ok' => true,
        'people' => count($people),
        'up' => $up,
        'down' => $down,
        'rate' => $total > 0 ? (int) round($up * 100 / $total) : null,
        'genres' => $genreList,
        'mismatches' => $mismatchList,
        'images' => $images,
    ];
}

function recommend_hit_score($food, $hit) {
    $tags = strtolower((string) ($hit['tags'] ?? ''));
    $score = 0;
    $core = recommend_core_name($food);
    if (mb_strlen($core) >= 2 && mb_strpos((string) ($hit['tags'] ?? ''), $core) !== false) {
        $score += 6;
    }
    $english = strtolower(recommend_english_query($food));
    $stop = ['japanese' => true, 'food' => true, 'meal' => true, 'set' => true, 'with' => true, 'and' => true, 'the' => true, 'dish' => true, 'lunch' => true, 'photo' => true];
    $added = 0;
    foreach (preg_split('/\s+/', $english) as $word) {
        if ($word === '' || isset($stop[$word]) || strlen($word) < 4) {
            continue;
        }
        if (strpos($tags, $word) !== false) {
            $score += 5;
            $added++;
            if ($added >= 2) {
                break;
            }
        }
    }
    $width = (int) ($hit['imageWidth'] ?? 0);
    $height = (int) ($hit['imageHeight'] ?? 0);
    if ($width >= 640 && $height >= 360) {
        $score += 1;
    }
    foreach (['watermark', 'logo', 'portrait', 'screenshot'] as $bad) {
        if (strpos($tags, $bad) !== false) {
            $score -= 3;
        }
    }
    if (recommend_tag_conflict($food, $tags)) {
        $score -= 6;
    }
    return $score;
}

function recommend_tag_conflict($food, $tags) {
    $rules = [
        [['鶏', 'チキン'], ['salmon', 'beef', 'pork', 'ramen', 'shrimp', 'eel']],
        [['豚', '生姜焼き', 'しょうが'], ['salmon', 'chicken', 'beef', 'ramen', 'shrimp']],
        [['牛'], ['salmon', 'chicken', 'pork', 'ramen']],
        [['魚', 'サバ', 'さば', '鮭', 'イワシ'], ['chicken', 'beef', 'pork', 'ramen', 'burger', 'pizza']],
        [['ラーメン'], ['steak', 'curry', 'salad', 'sushi']],
        [['カレー'], ['ramen', 'sushi', 'steak']],
    ];
    foreach ($rules as $rule) {
        $applies = false;
        foreach ($rule[0] as $needle) {
            if (mb_strpos($food, $needle) !== false) {
                $applies = true;
                break;
            }
        }
        if (!$applies) {
            continue;
        }
        foreach ($rule[1] as $bad) {
            if (strpos($tags, $bad) !== false) {
                return true;
            }
        }
    }
    return false;
}

function recommend_score_pick($food, $hits) {
    $best = null;
    $bestScore = -100;
    foreach ($hits as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $score = recommend_hit_score($food, $hit);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $hit;
        }
    }
    if ($best === null || $bestScore < 5) {
        return null;
    }
    return $best;
}

function recommend_search_is_weak($food, $hits) {
    if (count($hits) < 3) {
        return true;
    }
    $best = -100;
    foreach ($hits as $hit) {
        $score = recommend_hit_score($food, $hit);
        if ($score > $best) {
            $best = $score;
        }
    }
    return $best < 5;
}

function recommend_pixabay_search($query, $lang) {
    $key = trim(app_env('PIXABAY_API_KEY'));
    $empty = ['ok' => false, 'code' => 0, 'hits' => [], 'error' => 'no-key'];
    if ($key === '' || $query === '') {
        return $empty;
    }
    $params = [
        'key' => $key,
        'q' => mb_substr($query, 0, 100),
        'lang' => $lang === 'en' ? 'en' : 'ja',
        'image_type' => 'photo',
        'category' => 'food',
        'orientation' => 'horizontal',
        'safesearch' => 'true',
        'order' => 'popular',
        'per_page' => '10',
    ];
    $url = 'https://pixabay.com/api/?' . http_build_query($params);
    [$ok, $code, $raw] = recommend_request($url, 'GET', ['Accept: application/json'], null, 12);
    if ($code === 429) {
        return ['ok' => false, 'code' => 429, 'hits' => [], 'error' => '429'];
    }
    if (!$ok) {
        return ['ok' => false, 'code' => $code, 'hits' => [], 'error' => 'http'];
    }
    $json = json_decode($raw, true);
    $rows = is_array($json['hits'] ?? null) ? $json['hits'] : [];
    $hits = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $hits[] = [
            'id' => (int) $row['id'],
            'tags' => mb_substr((string) ($row['tags'] ?? ''), 0, 180),
            'pageURL' => mb_substr((string) ($row['pageURL'] ?? ''), 0, 300),
            'previewURL' => mb_substr((string) ($row['previewURL'] ?? ''), 0, 300),
            'webformatURL' => mb_substr((string) ($row['webformatURL'] ?? ''), 0, 300),
            'largeImageURL' => mb_substr((string) ($row['largeImageURL'] ?? ''), 0, 300),
            'user' => mb_substr((string) ($row['user'] ?? ''), 0, 80),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'imageWidth' => (int) ($row['imageWidth'] ?? 0),
            'imageHeight' => (int) ($row['imageHeight'] ?? 0),
            'query' => mb_substr($query, 0, 100),
        ];
        if (count($hits) >= 10) {
            break;
        }
    }
    return ['ok' => true, 'code' => $code, 'hits' => $hits, 'error' => ''];
}

function recommend_collect_hits($food, $cached) {
    $version = recommend_image_plan_version();
    if (is_array($cached)
        && (int) ($cached['plan'] ?? 0) >= $version
        && (int) ($cached['until'] ?? 0) > time()
        && isset($cached['hits'])
        && is_array($cached['hits'])) {
        return [$cached, array_slice($cached['hits'], 0, 8)];
    }
    if (trim(app_env('PIXABAY_API_KEY')) === '') {
        recommend_image_log($food, 'search', 'no-key');
        return [['until' => 0, 'plan' => $version, 'queries' => [], 'hits' => [], 'error' => 'no-key'], []];
    }
    $plan = recommend_search_plan($food);
    $hits = [];
    $queries = [];
    $error = '';
    foreach ($plan as $item) {
        $best = -100;
        foreach ($hits as $hit) {
            $score = recommend_hit_score($food, $hit);
            if ($score > $best) {
                $best = $score;
            }
        }
        if ($item['lang'] === 'en' && $best >= 8 && count($hits) >= 3) {
            continue;
        }
        $result = recommend_pixabay_search($item['q'], $item['lang']);
        $queries[] = [
            'lang' => $item['lang'],
            'q' => $item['q'],
            'count' => count($result['hits']),
            'code' => $result['code'],
        ];
        if ($result['code'] === 429) {
            $error = '429';
            break;
        }
        if (!$result['ok'] && $result['error'] !== '') {
            $error = $result['error'];
        }
        foreach ($result['hits'] as $hit) {
            $hits[(int) $hit['id']] = $hit;
        }
    }
    $list = array_values($hits);
    usort($list, function ($a, $b) use ($food) {
        return recommend_hit_score($food, $b) <=> recommend_hit_score($food, $a);
    });
    $list = array_slice($list, 0, 8);
    $summary = [];
    foreach ($queries as $query) {
        $summary[] = $query['lang'] . ':' . $query['count'];
    }
    recommend_image_log($food, 'search', implode(',', $summary) . ($error !== '' ? ' error=' . $error : ''));
    if ($error === '429') {
        $until = time() + 3600;
    } elseif ($error !== '' && !count($list)) {
        $until = time() + 1800;
    } else {
        $until = time() + 86400;
    }
    return [[
        'until' => $until,
        'plan' => $version,
        'queries' => $queries,
        'hits' => $list,
        'error' => $error,
    ], $list];
}

function recommend_download($url, $timeout, $maxBytes) {
    if (!is_string($url) || !preg_match('#^https://#', $url)) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || !preg_match('/(^|\.)pixabay\.com$/', $host)) {
        return null;
    }
    [$ok, $code, $raw] = recommend_request($url, 'GET', ['Accept: image/*'], null, $timeout);
    if (!$ok || strlen($raw) < 32 || strlen($raw) > $maxBytes) {
        return null;
    }
    return $raw;
}

function recommend_sniff_mime($binary) {
    if (strncmp($binary, "\xFF\xD8", 2) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($binary, "\x89PNG", 4) === 0) {
        return 'image/png';
    }
    if (strlen($binary) > 12 && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return '';
}

function recommend_ext($mime) {
    if ($mime === 'image/webp') {
        return 'webp';
    }
    if ($mime === 'image/png') {
        return 'png';
    }
    return 'jpg';
}

function recommend_encode_image($image, $type, $quality) {
    $tmp = fopen('php://memory', 'w+b');
    if ($tmp === false) {
        return null;
    }
    $ok = $type === 'webp' ? imagewebp($image, $tmp, $quality) : imagejpeg($image, $tmp, $quality);
    if (!$ok) {
        fclose($tmp);
        return null;
    }
    rewind($tmp);
    $blob = stream_get_contents($tmp);
    fclose($tmp);
    return is_string($blob) && $blob !== '' ? $blob : null;
}

function recommend_scale_image($binary, $maxWidth) {
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return null;
    }
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width < 1 || $height < 1) {
        imagedestroy($src);
        return null;
    }
    $scale = $width > $maxWidth ? $maxWidth / $width : 1;
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($dst === false) {
        imagedestroy($src);
        return null;
    }
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($src);
    return $dst;
}

function recommend_vision_jpeg($binary) {
    if (!function_exists('imagecreatefromstring')) {
        $mime = recommend_sniff_mime($binary);
        if ($mime === 'image/jpeg' && strlen($binary) <= 120000) {
            return $binary;
        }
        return null;
    }
    $dst = recommend_scale_image($binary, 384);
    if ($dst === null) {
        return null;
    }
    $jpg = recommend_encode_image($dst, 'jpeg', 60);
    imagedestroy($dst);
    if (!is_string($jpg) || strlen($jpg) > 200000) {
        return null;
    }
    return $jpg;
}

function recommend_optimize_image($binary) {
    $mime = recommend_sniff_mime($binary);
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $dst = recommend_scale_image($binary, 1000);
        if ($dst !== null) {
            $quality = 80;
            $blob = null;
            do {
                $blob = recommend_encode_image($dst, 'webp', $quality);
                $quality -= 8;
            } while (is_string($blob) && strlen($blob) > 250 * 1024 && $quality >= 56);
            imagedestroy($dst);
            if (is_string($blob) && strlen($blob) > 0 && strlen($blob) <= 700 * 1024) {
                return [$blob, 'image/webp', 'webp'];
            }
        }
    }
    if ($mime !== '' && strlen($binary) <= 250 * 1024) {
        return [$binary, $mime, recommend_ext($mime)];
    }
    return null;
}

function recommend_project_url() {
    $url = cafeteria_clean_secret(app_env('SUPABASE_URL'));
    $url = preg_replace('/\?.*$/', '', $url);
    $url = rtrim((string) $url, '/');
    foreach (['/rest/v1', '/storage/v1'] as $suffix) {
        $pos = strpos($url, $suffix);
        if ($pos !== false) {
            $url = rtrim(substr($url, 0, $pos), '/');
        }
    }
    return $url;
}

function recommend_storage_headers($contentType) {
    $key = cafeteria_key();
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ];
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    return $headers;
}

function recommend_bucket_ready() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!cafeteria_configured()) {
        $ready = false;
        return false;
    }
    $base = recommend_project_url();
    if ($base === '') {
        $ready = false;
        return false;
    }
    [$exists] = recommend_request($base . '/storage/v1/bucket/menu-images', 'GET', recommend_storage_headers(''), null, 10);
    if ($exists) {
        $ready = true;
        return true;
    }
    $payload = json_encode([
        'id' => 'menu-images',
        'name' => 'menu-images',
        'public' => true,
        'file_size_limit' => 1048576,
        'allowed_mime_types' => ['image/webp', 'image/jpeg', 'image/png'],
    ]);
    recommend_request($base . '/storage/v1/bucket', 'POST', recommend_storage_headers('application/json'), $payload, 12);
    $ready = true;
    return true;
}

function recommend_storage_delete($path) {
    if ($path === '' || !cafeteria_configured()) {
        return;
    }
    $url = recommend_project_url() . '/storage/v1/object/menu-images/' . $path;
    recommend_request($url, 'DELETE', recommend_storage_headers(''), null, 10);
}

function recommend_storage_upload($path, $binary, $mime) {
    if (!recommend_bucket_ready()) {
        return '';
    }
    $url = recommend_project_url() . '/storage/v1/object/menu-images/' . $path;
    $headers = recommend_storage_headers($mime);
    $headers[] = 'x-upsert: true';
    $headers[] = 'cache-control: public, max-age=86400';
    [$ok] = recommend_request($url, 'POST', $headers, $binary, 20);
    if (!$ok) {
        return '';
    }
    return recommend_project_url() . '/storage/v1/object/public/menu-images/' . $path;
}

function recommend_blank_image($method, $status, $until, $note) {
    return [
        'status' => $status,
        'method' => $method,
        'confidence' => 'low',
        'pixabayId' => 0,
        'pageURL' => '',
        'user' => '',
        'userId' => '',
        'query' => '',
        'selectedAt' => date('c'),
        'chosenAt' => time(),
        'until' => $until,
        'workingUntil' => 0,
        'storagePath' => '',
        'publicUrl' => '',
        'downloadUrl' => '',
        'menuId' => '',
        'note' => $note,
        'plan' => recommend_image_plan_version(),
    ];
}

function recommend_image_from_hit($food, $hit, $method, $confidence) {
    $download = (string) ($hit['largeImageURL'] ?? '');
    if ($download === '') {
        $download = (string) ($hit['webformatURL'] ?? '');
    }
    return [
        'status' => 'error',
        'method' => $method,
        'confidence' => $confidence,
        'pixabayId' => (int) ($hit['id'] ?? 0),
        'pageURL' => recommend_safe_page($hit['pageURL'] ?? ''),
        'user' => (string) ($hit['user'] ?? ''),
        'userId' => (string) ($hit['user_id'] ?? ''),
        'query' => (string) ($hit['query'] ?? ''),
        'selectedAt' => date('c'),
        'chosenAt' => time(),
        'until' => time() + 1800,
        'workingUntil' => 0,
        'storagePath' => '',
        'publicUrl' => '',
        'downloadUrl' => $download,
        'menuId' => sha1($food),
        'note' => '',
        'plan' => recommend_image_plan_version(),
    ];
}

function recommend_transfer_image($food, $image) {
    $download = (string) ($image['downloadUrl'] ?? '');
    $binary = recommend_download($download, 12, 4 * 1024 * 1024);
    if ($binary === null) {
        $image['status'] = 'error';
        $image['until'] = time() + 1800;
        $image['workingUntil'] = 0;
        $image['note'] = 'download';
        return $image;
    }
    $optimized = recommend_optimize_image($binary);
    if ($optimized === null) {
        $image['status'] = 'skipped';
        $image['until'] = time() + 86400;
        $image['workingUntil'] = 0;
        $image['note'] = 'optimize';
        $image['downloadUrl'] = '';
        return $image;
    }
    [$blob, $mime, $ext] = $optimized;
    $hash = sha1($food);
    $path = 'menus/' . $hash . '.' . $ext;
    $public = recommend_storage_upload($path, $blob, $mime);
    if ($public === '') {
        $image['status'] = 'error';
        $image['until'] = time() + 1800;
        $image['workingUntil'] = 0;
        $image['note'] = 'upload';
        return $image;
    }
    foreach (['webp', 'jpg', 'png'] as $other) {
        $otherPath = 'menus/' . $hash . '.' . $other;
        if ($otherPath !== $path) {
            recommend_storage_delete($otherPath);
        }
    }
    $image['status'] = 'ready';
    $image['storagePath'] = $path;
    $image['publicUrl'] = $public;
    $image['downloadUrl'] = '';
    $image['until'] = 0;
    $image['workingUntil'] = 0;
    $image['menuId'] = sha1($food);
    $image['selectedAt'] = date('c');
    $image['note'] = '';
    return $image;
}

function recommend_vision_prompt($food, $count) {
    return '学校食堂の定食「' . $food . '」の紹介に使う写真を、候補1から候補' . $count . 'の中から1枚だけ選んでください。'
        . '料理名との一致、料理が中央にはっきり写っているか、紹介に向いているか、解像度、不自然な加工や透かしを見てください。'
        . '人気順では選ばないでください。料理の種類が違う写真は選ばないでください。'
        . '確信が高いときだけ pick に番号を入れ、confidence を high にしてください。迷うときは pick を 0、confidence を low にしてください。'
        . 'JSONだけを返してください。例: {"pick":1,"confidence":"high"}';
}

function recommend_vision_pick($food, $hits) {
    $config = recommend_openai_config();
    if (!$config['enabled'] || !recommend_model_accepts_images($config['model'])) {
        return ['mode' => 'unavailable'];
    }
    $prepared = [];
    foreach (array_slice($hits, 0, 8) as $hit) {
        $binary = recommend_download((string) ($hit['webformatURL'] ?? ''), 8, 1500000);
        if ($binary === null) {
            $binary = recommend_download((string) ($hit['previewURL'] ?? ''), 8, 400000);
        }
        if ($binary === null) {
            continue;
        }
        $jpeg = recommend_vision_jpeg($binary);
        if ($jpeg === null) {
            continue;
        }
        $prepared[] = ['hit' => $hit, 'jpeg' => $jpeg];
        if (count($prepared) >= 6) {
            break;
        }
    }
    if (!count($prepared)) {
        return ['mode' => 'unavailable'];
    }
    $content = [
        ['type' => 'text', 'text' => recommend_vision_prompt($food, count($prepared))],
    ];
    foreach ($prepared as $index => $item) {
        $content[] = ['type' => 'text', 'text' => '候補' . ($index + 1)];
        $content[] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:image/jpeg;base64,' . base64_encode($item['jpeg']),
                'detail' => 'low',
            ],
        ];
    }
    $text = recommend_openai_chat($content, 28, 180);
    if ($text === null) {
        return ['mode' => 'error'];
    }
    $json = recommend_parse_json_text($text);
    if ($json === null) {
        return ['mode' => 'error'];
    }
    $pick = (int) ($json['pick'] ?? 0);
    $confidence = (string) ($json['confidence'] ?? 'low');
    if ($confidence !== 'high' || $pick < 1 || $pick > count($prepared)) {
        return ['mode' => 'low'];
    }
    return ['mode' => 'high', 'hit' => $prepared[$pick - 1]['hit']];
}

function recommend_generate_image($food, $row) {
    $image = is_array($row['image'] ?? null) ? $row['image'] : [];
    $search = is_array($row['search'] ?? null) ? $row['search'] : [];
    if ((string) ($image['status'] ?? '') === 'error'
        && !empty($image['pixabayId'])
        && !empty($image['downloadUrl'])
        && (int) ($image['chosenAt'] ?? 0) > time() - 7 * 86400) {
        $image = recommend_transfer_image($food, $image);
        return ['image' => $image, 'search' => $search];
    }
    [$search, $hits] = recommend_collect_hits($food, $search);
    if (($search['error'] ?? '') === 'no-key') {
        return ['image' => recommend_blank_image('none', 'error', time() + 1800, 'no-key'), 'search' => $search];
    }
    if (!count($hits)) {
        $note = (string) ($search['error'] ?? '');
        $until = $note === '429' ? time() + 3600 : time() + 86400;
        return ['image' => recommend_blank_image('none', 'skipped', $until, $note !== '' ? $note : 'no-results'), 'search' => $search];
    }
    $vision = recommend_vision_pick($food, $hits);
    if ($vision['mode'] === 'high' && is_array($vision['hit'] ?? null)) {
        $chosen = recommend_image_from_hit($food, $vision['hit'], 'openai-vision', 'high');
        $chosen = recommend_transfer_image($food, $chosen);
        recommend_image_log($food, 'pick', 'vision status=' . (string) ($chosen['status'] ?? ''));
        return ['image' => $chosen, 'search' => $search];
    }
    $scored = recommend_score_pick($food, $hits);
    if ($scored === null) {
        $note = $vision['mode'] === 'low' ? 'low-confidence' : 'low-score';
        recommend_image_log($food, 'skip', $note . ' vision=' . (string) ($vision['mode'] ?? ''));
        return ['image' => recommend_blank_image('score', 'skipped', time() + 86400, $note), 'search' => $search];
    }
    $chosen = recommend_image_from_hit($food, $scored, 'score', 'high');
    $chosen = recommend_transfer_image($food, $chosen);
    recommend_image_log($food, 'pick', 'score status=' . (string) ($chosen['status'] ?? '') . ' vision=' . (string) ($vision['mode'] ?? ''));
    return ['image' => $chosen, 'search' => $search];
}

function recommend_claim_image($food) {
    return recommend_lock(function () use ($food) {
        $assets = recommend_load_doc(recommend_assets_file(), ['menus' => []]);
        if ($assets === null) {
            return ['done' => true, 'result' => ['ok' => false, 'status' => 503, 'error' => '画像を保存できません。', 'image' => recommend_image_payload(['status' => 'error'])]];
        }
        $assets = recommend_normalize_assets($assets);
        $row = $assets['menus'][$food] ?? [];
        if (!is_array($row)) {
            $row = [];
        }
        $image = is_array($row['image'] ?? null) ? $row['image'] : [];
        if (recommend_image_fresh($image)) {
            return ['done' => true, 'result' => ['ok' => true, 'image' => recommend_image_payload($image)]];
        }
        if ((int) ($image['workingUntil'] ?? 0) > time()) {
            return ['busy' => true];
        }
        $image['workingUntil'] = time() + 80;
        $row['image'] = $image;
        $assets['menus'][$food] = $row;
        if (!recommend_save_doc(recommend_assets_file(), $assets)) {
            return ['done' => true, 'result' => ['ok' => false, 'status' => 503, 'error' => '画像を保存できません。', 'image' => recommend_image_payload(['status' => 'error'])]];
        }
        return ['row' => $row];
    });
}

function recommend_food_allowed($food) {
    if (recommend_food_is_current($food)) {
        return true;
    }
    $core = recommend_core_name($food);
    if ($core === '') {
        return false;
    }
    foreach (line_menus() as $menu) {
        if (!is_array($menu)) {
            continue;
        }
        $name = trim((string) ($menu['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $menuCore = recommend_core_name($name);
        if ($name === $food || $name === $core || $menuCore === $food || $menuCore === $core) {
            return true;
        }
    }
    return false;
}

function recommend_ensure_image($food) {
    $food = trim((string) $food);
    if ($food === '' || mb_strlen($food) > 80 || !recommend_food_allowed($food)) {
        return ['ok' => false, 'status' => 400, 'error' => 'この定食の画像は用意していません。', 'image' => recommend_image_payload(['status' => 'skipped'])];
    }
    $claim = recommend_claim_image($food);
    if (!empty($claim['done'])) {
        return $claim['result'];
    }
    if (!empty($claim['busy'])) {
        return ['ok' => true, 'image' => recommend_image_payload(['status' => 'pending'])];
    }
    $row = is_array($claim['row'] ?? null) ? $claim['row'] : [];
    try {
        $generated = recommend_generate_image($food, $row);
    } catch (Throwable $e) {
        $generated = ['image' => recommend_blank_image('none', 'error', time() + 600, 'exception'), 'search' => []];
    }
    $saved = recommend_merge_menu($food, function ($latest) use ($generated) {
        $latestImage = is_array($latest['image'] ?? null) ? $latest['image'] : [];
        if (($latestImage['status'] ?? '') === 'ready' && !empty($latestImage['publicUrl']) && (int) ($latestImage['workingUntil'] ?? 0) === 0) {
            return $latest;
        }
        $latest['image'] = $generated['image'];
        if (is_array($generated['search'] ?? null)) {
            $search = $generated['search'];
            if (($generated['image']['status'] ?? '') === 'ready') {
                $search['hits'] = [];
            }
            $latest['search'] = $search;
        }
        return $latest;
    });
    $image = is_array($saved['image'] ?? null) ? $saved['image'] : ($generated['image'] ?? []);
    return ['ok' => true, 'image' => recommend_image_payload($image)];
}

function recommend_self_check_open_days() {
    $sunday = ['2026-11-29' => true];
    $skipSunday = recommend_pick_open_dates('2026-11-28', function ($date) use ($sunday) {
        return !empty($sunday[$date]);
    });
    $weekend = ['2026-11-28' => true, '2026-11-29' => true];
    $skipWeekend = recommend_pick_open_dates('2026-11-28', function ($date) use ($weekend) {
        return !empty($weekend[$date]);
    });
    $open = recommend_pick_open_dates('2026-11-30', function () {
        return false;
    });
    return count($skipSunday) === 2
        && $skipSunday[0][0] === '2026-11-28'
        && $skipSunday[0][1] === 'today'
        && $skipSunday[1][0] === '2026-11-30'
        && count($skipWeekend) === 2
        && $skipWeekend[0][0] === '2026-11-30'
        && $skipWeekend[1][0] === '2026-12-01'
        && $skipWeekend[0][1] === 'later'
        && $open[0][0] === '2026-11-30'
        && $open[0][1] === 'today'
        && $open[1][0] === '2026-12-01';
}

function recommend_self_check_slots() {
    $rows = [
        ['date' => '2026-11-29', 'food' => '昨日の定食', 'disliked' => false],
        ['date' => '2026-11-30', 'food' => '納豆定食', 'disliked' => true],
        ['date' => '2026-12-01', 'food' => '豚カツ定食', 'disliked' => false],
        ['date' => '2026-12-02', 'food' => '唐揚げ定食', 'disliked' => false],
        ['date' => '2026-12-03', 'food' => '親子丼', 'disliked' => false],
        ['date' => '2026-12-04', 'food' => '豚カツ定食', 'disliked' => false],
    ];
    $slots = recommend_choose_slots($rows, '2026-11-30', function ($food) {
        if ($food === '親子丼') {
            return 9;
        }
        if ($food === '唐揚げ定食') {
            return 5;
        }
        return 1;
    });
    $dates = [];
    foreach ($slots as $slot) {
        $dates[] = $slot['row']['date'];
    }
    $bestToday = recommend_choose_slots($rows, '2026-12-03', function ($food) {
        return $food === '親子丼' ? 9 : 1;
    });
    $bestFoods = [];
    $bestDates = [];
    foreach ($bestToday as $slot) {
        $bestFoods[] = $slot['row']['food'];
        $bestDates[] = $slot['row']['date'];
    }
    $titles = recommend_slot_title('week') . recommend_slot_title('next') . recommend_slot_title('after');
    return count($slots) === 3
        && $slots[0]['role'] === 'week'
        && $slots[0]['row']['food'] === '親子丼'
        && $slots[1]['role'] === 'next'
        && $slots[1]['row']['food'] === '豚カツ定食'
        && $slots[1]['row']['date'] === '2026-12-01'
        && $slots[2]['role'] === 'after'
        && $slots[2]['row']['date'] === '2026-12-02'
        && !in_array('納豆定食', array_column(array_column($slots, 'row'), 'food'), true)
        && count(array_unique($dates)) === 3
        && !in_array('2026-11-29', $dates, true)
        && $bestToday[0]['role'] === 'week-today'
        && $bestToday[0]['row']['date'] === '2026-12-03'
        && !in_array('親子丼', array_slice($bestFoods, 1), true)
        && !in_array('2026-11-30', $bestDates, true)
        && count($bestToday) === 2
        && mb_strpos($titles, 'イチオシ') !== false
        && mb_strpos($titles, '次の営業日') !== false
        && mb_strpos($titles, 'もう一つのおすすめ') === false;
}

function recommend_self_check() {
    $chicken = '鶏の照り焼き定食';
    $a = ['userId' => 'a', 'likes' => ['鶏肉'], 'avoids' => [], 'allergies' => [], 'timesPerWeek' => 'ほぼ毎日'];
    $b = ['userId' => 'b', 'likes' => ['魚'], 'avoids' => ['肉'], 'allergies' => [], 'timesPerWeek' => 'ほぼ毎日'];
    $scoreA = recommend_personal_score($a, $chicken, []);
    $scoreB = recommend_personal_score($b, $chicken, []);
    $oneDown = [[
        'userId' => 'a',
        'food' => '唐揚げ定食',
        'vote' => 'down',
        'reason' => 'not_my_taste',
        'date' => '2026-01-01',
    ]];
    $scoreA2 = recommend_personal_score($a, $chicken, $oneDown);
    $ingredient = [[
        'userId' => 'a',
        'food' => '鶏の照り焼き定食',
        'vote' => 'down',
        'reason' => 'disliked_ingredient',
        'date' => '2026-01-02',
    ]];
    $scoreIngredient = recommend_personal_score($a, $chicken, $ingredient);
    $three = [
        ['userId' => 'a', 'food' => '唐揚げ定食', 'vote' => 'down', 'reason' => 'not_my_taste', 'date' => '2026-01-01'],
        ['userId' => 'a', 'food' => 'チキンソテー定食', 'vote' => 'down', 'reason' => 'not_my_taste', 'date' => '2026-01-02'],
        ['userId' => 'a', 'food' => '鶏の照り焼き定食', 'vote' => 'down', 'reason' => 'not_my_taste', 'date' => '2026-01-03'],
    ];
    $scoreA3 = recommend_personal_score($a, $chicken, $three);
    $fishVote = [[
        'userId' => 'a',
        'food' => 'サバの味噌煮定食',
        'vote' => 'up',
        'reason' => 'liked_food',
        'date' => '2026-01-04',
    ]];
    $reasonA = recommend_reason_text($a, $chicken, [], 0);
    $reasonFish = recommend_reason_text($a, $chicken, $fishVote, 0);
    $reasonB = recommend_reason_text($b, $chicken, [], 0);
    $eggUser = ['userId' => 'c', 'likes' => ['卵'], 'avoids' => [], 'allergies' => ['卵'], 'timesPerWeek' => ''];
    $reasonEgg = recommend_reason_text($eggUser, '卵焼き定食', [], 0);
    $hadBeforeOther = [[
        'userId' => 'a',
        'food' => '唐揚げ定食',
        'vote' => 'down',
        'reason' => 'had_before',
        'date' => '2026-01-05',
    ]];
    $english = recommend_english_query($chicken);
    $hits = [
        ['id' => 1, 'tags' => 'ramen pork bowl', 'imageWidth' => 1200, 'imageHeight' => 800],
        ['id' => 2, 'tags' => 'chicken teriyaki rice bowl', 'imageWidth' => 1200, 'imageHeight' => 800],
    ];
    $picked = recommend_score_pick($chicken, $hits);
    $weak = recommend_score_pick($chicken, [
        ['id' => 3, 'tags' => 'delicious meal', 'imageWidth' => 2000, 'imageHeight' => 1200],
    ]);
    $checks = [
        'users_differ' => $scoreA > $scoreB,
        'one_vote_keeps_match' => $scoreA > 0 && $scoreA2 > 0 && $scoreA2 < $scoreA,
        'ingredient_vote_not_extreme' => $scoreIngredient > 0 && $scoreIngredient < $scoreA,
        'three_votes_capped' => $scoreA3 === $scoreA - 3,
        'other_user_ignored' => recommend_personal_score($b, $chicken, $oneDown) === $scoreB,
        'unrelated_upvote_ignored' => recommend_personal_score($a, $chicken, $fishVote) === $scoreA,
        'had_before_other_ignored' => recommend_personal_score($a, $chicken, $hadBeforeOther) === $scoreA,
        'reason_uses_like' => mb_strpos($reasonA, '鶏肉') !== false && mb_strpos($reasonA, '魚') === false,
        'reason_ignores_unrelated' => mb_strpos($reasonFish, '鶏肉') !== false && mb_strpos($reasonFish, 'サバ') === false,
        'reason_uses_avoid' => mb_strpos($reasonB, '苦手') !== false && mb_strpos($reasonB, '好きと回答') === false,
        'allergy_blocks' => mb_strpos($reasonEgg, 'おすすめしません') !== false && recommend_fit($eggUser, '卵焼き定食', []) === 'blocked',
        'english_query' => $english !== '' && mb_strlen($english) <= 100 && strpos($english, 'teriyaki') !== false,
        'english_mayo' => strpos(recommend_english_query('豚マヨ定食'), 'mayonnaise') !== false,
        'english_bonito' => strpos(recommend_english_query('カツオのたたき'), 'bonito') !== false
            && strpos(recommend_english_query('カツオのたたき'), 'cutlet') === false,
        'span_15' => (line_span_dates('2026-11-28', 0)[0] ?? '') === '2026-11-21'
            && (line_span_dates('2026-11-28', 0)[7] ?? '') === '2026-11-28'
            && (line_span_dates('2026-11-28', 0)[14] ?? '') === '2026-12-05'
            && count(line_span_dates('2026-01-02', 0)) === 15
            && (line_span_dates('2026-01-02', 0)[0] ?? '') === '2025-12-26',
        'core_short' => mb_strlen(recommend_core_name($chicken)) <= 100 && recommend_core_name($chicken) === '鶏の照り焼き',
        'score_picks_match' => is_array($picked) && (int) $picked['id'] === 2,
        'score_rejects_unrelated' => $weak === null,
        'blurb_accepts_name' => recommend_blurb_acceptable($chicken, '鶏の照り焼き定食です。この日の学校食堂の定食です。') === true,
        'blurb_rejects_invention' => recommend_blurb_acceptable($chicken, '鶏の照り焼き定食です。卵とチーズを使った絶品です。') === false,
        'blurb_rejects_sides' => recommend_blurb_acceptable($chicken, '鶏の照り焼き定食は、甘辛いタレで焼いた鶏肉です。ご飯と味噌汁、サラダがつきます。') === false,
        'vision_model' => recommend_model_accepts_images('gpt-4o-mini') === true && recommend_model_accepts_images('gpt-3.5-turbo') === false,
        'skip_sunday' => recommend_self_check_open_days(),
        'pitch_two_sentences' => substr_count(recommend_menu_pitch('豚カツ定食'), '。') === 2
            && mb_strpos(recommend_menu_pitch('豚カツ定食'), '豚カツ定食') !== false
            && mb_strpos(recommend_menu_pitch('豚カツ定食'), '納豆') === false
            && recommend_menu_pitch('豚カツ定食') !== recommend_menu_pitch('納豆定食')
            && recommend_menu_pitch('親子丼') !== recommend_menu_pitch('唐揚げ定食')
            && substr_count(recommend_menu_pitch('親子丼'), '。') === 2,
        'week_best_is_distinct' => recommend_self_check_slots(),
        'supabase_menu_shapes' => line_food_from_rows([
                ['date' => '2026/12/02', 'food' => '親子丼'],
                '2026-12-03' => '鯖の塩焼き定食',
            ], '2026-12-02') === '親子丼'
            && line_food_from_rows([
                ['date' => '2026/12/02', 'food' => '親子丼'],
                '2026-12-03' => ['menu' => '鯖の塩焼き定食'],
            ], '2026-12-03') === '鯖の塩焼き定食'
            && line_holiday_from_rows([
                ['date' => '2026/11/29', 'reason' => '日曜'],
            ], '2026-11-29') === '日曜'
            && line_holiday_from_rows([
                '2026-12-06' => '休校',
            ], '2026-12-06') === '休校',
        'next_skips_disliked' => (recommend_choose_next_menu([
            ['date' => '2026-12-01', 'food' => '納豆定食'],
            ['date' => '2026-12-02', 'food' => '豚カツ定食'],
        ], function ($food) {
            return mb_strpos($food, '納豆') !== false;
        })['food'] ?? '') === '豚カツ定食',
        'next_keeps_tonkatsu' => (recommend_choose_next_menu([
            ['date' => '2026-12-02', 'food' => '豚カツ定食'],
            ['date' => '2026-12-03', 'food' => '納豆定食'],
        ], function ($food) {
            return mb_strpos($food, '納豆') !== false;
        })['food'] ?? '') === '豚カツ定食',
        'next_keeps_undecided' => (recommend_choose_next_menu([
            ['date' => '2026-12-01', 'food' => ''],
            ['date' => '2026-12-02', 'food' => '豚カツ定食'],
        ], function () {
            return false;
        })['date'] ?? '') === '2026-12-01',
        'natto_avoid_hits' => recommend_menu_disliked(
            ['userId' => 'n', 'likes' => [], 'avoids' => ['納豆'], 'allergies' => [], 'timesPerWeek' => ''],
            '納豆丼'
        ) === true && recommend_menu_disliked(
            ['userId' => 'n', 'likes' => [], 'avoids' => ['納豆'], 'allergies' => [], 'timesPerWeek' => ''],
            '豚カツ定食'
        ) === false,
    ];
    $failed = [];
    foreach ($checks as $name => $ok) {
        if (!$ok) {
            $failed[] = $name;
        }
    }
    return ['ok' => count($failed) === 0, 'failed' => $failed, 'scores' => [$scoreA, $scoreB, $scoreA2, $scoreIngredient, $scoreA3]];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $result = recommend_self_check();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
