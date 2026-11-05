<?php
/**
 * 来客数はここで計算する。LLMには渡さない。
 * 休業日（来客0かつ販売なし、祝日、土日の0人）は平均に入れない。
 */
date_default_timezone_set('Asia/Tokyo');

function crowd_data_dir() {
    return __DIR__ . '/../data';
}

function crowd_today() {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

function crowd_now_iso() {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c');
}

function crowd_read_json($file, $fallback) {
    if (!is_file($file)) {
        return $fallback;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $fallback;
}

function crowd_write_json($file, $data) {
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

function crowd_dow($date) {
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        $ts = time();
    }
    $days = ['日', '月', '火', '水', '木', '金', '土'];
    return $days[(int) date('w', $ts)];
}

function crowd_normalize_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return crowd_today();
    }
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return crowd_today();
    }
    return date('Y-m-d', $ts);
}

function crowd_holiday_map() {
    $map = [];
    $rows = crowd_read_json(crowd_data_dir() . '/holidays.json', []);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        $reason = trim((string) ($row['reason'] ?? ''));
        $map[$date] = $reason !== '' ? $reason : '休業日';
    }
    return $map;
}

function crowd_sold_count($menuSales) {
    $sold = 0;
    if (!is_array($menuSales)) {
        return 0;
    }
    foreach ($menuSales as $qty) {
        $sold += (int) $qty;
    }
    return $sold;
}

function crowd_menu_map($menuSales) {
    $out = [];
    if (!is_array($menuSales)) {
        return $out;
    }
    foreach ($menuSales as $name => $qty) {
        $name = trim((string) $name);
        if ($name === '' || $name === '日替丼') {
            if ($name === '日替丼') {
                $name = '日替定食';
            } else {
                continue;
            }
        }
        $out[$name] = ($out[$name] ?? 0) + (int) $qty;
    }
    return $out;
}

