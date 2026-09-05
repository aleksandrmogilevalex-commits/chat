<?php
declare(strict_types=1);

// ============================================================
// Вспомогательные функции чата: БД, авторизация, пользователи.
// Этот файл не нужно менять, кроме как для интеграции авторизации
// (см. chat_current_user_id и chat_users_info ниже).
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

/**
 * Гарантированно валидный UTF-8 без NUL-байтов.
 * Битые байты в теле сообщения иначе роняли INSERT («Incorrect string value»)
 * и json_encode() всей ленты.
 */
function chat_utf8(string $s): string
{
    $s = str_replace("\0", '', $s);
    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    if (preg_match('//u', $s)) {
        return $s;
    }
    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($clean !== false) {
            return $clean;
        }
    }
    return (string)preg_replace('/[\x80-\xFF]/', '', $s);
}

/**
 * Настройки. config.php — база; config.local.php (в .gitignore) — локальные
 * переопределения (пароли БД и т.п.), чтобы реальные доступы не попадали в git.
 */
function chat_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        if (is_file(__DIR__ . '/config.local.php')) {
            $local = require __DIR__ . '/config.local.php';
            if (is_array($local)) {
                $cfg = array_replace_recursive($cfg, $local);
                if (isset($local['allowed_ext'])) {           // списки заменяем целиком
                    $cfg['allowed_ext'] = $local['allowed_ext'];
                }
            }
        }
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
        // БД храним в UTC: все сравнения (typing, presence) и UNIX_TIMESTAMP()
        // для отображения тогда не зависят от ДВС/смены часового пояса.
        // Локальный пояс остаётся за PHP (date_default_timezone_set).
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/**
 * Старт сессии с безопасными флагами cookie (HttpOnly, SameSite=Lax, Secure
 * на HTTPS). Если сессия сайта уже открыта — ничего не трогаем.
 */
function chat_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
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
        chat_session_start();
        if (isset($_GET['act_as'])) {
            $_SESSION['chat_act_as'] = max(1, (int)$_GET['act_as']);
        }
        return isset($_SESSION['chat_act_as']) ? (int)$_SESSION['chat_act_as'] : null;
    }

    // ИНТЕГРАЦИЯ С ВАШИМ САЙТОМ — раскомментируйте и подставьте своё:
    // chat_session_start();
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
        $out[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => chat_utf8((string)$row['name'])];
    }
    return $out;
}

/**
 * CSRF-токен текущей сессии. Защита всех POST-действий API
 * (send / start / typing) от кросс-сайтовых подделок.
 */
function chat_csrf_token(): string
{
    chat_session_start();
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

/**
 * Простой лимит частоты (анти-спам) на сессию: не больше $limit событий
 * за $seconds секунд. Без активной сессии не ограничивает.
 */
function chat_rate_limit(string $key, int $limit, int $seconds): bool
{
    if ($limit <= 0 || $seconds <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    $now = time();
    $hits = $_SESSION['chat_rl'][$key] ?? [];
    $hits = array_values(array_filter($hits, function ($t) use ($now, $seconds) {
        return (int)$t > $now - $seconds;
    }));
    if (count($hits) >= $limit) {
        $_SESSION['chat_rl'][$key] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION['chat_rl'][$key] = $hits;
    return true;
}

/**
 * Обновить «был(а) в сети». Поллинг идёт каждые 3 с — чтобы не писать в БД
 * на каждый запрос, при активной сессии обновляем не чаще раза в 20 с.
 */
function chat_touch_presence(int $userId): void
{
    $now = time();
    if (session_status() === PHP_SESSION_ACTIVE) {
        if ((int)($_SESSION['chat_presence_uid'] ?? 0) === $userId
            && (int)($_SESSION['chat_presence_at'] ?? 0) > $now - 20) {
            return;
        }
        $_SESSION['chat_presence_uid'] = $userId;
        $_SESSION['chat_presence_at']  = $now;
    }
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
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // JSON_INVALID_UTF8_SUBSTITUTE: одна битая строка в БД не должна ронять весь ответ
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
        'file_name'  => isset($m['file_name']) ? (string)$m['file_name'] : null,
        'file_size'  => isset($m['file_size']) ? (int)$m['file_size'] : null,
        'is_image'   => !empty($m['is_image']),
        'ts'         => (int)$m['ts'],
    ];
}

/** «8M» / «512K» / «1G» из php.ini → байты. */
function chat_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $n = (float)$v;
    switch ($unit) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/**
 * Реальный предел вложения в МБ: минимум из config['max_file_mb'],
 * upload_max_filesize и post_max_size. Иначе при max_file_mb = 10 и
 * php.ini = 2M пользователь видел бы «Файл больше 10 МБ» на файле в 3 МБ.
 */
function chat_effective_max_mb(): int
{
    $mb = (int)chat_config()['max_file_mb'];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = chat_ini_bytes((string)ini_get($key));
        if ($bytes > 0) {
            $mb = min($mb, max(1, (int)floor($bytes / 1048576)));
        }
    }
    return max(1, $mb);
}

/** Расширение → MIME для картинок, которые показываем в пузыре (inline). */
function chat_image_mimes(): array
{
    return [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
}

/**
 * Сохранение вложения. Возвращает [relPath, fileName, size, isImage, absPath].
 *
 * Картинки проверяются по содержимому (getimagesize): SVG/HTML, переименованный
 * в .png, раньше сохранялся и отдавался браузеру inline как image/svg+xml —
 * это stored XSS на домене сайта. Теперь такой файл отклоняется.
 */
function chat_save_upload(array $file): array
{
    $cfg = chat_config();
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE/FORM_SIZE — превышены лимиты PHP/формы
        $msg = $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            ? 'Файл больше ' . chat_effective_max_mb() . ' МБ'
            : 'Ошибка загрузки файла';
        throw new ChatClientError($msg);
    }
    $maxMb = chat_effective_max_mb();
    if ((int)$file['size'] > $maxMb * 1024 * 1024) {
        throw new ChatClientError('Файл больше ' . $maxMb . ' МБ');
    }
    if ((int)$file['size'] <= 0) {
        throw new ChatClientError('Пустой файл');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ChatClientError('Некорректная загрузка');
    }
    $origName = chat_utf8(basename(str_replace('\\', '/', (string)$file['name'])));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $cfg['allowed_ext'], true)) {
        throw new ChatClientError('Тип файла не разрешён' . ($ext !== '' ? ': .' . $ext : ''));
    }

    $isImage = 0;
    $imageMimes = chat_image_mimes();
    if (isset($imageMimes[$ext])) {
        $info = @getimagesize($file['tmp_name']);
        if (!$info || ($info['mime'] ?? '') !== $imageMimes[$ext]) {
            throw new ChatClientError('Файл повреждён или не является изображением .' . $ext);
        }
        $isImage = 1;
    }

    $rel = date('Y/m');
    $dir = rtrim($cfg['uploads_dir'], '/') . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог для файлов: ' . $dir);
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $abs  = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        throw new RuntimeException('Не удалось сохранить файл в ' . $dir);
    }
    return [
        $rel . '/' . $name,
        chat_substr($origName, 0, 200),
        (int)$file['size'],
        $isImage,
        $abs,
    ];
}
