<?php
declare(strict_types=1);

// ============================================================
// Вспомогательные функции чата: БД, авторизация, пользователи.
// Этот файл не нужно менять, кроме как для интеграции авторизации
// (см. chat_current_user_id и chat_user_info ниже).
// ============================================================

/**
 * Ошибка «вины клиента» (файл слишком большой, запрет тип и т.п.).
 * api.php превращает её в HTTP 400, а не 500, и текст можно
 * показывать пользователю (это не технические детали).
 */
class ChatClientError extends RuntimeException {}

// UTF-8-безопасные строковые функции: используют mbstring, если расширение
// установлено, иначе корректный fallback (иначе API падал бы с 500 на хостингах
// без php-mbstring: "Call to undefined function mb_strlen()").
function chat_strlen(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return preg_match_all('/./us', $s) ?: strlen($s);
}

function chat_substr(string $s, int $start, ?int $len = null): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, $start, $len, 'UTF-8');
    }
    if (preg_match_all('/./us', $s, $m)) {
        return implode('', array_slice($m[0], $start, $len));
    }
    return $len === null ? substr($s, $start) : substr($s, $start, $len);
}

function chat_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Kyiv');
    }
    return $cfg;
}

/** Единое PDO-подключение. БД работает в UTC (см. ниже). */
function chat_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = chat_config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // БД храним в UTC: все сравнения (last_read, typing, unread) и
        // UNIX_TIMESTAMP() для отображения тогда не зависят от ДВС/смены
        // часового пояса. Локальный пояс остаётся за PHP (date_default_timezone_set).
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/**
 * >>> ГЛАВНАЯ ТОЧКА ИНТЕГРАЦИИ <<<
 * Должна вернуть ID текущего пользователя вашего сайта или null.
 *
 * По умолчанию (config: demo_auth = true) работает тестовый вход: /chat-page.php?act_as=1
 *
 * Когда на сайте готова своя авторизация:
 *   1) в config.php поставьте 'demo_auth' => false
 *   2) раскомментируйте строку ниже, подставив имя вашей сессионной переменной.
 */
function chat_current_user_id(): ?int
{
    $cfg = chat_config();
    if (!empty($cfg['demo_auth'])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (isset($_GET['act_as'])) {
            $_SESSION['chat_act_as'] = max(1, (int)$_GET['act_as']);
        }
        return isset($_SESSION['chat_act_as']) ? (int)$_SESSION['chat_act_as'] : null;
    }

    // ИНТЕГРАЦИЯ С ВАШИМ САЙТОМ — раскомментируйте и подставьте своё:
    // if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    // return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    return null; // по умолчанию: не авторизован
}

/**
 * Информация о пользователях: [id => ['id'=>.., 'name'=>..]]
 * По умолчанию берётся таблица из config.php. На продакшене проще всего
 * указать вашу таблицу в config, либо перепишите этот запрос вручную.
 *
 * @param int[] $ids
 * @return array<int, array{id:int, name:string}>
 */
function chat_users_info(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $cfg = chat_config();
    // Имена таблицы/колонки берутся из config, но на всякий случай валидируем:
    // в SQL они подставляются напрямую (плейсхолдеры для идентификаторов невозможны).
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$cfg['users_table']);
    $col   = preg_replace('/[^A-Za-z0-9_]/', '', (string)$cfg['users_name_col']);
    if ($table === '' || $col === '') {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $st   = chat_db()->prepare("SELECT id, `{$col}` AS name FROM `{$table}` WHERE id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    return $out;
}

/**
 * CSRF-токен текущей сессии. Защита всех POST-действий API
 * (send / start / typing) от кросс-сайтовых подделок.
 */
function chat_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['chat_csrf'])) {
        $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['chat_csrf'];
}

/**
 * Проверка CSRF для POST-запросов. Токен — заголовок X-CSRF-Token
 * (или поле 'csrf' в теле формы). Если защита включена и токен
 * неверный — сразу ответ 403.
 */
function chat_require_csrf(): void
{
    if (empty(chat_config()['csrf_protection'])) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN']
         ?? ($_POST['csrf'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(chat_csrf_token(), $sent)) {
        json_out(['error' => 'csrf'], 403);
    }
}

/** Обновить «был(а) в сети» для пользователя. */
function chat_touch_presence(int $userId): void
{
    chat_db()->prepare(
        'INSERT INTO chat_presence (user_id, last_seen) VALUES (:u, NOW())
         ON DUPLICATE KEY UPDATE last_seen = NOW()'
    )->execute([':u' => $userId]);
}

/** Онлайн-карта для списка id: [id => bool]. */
function chat_online_map(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $map = array_fill_keys($ids, false);
    if (!$ids) {
        return $map;
    }
    $limit = (int)(chat_config()['online_seconds'] ?? 60);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = chat_db()->prepare(
        "SELECT user_id FROM chat_presence
          WHERE user_id IN ($in) AND last_seen >= DATE_SUB(NOW(), INTERVAL $limit SECOND)"
    );
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $map[(int)$uid] = true;
    }
    return $map;
}

/** JSON-ответ с правильными заголовками. */
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Проверка: пользователь — участник диалога. */
function chat_is_participant(int $threadId, int $userId): bool
{
    $st = chat_db()->prepare(
        'SELECT 1 FROM chat_participants WHERE thread_id = :t AND user_id = :u LIMIT 1'
    );
    $st->execute([':t' => $threadId, ':u' => $userId]);
    return (bool)$st->fetchColumn();
}

/** Строка сообщения в едином формате для API. */
function chat_message_row(array $m): array
{
    return [
        'id'         => (int)$m['id'],
        'thread_id'  => (int)$m['thread_id'],
        'sender_id'  => (int)$m['sender_id'],
        'body'       => (string)($m['body'] ?? ''),
        'has_file'   => !empty($m['has_file']),
        'file_name'  => $m['file_name'] !== null ? (string)$m['file_name'] : null,
        'file_size'  => $m['file_size'] !== null ? (int)$m['file_size'] : null,
        'is_image'   => !empty($m['is_image']),
        'ts'         => (int)$m['ts'],
    ];
}

/** Сохранение вложения. Возвращает [relPath, fileName, size, isImage]. */
function chat_save_upload(array $file): array
{
    $cfg = chat_config();
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE/FORM_SIZE — превышены лимиты PHP/формы
        $msg = $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            ? 'Файл больше ' . $cfg['max_file_mb'] . ' МБ'
            : 'Ошибка загрузки файла';
        throw new ChatClientError($msg);
    }
    $maxBytes = ((int)$cfg['max_file_mb']) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new ChatClientError('Файл больше ' . $cfg['max_file_mb'] . ' МБ');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ChatClientError('Некорректная загрузка');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $cfg['allowed_ext'], true)) {
        throw new ChatClientError('Тип файла не разрешён: .' . $ext);
    }
    $rel = date('Y/m');
    $dir = rtrim($cfg['uploads_dir'], '/') . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new ChatClientError('Не удалось создать каталог для файлов');
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new ChatClientError('Не удалось сохранить файл');
    }
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    return [
        $rel . '/' . $name,
        chat_substr((string)$file['name'], 0, 200),
        (int)$file['size'],
        in_array($ext, $images, true) ? 1 : 0,
    ];
}