function crowd_load_records() {
    $data = crowd_read_json(crowd_data_dir() . '/attendance-data.json', []);
    $rows = [];
    $daily = $data['dailyRecords'] ?? [];
    if (is_array($daily)) {
        foreach ($daily as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string) ($row['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $day = (string) ($row['day'] ?? '');
            if ($day === '') {
                $day = crowd_dow($date);
            }
            $rows[] = [
                'date' => $date,
                'day' => $day,
                'attendance' => (int) ($row['attendance'] ?? 0),
                'menuSales' => crowd_menu_map($row['menuSales'] ?? []),
            ];
        }
    }
    usort($rows, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $rows;
}

function crowd_is_business_day($row, $holidayMap) {
    $date = $row['date'];
    if (isset($holidayMap[$date])) {
        return false;
    }
    $attendance = (int) ($row['attendance'] ?? 0);
    $sold = crowd_sold_count($row['menuSales'] ?? []);
    if ($attendance <= 0 && $sold <= 0) {
        return false;
    }
    $day = (string) ($row['day'] ?? '');
    if (($day === '土' || $day === '日') && $attendance <= 0) {
        return false;
    }
    return true;
}

function crowd_closed_reason($date, $holidayMap) {
    if (isset($holidayMap[$date])) {
        return $holidayMap[$date];
    }
    $dow = crowd_dow($date);
    if ($dow === '土') {
        return '土曜日';
    }
    if ($dow === '日') {
        return '日曜日';
    }
    return null;
}

function crowd_median($values) {
    $values = array_values(array_map('floatval', $values));
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return (float) $values[$mid];
    }
    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
}

function crowd_mean($values) {
    if (count($values) === 0) {
        return null;
    }
    return array_sum($values) / count($values);
}

function crowd_food_map() {
    $map = [];
    $rows = crowd_read_json(crowd_data_dir() . '/daily-menu.json', []);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = (string) ($row['date'] ?? '');
        $food = trim((string) ($row['food'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $food !== '') {
            $map[$date] = $food;
        }
    }
    return $map;
}

function crowd_menu_daily_averages($rows) {
    $days = count($rows);
    $sums = [];
    if ($days === 0) {
        return ['items' => [], 'sum' => 0.0];
    }
    foreach ($rows as $row) {
        foreach ($row['menuSales'] as $name => $qty) {
            $sums[$name] = ($sums[$name] ?? 0) + (int) $qty;
        }
    }
    $items = [];
    foreach ($sums as $name => $total) {
        $items[] = ['name' => $name, 'avg' => $total / $days];
    }
    usort($items, function ($a, $b) {
        if ($a['avg'] === $b['avg']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['avg'] < $b['avg'] ? 1 : -1;
    });
    $sum = 0.0;
    foreach ($items as $item) {
        $sum += $item['avg'];
    }
    return ['items' => $items, 'sum' => $sum];
}

function crowd_blend_factors($factors) {
    $weightSum = 0.0;
    foreach ($factors as $factor) {
        $weightSum += (float) $factor['weight'];
    }
    if ($weightSum <= 0) {
        return null;
    }
    $value = 0.0;
    $out = [];
    foreach ($factors as $factor) {
        $share = ((float) $factor['weight']) / $weightSum;
        $value += ((float) $factor['value']) * $share;
        $factor['percent'] = (int) round($share * 100);
        $out[] = $factor;
    }
    return ['value' => $value, 'factors' => $out];
}

function crowd_regression_next($values) {
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    if ($n === 1) {
        return (float) $values[0];
    }
    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumXX = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float) $i;
        $y = (float) $values[$i];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumXX += $x * $x;
    }
    $den = ($n * $sumXX) - ($sumX * $sumX);
    if (abs($den) < 0.0000001) {
        return $sumY / $n;
    }
    $slope = (($n * $sumXY) - ($sumX * $sumY)) / $den;
    $intercept = ($sumY - ($slope * $sumX)) / $n;
    return $intercept + ($slope * $n);
}

function crowd_allocate_menus($sameWeekdayRows, $prediction) {
    $prediction = (int) $prediction;
    if ($prediction <= 0 || count($sameWeekdayRows) === 0) {
        return [];
    }
    $sums = [];
    $days = count($sameWeekdayRows);
    foreach ($sameWeekdayRows as $row) {
        foreach ($row['menuSales'] as $name => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }
            $sums[$name] = ($sums[$name] ?? 0) + (int) $qty;
        }
    }
    if (count($sums) === 0 || $days === 0) {
        return [];
    }
    $avgs = [];
    foreach ($sums as $name => $total) {
        $avgs[$name] = $total / $days;
    }
    $avgSum = array_sum($avgs);
    if ($avgSum <= 0) {
        return [];
    }
    arsort($avgs);
    $parts = [];
    $assigned = 0;
    foreach ($avgs as $name => $avg) {
        $count = (int) floor($prediction * ($avg / $avgSum));
        $parts[] = ['name' => $name, 'count' => $count, 'avg' => $avg];
        $assigned += $count;
    }
    $remain = $prediction - $assigned;
    $n = count($parts);
    for ($i = 0; $remain > 0 && $n > 0; $i++) {
        $parts[$i % $n]['count']++;
        $remain--;
    }
    $shown = [];
    foreach ($parts as $part) {
        if ($part['count'] > 0) {
            $shown[] = $part;
        }
    }
    if (count($shown) > 12) {
        $top = array_slice($shown, 0, 11);
        $rest = 0;
        foreach (array_slice($shown, 11) as $part) {
            $rest += (int) $part['count'];
        }
        if ($rest > 0) {
            $top[] = ['name' => 'その他', 'count' => $rest, 'avg' => 0];
        }
        $shown = $top;
    }
    $sum = 0;
    foreach ($shown as $part) {
        $sum += (int) $part['count'];
    }
    if (count($shown) > 0 && $sum !== $prediction) {
        $shown[0]['count'] += $prediction - $sum;
        if ($shown[0]['count'] < 0) {
            $shown[0]['count'] = 0;
        }
    }
    $out = [];
    foreach ($shown as $part) {
        if ($part['count'] <= 0) {
            continue;
        }
        $out[] = ['name' => $part['name'], 'count' => (int) $part['count']];
    }
    return $out;
}

function crowd_predictions_file() {
    return crowd_data_dir() . '/attendance-predictions.json';
}

