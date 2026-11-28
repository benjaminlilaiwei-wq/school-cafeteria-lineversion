<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/recommend-lib.php';

$food = isset($_GET['food']) ? trim((string) $_GET['food']) : '';
if ($food === '' || mb_strlen($food) > 80) {
    http_response_code(404);
    exit;
}
$cached = recommend_cached_image($food);
$url = is_array($cached) ? (string) ($cached['url'] ?? '') : '';
$url = explode('?', $url, 2)[0];
if (($cached['status'] ?? '') !== 'ready' || !recommend_owned_image_url($url)) {
    http_response_code(404);
    exit;
}
[$ok, $code, $raw] = recommend_request($url, 'GET', ['Accept: image/*'], null, 12);
if (!$ok || !is_string($raw) || strlen($raw) < 32) {
    http_response_code(404);
    exit;
}
$image = @imagecreatefromstring($raw);
if ($image === false) {
    http_response_code(404);
    exit;
}
$width = imagesx($image);
$height = imagesy($image);
if ($width > 1024 && $height > 0) {
    $target = imagecreatetruecolor(1024, (int) max(1, round($height * 1024 / $width)));
    imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
    imagedestroy($image);
    $image = $target;
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
imagejpeg($image, null, 82);
imagedestroy($image);
