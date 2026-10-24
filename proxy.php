<?php
// وسيط لخدمة الأسئلة: يخفي المفتاح ويخزّن الردود فيستفيد منها كل المستخدمين
// 1) ضع المفتاح مكان الكلمة بالأسفل  2) ارفع الملف على الاستضافة  3) أعطني رابطه

const KEY        = 'ضع_المفتاح_هنا';
const UPSTREAM   = 'https://nwes.serv00.net/bot/api/';
const DAILY_MISS = 900;   // أقصى عدد طلبات للخدمة الأصلية يومياً (إجمالي كل المستخدمين)
const IP_MISS    = 400;   // أقصى عدد طلبات جديدة لكل جهاز يومياً

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$act = $_GET['action'] ?? '';
$ttl = 86400;
if ($act === 'ping') {
    out(['ok' => true, 'proxy' => true]);
} elseif ($act === 'folders') {
    $q = ['action' => 'folders', 'parent_id' => (int)($_GET['parent_id'] ?? 0)];
} elseif ($act === 'quizzes') {
    $q = ['action' => 'quizzes', 'folder_id' => (int)($_GET['folder_id'] ?? 0), 'page' => max(1, (int)($_GET['page'] ?? 1))];
} elseif ($act === 'quiz') {
    $q = ['action' => 'quiz', 'id' => (int)($_GET['id'] ?? 0)];
    $ttl = 2592000;
} else {
    out(['ok' => false, 'error' => 'unknown_action']);
}

$dir = __DIR__ . '/qcache';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$f = $dir . '/' . md5(http_build_query($q)) . '.json';

// 1) من التخزين المؤقت إن كان حديثاً
if (is_file($f) && time() - filemtime($f) < $ttl) { readfile($f); exit; }

// 2) حدود الحماية
$cf = $dir . '/_cnt_' . date('Y-m-d') . '.json';
$c = is_file($cf) ? json_decode(file_get_contents($cf), true) : [];
if (!is_array($c)) { $c = []; }
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
if (($c['_all'] ?? 0) >= DAILY_MISS || ($c[$ip] ?? 0) >= IP_MISS) {
    if (is_file($f)) { readfile($f); exit; }
    out(['ok' => false, 'error' => 'busy']);
}

// 3) الطلب للخدمة الأصلية
$q['key'] = KEY;
$url = UPSTREAM . '?' . http_build_query($q);
$res = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => 1]);
    $res = curl_exec($ch);
    curl_close($ch);
} else {
    $res = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
}
$c['_all'] = ($c['_all'] ?? 0) + 1;
$c[$ip] = ($c[$ip] ?? 0) + 1;
@file_put_contents($cf, json_encode($c), LOCK_EX);

$j = $res ? json_decode($res, true) : null;
if (!is_array($j)) {
    if (is_file($f)) { readfile($f); exit; }
    out(['ok' => false, 'error' => 'upstream']);
}
if (empty($j['ok'])) {
    $e = $j['error'] ?? 'upstream';
    if ($e === 'daily_limit_reached') {
        if (is_file($f)) { readfile($f); exit; }
        out(['ok' => false, 'error' => 'busy']);
    }
    out(['ok' => false, 'error' => in_array($e, ['invalid_key', 'key_disabled', 'not_found'], true) ? $e : 'upstream']);
}
@file_put_contents($f, $res, LOCK_EX);
echo $res;