function crowd_load_predictions() {
    $data = crowd_read_json(crowd_predictions_file(), []);
    $byDate = $data['byDate'] ?? [];
    return is_array($byDate) ? $byDate : [];
}

function crowd_actual_map($records) {
    $map = [];
    foreach ($records as $row) {
        $map[$row['date']] = (int) $row['attendance'];
    }
    return $map;
}

function crowd_sales_people($date) {
    $sales = crowd_read_json(crowd_data_dir() . '/sales-data.json', []);
    $day = $sales[$date] ?? null;
    if (!is_array($day)) {
        return null;
    }
    if (isset($day['people']) && (int) $day['people'] > 0) {
        return (int) $day['people'];
    }
    return null;
}

function crowd_sync_actuals($byDate, $records) {
    $today = crowd_today();
    $actuals = crowd_actual_map($records);
    $changed = false;
    foreach ($byDate as $date => $row) {
        if (!is_array($row) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            continue;
        }
        if ($date >= $today) {
            continue;
        }
        if (array_key_exists($date, $actuals)) {
            $actual = (int) $actuals[$date];
        } else {
            $actual = crowd_sales_people($date);
        }
        if ($actual === null) {
            continue;
        }
        $predicted = isset($row['predicted']) ? (int) $row['predicted'] : null;
        $error = $predicted === null ? null : abs($predicted - $actual);
        if (($row['actual'] ?? null) !== $actual || ($row['absError'] ?? null) !== $error) {
            $byDate[$date]['actual'] = $actual;
            $byDate[$date]['absError'] = $error;
            $changed = true;
        }
    }
    return [$byDate, $changed];
}

function crowd_remember_prediction($payload, $records) {
    $date = $payload['date'];
    $byDate = crowd_load_predictions();
    list($byDate, $changed) = crowd_sync_actuals($byDate, $records);
    $existing = $byDate[$date] ?? null;
    $hasForecast = is_array($existing) && array_key_exists('predicted', $existing) && $existing['predicted'] !== null;
    if (!$hasForecast && $payload['predictionNumber'] !== null && $date >= crowd_today()) {
        $byDate[$date] = [
            'date' => $date,
            'predicted' => $payload['predictionNumber'],
            'predictedAt' => crowd_now_iso(),
            'method' => $payload['method'],
            'closed' => !empty($payload['closed']),
            'actual' => null,
            'absError' => null,
        ];
        $changed = true;
    }
    list($byDate, $changedAgain) = crowd_sync_actuals($byDate, $records);
    if ($changed || $changedAgain) {
        ksort($byDate);
        $dates = array_keys($byDate);
        if (count($dates) > 90) {
            $drop = array_slice($dates, 0, count($dates) - 90);
            foreach ($drop as $old) {
                unset($byDate[$old]);
            }
        }
        crowd_write_json(crowd_predictions_file(), ['byDate' => $byDate]);
    }
    return $byDate;
}

function crowd_history_rows($byDate) {
    $rows = [];
    foreach ($byDate as $date => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'date' => (string) ($row['date'] ?? $date),
            'predicted' => array_key_exists('predicted', $row) ? $row['predicted'] : null,
            'predictedAt' => (string) ($row['predictedAt'] ?? ''),
            'actual' => array_key_exists('actual', $row) ? $row['actual'] : null,
            'absError' => array_key_exists('absError', $row) ? $row['absError'] : null,
            'closed' => !empty($row['closed']),
        ];
    }
    usort($rows, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    return array_slice($rows, 0, 21);
}

