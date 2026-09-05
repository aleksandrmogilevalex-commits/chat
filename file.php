<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

// ============================================================
// Безопасная отдача вложений: файл видят только участники диалога.
// Ссылка вида file.php?id=17 (id сообщения).
//
// Картинки (is_image) отдаются inline с MIME по расширению — содержимое
// проверено при загрузке (getimagesize). Всё остальное — только как
// attachment + nosniff, чтобы .txt/.html/.svg не исполнялись на домене сайта.
// ============================================================

try {
    $me = chat_current_user_id();
    if (!$me) {
        http_response_code(401);
        exit('Не авторизован');
    }

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
    if ($real === false || $baseReal === false || !is_file($real)
        || strpos($real, rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(404);
        exit;
    }

    $size  = filesize($real);
    $mtime = filemtime($real);
    if ($size === false || $mtime === false) {
        http_response_code(404);
        exit;
    }

    $ext    = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $images = chat_image_mimes();
    $inline = (int)$m['is_image'] === 1 && isset($images[$ext]);
    if ($inline) {
        $mime = $images[$ext];
    } else {
        // Для скачивания браузеру достаточно octet-stream; для pdf/mp3/mp4
        // ставим настоящий тип, чтобы работали встроенные просмотрщики/плееры.
        $safe = ['pdf' => 'application/pdf', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'zip' => 'application/zip'];
        $mime = $safe[$ext] ?? 'application/octet-stream';
    }

    // Кэш браузера: файл неизменяемый, ETag от id + mtime + size
    $etag = '"' . md5($msgId . '|' . $mtime . '|' . $size) . '"';
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    $name  = (string)($m['file_name'] ?: basename($real));
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
    if (preg_replace('/[^A-Za-z0-9]/', '', $ascii) === '') {
        $ascii = 'file.' . $ext; // имя целиком из кириллицы → «file.ext», а не «_____.ext»
    }
    $disposition = $inline ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)$size);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
    header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    // Не держим буферы для больших файлов
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($real);
} catch (Throwable $e) {
    error_log('[chat] file.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    exit('Ошибка сервера');
}
