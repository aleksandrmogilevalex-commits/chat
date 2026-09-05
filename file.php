<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

// ============================================================
// Безопасная отдача вложений: файл видят только участники диалога.
// Ссылка вида file.php?id=17 (id сообщения).
// ============================================================

$me = chat_current_user_id();
if (!$me) {
    http_response_code(401);
    exit('Не авторизован');
}
chat_touch_presence($me);

$msgId = (int)($_GET['id'] ?? 0);
if ($msgId <= 0) {
    http_response_code(400);
    exit;
}

$st = chat_db()->prepare(
    'SELECT m.file_path, m.file_name, m.is_image, m.thread_id
       FROM chat_messages m
      WHERE m.id = :id'
);
$st->execute([':id' => $msgId]);
$m = $st->fetch();

if (!$m || !$m['file_path'] || !chat_is_participant((int)$m['thread_id'], $me)) {
    http_response_code(404);
    exit('Не найдено');
}

$base = rtrim(chat_config()['uploads_dir'], '/');
$path = $base . '/' . $m['file_path'];
$real = realpath($path);
$baseReal = realpath($base);
// Сравниваем с разделителем на конце, чтобы каталог-сосед вида
// /path/uploads_chat_evil не прошёл проверку префикса /path/uploads_chat
if ($real === false || $baseReal === false
    || strpos($real, rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit;
}

$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($real);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
} else {
    $extMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'txt' => 'text/plain',
    ];
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mime = $extMap[$ext] ?? $mime;
}

$name = $m['file_name'] ?: basename($real);
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600'); // файл приватный: кэш только в браузере участника
if ((int)$m['is_image'] === 1 && strpos($mime, 'image/') === 0) {
    header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
} else {
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
}
readfile($real);