function crowd_build_prediction($targetDate) {
    $targetDate = crowd_normalize_date($targetDate);
    $holidayMap = crowd_holiday_map();
    $records = crowd_load_records();
    $dow = crowd_dow($targetDate);
    $closedReason = crowd_closed_reason($targetDate, $holidayMap);
    $business = [];
    $excluded = 0;
    foreach ($records as $row) {
        if ($row['date'] >= $targetDate) {
            continue;
        }
        if (!crowd_is_business_day($row, $holidayMap)) {
            if ((int) $row['attendance'] <= 0) {
                $excluded++;
            }
            continue;
        }
        $business[] = $row;
    }
    $sameAll = [];
    foreach ($business as $row) {
        if ($row['day'] === $dow) {
            $sameAll[] = $row;
        }
    }
    $sameForTrend = $sameAll;
    if (count($sameForTrend) > 20) {
        $sameForTrend = array_slice($sameForTrend, -20);
    }
    $weekdayValues = [];
    foreach ($sameAll as $row) {
        $weekdayValues[] = (int) $row['attendance'];
    }
    $trendValues = [];
    foreach ($sameForTrend as $row) {
        $trendValues[] = (int) $row['attendance'];
    }
    $weekdayMean = crowd_mean($weekdayValues);
    $weekdayMedian = crowd_median($weekdayValues);
    $regression = count($trendValues) >= 4 ? crowd_regression_next($trendValues) : null;
    if ($regression !== null && $regression < 0) {
        $regression = null;
    }
    $recentSameValues = array_slice($weekdayValues, -4);
    $recentSameMean = count($recentSameValues) >= 4 ? crowd_mean($recentSameValues) : null;
    $recentBizValues = [];
    foreach (array_slice($business, -5) as $row) {
        $recentBizValues[] = (int) $row['attendance'];
    }
    $recentBizMean = count($recentBizValues) >= 3 ? crowd_mean($recentBizValues) : null;

    $menuBase = count($sameAll) > 0 ? $sameAll : $business;
    $menuStats = crowd_menu_daily_averages($menuBase);
    $menuSum = (float) $menuStats['sum'];
    $useMenu = $menuSum > 0;
    if ($useMenu && $weekdayMean !== null && $weekdayMean > 0) {
        $ratio = $menuSum / $weekdayMean;
        if ($ratio < 0.7 || $ratio > 1.3) {
            $useMenu = false;
        }
    }
    $foodMap = crowd_food_map();
    $targetFood = (string) ($foodMap[$targetDate] ?? '');
    $foodValues = [];
    if ($targetFood !== '') {
        foreach ($business as $row) {
            $pastFood = (string) ($foodMap[$row['date']] ?? '');
            if ($pastFood !== '' && $pastFood === $targetFood) {
                $foodValues[] = (int) $row['attendance'];
            }
        }
    }
    $foodMean = count($foodValues) >= 2 ? crowd_mean($foodValues) : null;

    $factors = [];
    if ($weekdayMean !== null) {
        $factors[] = ['label' => $dow . '曜の平均', 'value' => $weekdayMean, 'weight' => 22, 'note' => count($weekdayValues) . '日'];
    }
    if ($weekdayMedian !== null) {
        $factors[] = ['label' => $dow . '曜の中央値', 'value' => $weekdayMedian, 'weight' => 13, 'note' => '飛び抜けた日の影響を弱める'];
    }
    if ($regression !== null) {
        $factors[] = ['label' => $dow . '曜の直線回帰', 'value' => $regression, 'weight' => 18, 'note' => '直近' . count($trendValues) . '日の増減'];
    }
    if ($recentSameMean !== null) {
        $factors[] = ['label' => '直近' . count($recentSameValues) . '回の' . $dow . '曜', 'value' => $recentSameMean, 'weight' => 15, 'note' => ''];
    }
    if ($recentBizMean !== null) {
        $factors[] = ['label' => '直近' . count($recentBizValues) . '営業日', 'value' => $recentBizMean, 'weight' => 12, 'note' => '曜日を混ぜた直近'];
    }
    if ($useMenu) {
        $factors[] = ['label' => 'メニュー別平均の合計', 'value' => $menuSum, 'weight' => 15, 'note' => count($menuBase) . '日'];
    }
    if ($foodMean !== null) {
        $factors[] = ['label' => '同じ定食の平均', 'value' => $foodMean, 'weight' => 10, 'note' => $targetFood . ' / ' . count($foodValues) . '日'];
    }
    $blended = crowd_blend_factors($factors);
    $sample = count($weekdayValues);
    $mean = $weekdayMean;

    $prediction = null;
    $method = 'none';
    $confidence = 'low';
    if ($closedReason !== null) {
        $prediction = 0;
        $method = 'closed';
        $confidence = 'high';
    } elseif ($blended !== null) {
        $prediction = (int) round($blended['value']);
        $method = 'multi_factor';
        if ($sample >= 8 && count($factors) >= 4) {
            $confidence = 'high';
        } elseif ($sample >= 4 || count($business) >= 8) {
            $confidence = 'medium';
        }
        $capSource = count($weekdayValues) > 0 ? $weekdayValues : $recentBizValues;
        if (count($capSource) > 0) {
            $cap = (int) round(max($capSource) * 1.2);
            if ($prediction < 0) {
                $prediction = 0;
            }
            if ($cap > 0 && $prediction > $cap) {
                $prediction = $cap;
            }
        }
    } elseif (count($business) > 0) {
        $values = [];
        foreach ($business as $row) {
            $values[] = (int) $row['attendance'];
        }
        $mean = crowd_mean($values);
        $prediction = (int) round($mean);
        $method = 'business_day_average';
    }

    $menus = [];
    if ($closedReason === null && $prediction !== null && $prediction > 0) {
        $menus = crowd_allocate_menus($menuBase, $prediction);
    }

    $weekdayLines = [];
    foreach (['月', '火', '水', '木', '金'] as $dayName) {
        $dayValues = [];
        foreach ($business as $row) {
            if ($row['day'] === $dayName) {
                $dayValues[] = (int) $row['attendance'];
            }
        }
        if (count($dayValues) === 0) {
            continue;
        }
        $mark = $dayName === $dow ? ' ← この曜日' : '';
        $weekdayLines[] = '- ' . $dayName . '曜: 平均' . (int) round(crowd_mean($dayValues)) . '人 / 中央値' . (int) round(crowd_median($dayValues)) . '人（' . count($dayValues) . '日）' . $mark;
    }

    $lines = [];
    $lines[] = '予測対象日: ' . $targetDate . '（' . $dow . '曜日）';
    if ($closedReason !== null) {
        $lines[] = '予測来客数: 0人';
        $lines[] = 'この日は休業です（' . $closedReason . '）。来客は見込んでいません。';
        $lines[] = 'この判断にChatGPTは使っていません。';
    } elseif ($prediction === null) {
        $lines[] = '営業日の来客記録が足りないので、人数は出せません。';
    } else {
        $lines[] = '予測来客数: ' . $prediction . '人';
        $lines[] = '人数はサーバーで計算しています。ChatGPTはこの計算に入っていません。';
        $lines[] = '休業日の0人は ' . $excluded . ' 日分を除いています。';
        if ($blended !== null) {
            $lines[] = '';
            $lines[] = '人数に使った材料（重みは、使えた材料だけで100%になるよう割り直しています）';
            foreach ($blended['factors'] as $factor) {
                $note = $factor['note'] !== '' ? '。' . $factor['note'] : '';
                $lines[] = '- ' . $factor['label'] . ': ' . (int) round($factor['value']) . '人（' . (int) $factor['percent'] . '%）' . $note;
            }
        }
        if (!$useMenu && $menuSum > 0) {
            $lines[] = 'メニュー別平均の合計は ' . (int) round($menuSum) . '人で、来客数の平均と差が大きいので、人数の材料からは外しました。内訳の参考には出しています。';
        }
        if ($targetFood !== '' && $foodMean === null) {
            $lines[] = 'この日の定食は「' . $targetFood . '」です。同じ定食の営業日が2日未満なので、定食ごとの平均は人数に入れていません。';
        }
        if (count($weekdayLines) > 0) {
            $lines[] = '';
            $lines[] = '曜日別の営業日（平均と中央値）';
            foreach ($weekdayLines as $weekdayLine) {
                $lines[] = $weekdayLine;
            }
        }
        if (count($menuStats['items']) > 0) {
            $lines[] = '';
            $lines[] = 'メニューごとの1日平均（' . (count($sameAll) > 0 ? '同じ曜日の営業日' : '営業日全体') . '）';
            $shownMenus = array_slice($menuStats['items'], 0, 8);
            foreach ($shownMenus as $item) {
                $lines[] = '- ' . $item['name'] . ': 平均' . number_format($item['avg'], 1) . '人';
            }
            if (count($menuStats['items']) > 8) {
                $lines[] = '- ほか ' . (count($menuStats['items']) - 8) . '品';
            }
            $lines[] = 'メニュー平均の合計: ' . (int) round($menuSum) . '人';
        }
        if (count($menus) > 0) {
            $lines[] = '';
            $lines[] = 'メニュー別の見込み（上の予測人数に、メニュー平均の割合で配分）';
            $allocated = 0;
            foreach ($menus as $menu) {
                $lines[] = '- ' . $menu['name'] . ': ' . $menu['count'] . '人';
                $allocated += (int) $menu['count'];
            }
            $lines[] = 'メニュー別の合計: ' . $allocated . '人';
        }
    }

    $payload = [
        'date' => $targetDate,
        'closed' => $closedReason !== null,
        'closedReason' => $closedReason,
        'predictionNumber' => $prediction,
        'method' => $method,
        'confidence' => $confidence,
        'menus' => $menus,
        'stats' => [
            'businessDays' => count($business),
            'sameWeekdayDays' => $sample,
            'excludedClosedDays' => $excluded,
            'sameWeekdayAverage' => $mean === null ? null : (int) round($mean),
            'regression' => $regression === null ? null : (int) round($regression),
            'factorCount' => count($factors),
        ],
    ];
    $byDate = crowd_remember_prediction($payload, $records);
    $payload['history'] = crowd_history_rows($byDate);
    $saved = $byDate[$targetDate] ?? null;
    $payload['saved'] = is_array($saved) ? $saved : null;
    if (is_array($saved) && array_key_exists('predicted', $saved)) {
        $lines[] = '';
        $lines[] = 'この日の最初の保存: ' . (int) $saved['predicted'] . '人（' . (string) ($saved['predictedAt'] ?? '') . '）';
        if ($saved['actual'] !== null && $saved['actual'] !== '') {
            $lines[] = '実績: ' . (int) $saved['actual'] . '人 / 差: ' . (int) ($saved['absError'] ?? 0) . '人';
        } else {
            $lines[] = '実績は、その日が終わってから記録と比べます。先に保存した人数は上書きしません。';
        }
    }
    $payload['prediction'] = implode("\n", $lines);
    if ($closedReason !== null) {
        $payload['congestion'] = ['level' => 'closed', 'text' => '休み', 'color' => '#6c757d'];
    } else {
        $payload['congestion'] = crowd_congestion($prediction);
    }
    return $payload;
}

function crowd_congestion($attendanceNumber) {
    if ($attendanceNumber === null) {
        return ['level' => 'unknown', 'text' => 'データ不足', 'color' => '#6c757d'];
    }
    if ($attendanceNumber >= 55) {
        return ['level' => 'very_crowded', 'text' => '非常に混雑しそう', 'color' => '#dc3545'];
    }
    if ($attendanceNumber >= 40) {
        return ['level' => 'crowded', 'text' => '混雑しそう', 'color' => '#fd7e14'];
    }
    if ($attendanceNumber >= 15) {
        return ['level' => 'moderate', 'text' => 'やや混雑しそう', 'color' => '#ffc107'];
    }
    return ['level' => 'quiet', 'text' => '空いていそう', 'color' => '#28a745'];
}

function crowd_prediction_payload($targetDate) {
    $payload = crowd_build_prediction($targetDate);
    return [
        'prediction' => $payload['prediction'],
        'predictionNumber' => $payload['closed'] ? null : $payload['predictionNumber'],
        'confidence' => $payload['confidence'],
        'method' => $payload['method'],
        'usedApi' => null,
        'date' => $payload['date'],
        'closed' => $payload['closed'],
        'menus' => $payload['menus'],
        'history' => $payload['history'],
        'saved' => $payload['saved'],
        'stats' => $payload['stats'],
        'congestion' => $payload['congestion'],
        'details' => '曜日の平均と中央値、同じ曜日の回帰、直近の営業日、メニューごとの平均、同じ定食の日を合わせて計算しました。',
    ];
}
