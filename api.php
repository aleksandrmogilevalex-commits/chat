<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

// ============================================================
// API чата. Все ответы — JSON.
//   GET  ?action=sync[&thread_id=&after_id=&focused=1]  — диалоги + новые сообщения
//   GET  ?action=history&thread_id=&before_id=          — история (прокрутка вверх)
//   GET  ?action=csrf                                   — CSRF-токен для POST
//   POST ?action=send     (thread_id, body[, file])     — отправить сообщение
//   POST ?action=start    (recipient_id[, subject])     — найти/создать диалог 1-на-1
//   POST ?action=typing   (thread_id)                   — «печатает…»
// Все POST проверяют CSRF: заголовок X-CSRF-Token с токеном сессии
// (см. config.php: 'csrf_protection').
//
// Прочитанность считается по ID сообщения (chat_participants.last_read_id),
// а не по времени: DATETIME хранит целые секунды, и сообщение, отправленное
// в ту же секунду, что и «прочтение», ошибочно получало ✓✓ / не попадало в unread.
// ============================================================

$action = (string)($_GET['action'] ?? '');

try {
    $cfg = chat_config();
    $me  = chat_current_user_id();
    if (!$me) {
        json_out(['error' => 'auth'], 401);
    }

    $isPost = in_array($action, ['send', 'start', 'typing'], true);
    if ($isPost) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            json_out(['error' => 'method'], 405);
        }
        // Защита от CSRF: все изменяющие действия идут только с токеном сессии
        chat_require_csrf();
    }

    chat_touch_presence($me);
    $pdo = chat_db();

    $pageSync = max(1, (int)($cfg['messages_page'] ?? 200));
    $pageHist = max(1, (int)($cfg['history_page'] ?? 50));
    $now      = time();

    /** Лимит частоты из config: 'rate_limit' => ['send' => [30, 60], ...] */
    $rateLimit = function (string $key) use ($cfg): void {
        $rl = $cfg['rate_limit'][$key] ?? null;
        if (is_array($rl) && count($rl) === 2 && !chat_rate_limit($key, (int)$rl[0], (int)$rl[1])) {
            json_out(['error' => 'Слишком часто. Подождите немного'], 429);
        }
    };

    switch ($action) {

    // ------------------------------------------------CSRF-токен для клиента
    case 'csrf':
        json_out(['token' => chat_csrf_token()]);

    // ------------------------------------------------Sync: список диалогов + сообщения
    case 'sync': {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        $afterId  = (int)($_GET['after_id'] ?? 0);
        $focused  = ($_GET['focused'] ?? '1') === '1';

        // --- Список диалогов (одним запросом)
        $st = $pdo->prepare(
            "SELECT
                t.id   AS thread_id,
                t.subject,
                UNIX_TIMESTAMP(t.updated_at) AS updated_ts,
                o.user_id AS other_id,
                UNIX_TIMESTAMP(o.typing_until) AS other_typing_ts,
                lm.sender_id AS lm_sender,
                lm.body      AS lm_body,
                (lm.file_path IS NOT NULL) AS lm_has_file,
                lm.file_name AS lm_file_name,
                lm.is_image  AS lm_is_image,
                UNIX_TIMESTAMP(lm.created_at) AS lm_ts,
                (SELECT COUNT(*) FROM chat_messages m2
                  WHERE m2.thread_id = t.id
                    AND m2.id > mep.last_read_id
                    AND m2.sender_id <> :meA) AS unread
             FROM chat_threads t
             JOIN chat_participants mep ON mep.thread_id = t.id AND mep.user_id = :meB
             LEFT JOIN chat_participants o  ON o.thread_id = t.id AND o.user_id <> :meC
             LEFT JOIN chat_messages lm ON lm.id = (SELECT MAX(m.id) FROM chat_messages m WHERE m.thread_id = t.id)
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT 100"
        );
        $st->execute([':meA' => $me, ':meB' => $me, ':meC' => $me]);
        $rows = $st->fetchAll();

        $names  = chat_users_info(array_map(function ($r) { return (int)$r['other_id']; }, $rows));
        $online = chat_online_map(array_keys($names));

        $threads = [];
        foreach ($rows as $r) {
            $oid  = (int)$r['other_id'];
            $name = $names[$oid]['name'] ?? ('Пользователь #' . $oid);
            // предпросмотр последнего сообщения
            if ($r['lm_ts'] === null) {
                $last = null;
            } elseif ((int)$r['lm_has_file'] === 1) {
                $isImg = (int)$r['lm_is_image'] === 1;
                $last = [
                    'kind' => $isImg ? 'image' : 'file',
                    'text' => $r['lm_body'] !== null && $r['lm_body'] !== ''
                        ? (string)$r['lm_body']
                        : ($isImg ? 'Фото' : (string)($r['lm_file_name'] ?: 'Файл')),
                    'ts'   => (int)$r['lm_ts'],
                    'mine' => (int)$r['lm_sender'] === $me,
                ];
            } else {
                $last = [
                    'kind' => 'text',
                    'text' => (string)$r['lm_body'],
                    'ts'   => (int)$r['lm_ts'],
                    'mine' => (int)$r['lm_sender'] === $me,
                ];
            }
            $threads[] = [
                'id'      => (int)$r['thread_id'],
                'subject' => $r['subject'] !== null ? (string)$r['subject'] : null,
                'other'   => [
                    'id'     => $oid,
                    'name'   => $name,
                    'online' => (bool)($online[$oid] ?? false),
                ],
                'last'    => $last,
                'unread'  => (int)$r['unread'],
                'typing'  => $r['other_typing_ts'] !== null && (int)$r['other_typing_ts'] > $now,
                'updated_ts' => (int)$r['updated_ts'],
            ];
        }

        $out = [
            'me'      => ['id' => $me],
            'threads' => $threads,
            'limits'  => ['max_file_mb' => chat_effective_max_mb()],
        ];

        // --- Открытый диалог: новые сообщения + статус собеседника
        if ($threadId > 0 && chat_is_participant($threadId, $me)) {
            $hasMore = false;
            if ($afterId > 0) {
                $st = $pdo->prepare(
                    "SELECT m.id, m.thread_id, m.sender_id, m.body,
                            (m.file_path IS NOT NULL) AS has_file,
                            m.file_name, m.file_size, m.is_image,
                            UNIX_TIMESTAMP(m.created_at) AS ts
                       FROM chat_messages m
                      WHERE m.thread_id = :t AND m.id > :after
                      ORDER BY m.id ASC LIMIT $pageSync"
                );
                $st->execute([':t' => $threadId, ':after' => $afterId]);
                $msgs = $st->fetchAll();
            } else {
                // берём на одну строку больше, чтобы сообщить клиенту, есть ли история выше
                $st = $pdo->prepare(
                    "SELECT m.id, m.thread_id, m.sender_id, m.body,
                            (m.file_path IS NOT NULL) AS has_file,
                            m.file_name, m.file_size, m.is_image,
                            UNIX_TIMESTAMP(m.created_at) AS ts
                       FROM chat_messages m
                      WHERE m.thread_id = :t
                      ORDER BY m.id DESC LIMIT " . ($pageSync + 1)
                );
                $st->execute([':t' => $threadId]);
                $msgs = $st->fetchAll();
                if (count($msgs) > $pageSync) {
                    $hasMore = true;
                    array_pop($msgs);
                }
                $msgs = array_reverse($msgs);
            }

            // собеседник (первый другой участник)
            $st = $pdo->prepare(
                "SELECT user_id, last_read_id, UNIX_TIMESTAMP(typing_until) AS tp
                   FROM chat_participants
                  WHERE thread_id = :t AND user_id <> :u
                  LIMIT 1"
            );
            $st->execute([':t' => $threadId, ':u' => $me]);
            $o = $st->fetch();
            $open = [
                'thread_id' => $threadId,
                'after_id'  => $afterId,   // 0 = полная загрузка ленты (тогда has_more осмыслен)
                'messages'  => array_map('chat_message_row', $msgs),
                'has_more'  => $hasMore,
                'other'     => null,
            ];
            if ($o) {
                $oid = (int)$o['user_id'];
                $open['other'] = [
                    'id'           => $oid,
                    'last_read_id' => (int)$o['last_read_id'],
                    'typing'       => $o['tp'] !== null && (int)$o['tp'] > $now,
                    'online'       => (bool)(chat_online_map([$oid])[$oid] ?? false),
                ];
            }
            $out['open'] = $open;

            // Диалог открыт и вкладка активна — отмечаем прочитанным всё, что
            // реально доставлено клиенту (галочки ✓✓ у собеседника)
            if ($focused) {
                $delivered = $afterId;
                foreach ($msgs as $m) {
                    $delivered = max($delivered, (int)$m['id']);
                }
                if ($delivered > 0) {
                    $pdo->prepare(
                        'UPDATE chat_participants
                            SET last_read_id = :id, last_read = NOW()
                          WHERE thread_id = :t AND user_id = :u AND last_read_id < :id2'
                    )->execute([':id' => $delivered, ':t' => $threadId, ':u' => $me, ':id2' => $delivered]);
                }
            }
        }

        json_out($out);
    }

    // ------------------------------------------------История (старые сообщения)
    case 'history': {
        $threadId = (int)($_GET['thread_id'] ?? 0);
        $beforeId = (int)($_GET['before_id'] ?? 0);
        if ($threadId <= 0 || $beforeId <= 0 || !chat_is_participant($threadId, $me)) {
            json_out(['error' => 'bad_request'], 400);
        }
        $st = $pdo->prepare(
            "SELECT m.id, m.thread_id, m.sender_id, m.body,
                    (m.file_path IS NOT NULL) AS has_file,
                    m.file_name, m.file_size, m.is_image,
                    UNIX_TIMESTAMP(m.created_at) AS ts
               FROM chat_messages m
              WHERE m.thread_id = :t AND m.id < :before
              ORDER BY m.id DESC LIMIT " . ($pageHist + 1)
        );
        $st->execute([':t' => $threadId, ':before' => $beforeId]);
        $msgs = $st->fetchAll();
        $hasMore = false;
        if (count($msgs) > $pageHist) {
            $hasMore = true;
            array_pop($msgs);
        }
        json_out(['messages' => array_map('chat_message_row', array_reverse($msgs)), 'has_more' => $hasMore]);
    }

    // ------------------------------------------------Отправка сообщения
    case 'send': {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $body     = chat_utf8(trim((string)($_POST['body'] ?? '')));

        // Если POST-тело превысило post_max_size, PHP вычищает $_POST и $_FILES —
        // тогда «forbidden» был бы ложной диагностикой. Говорим честно.
        if (empty($_POST) && empty($_FILES)) {
            $postMax = chat_ini_bytes((string)ini_get('post_max_size'));
            if ($postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
                json_out(['error' => 'Файл больше ' . chat_effective_max_mb() . ' МБ'], 400);
            }
        }
        if ($threadId <= 0 || !chat_is_participant($threadId, $me)) {
            json_out(['error' => 'forbidden'], 403);
        }
        // «файл есть», только если он реально загружен (пустой input не считается)
        $hasFile = isset($_FILES['file'])
            && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($body === '' && !$hasFile) {
            json_out(['error' => 'empty'], 400);
        }
        $rateLimit('send');
        if (chat_strlen($body) > 4000) {
            $body = chat_substr($body, 0, 4000);
        }

        $filePath = null; $fileName = null; $fileSize = null; $isImage = 0; $absPath = null;
        if ($hasFile) {
            // ошибки валидации файла (ChatClientError) перехватятся ниже -> 400
            [$filePath, $fileName, $fileSize, $isImage, $absPath] = chat_save_upload($_FILES['file']);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO chat_messages (thread_id, sender_id, body, file_path, file_name, file_size, is_image)
                 VALUES (:t, :u, :b, :fp, :fn, :fs, :im)'
            )->execute([
                ':t' => $threadId, ':u' => $me, ':b' => $body !== '' ? $body : null,
                ':fp' => $filePath, ':fn' => $fileName, ':fs' => $fileSize, ':im' => $isImage,
            ]);
            $msgId = (int)$pdo->lastInsertId();

            // диалог наверх списка + сброс своего «печатает…» + своё сообщение прочитано
            $pdo->prepare('UPDATE chat_threads SET updated_at = NOW() WHERE id = :t')->execute([':t' => $threadId]);
            $pdo->prepare(
                'UPDATE chat_participants
                    SET typing_until = NULL, last_read = NOW(), last_read_id = GREATEST(last_read_id, :id)
                  WHERE thread_id = :t AND user_id = :u'
            )->execute([':id' => $msgId, ':t' => $threadId, ':u' => $me]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($absPath !== null && is_file($absPath)) {
                @unlink($absPath); // не оставляем файл-сироту
            }
            throw $e;
        }

        $st = $pdo->prepare(
            "SELECT m.id, m.thread_id, m.sender_id, m.body,
                    (m.file_path IS NOT NULL) AS has_file,
                    m.file_name, m.file_size, m.is_image,
                    UNIX_TIMESTAMP(m.created_at) AS ts
               FROM chat_messages m WHERE m.id = :id"
        );
        $st->execute([':id' => $msgId]);
        json_out(['message' => chat_message_row($st->fetch())]);
    }

    // ------------------------------------------------Найти/создать диалог 1-на-1
    case 'start': {
        $recipient = (int)($_POST['recipient_id'] ?? 0);
        $subject   = chat_utf8(trim((string)($_POST['subject'] ?? '')));
        if ($recipient <= 0 || $recipient === $me) {
            json_out(['error' => 'bad_recipient'], 400);
        }
        if (!chat_users_info([$recipient])) {
            json_out(['error' => 'unknown_user'], 404);
        }

        // Блокировка от гонки: два одновременных «start» не создадут два диалога
        $lockName = 'chat_start_' . min($me, $recipient) . '_' . max($me, $recipient);
        $st = $pdo->prepare('SELECT GET_LOCK(:n, 5)');
        $st->execute([':n' => $lockName]);
        if ((int)$st->fetchColumn() !== 1) {
            json_out(['error' => 'busy'], 503);
        }

        try {
            // существующий диалог ровно с этими двумя участниками (как «личка» в Телеграме)
            $st = $pdo->prepare(
                "SELECT p.thread_id
                   FROM chat_participants p
                  WHERE p.user_id IN (:a, :b)
                  GROUP BY p.thread_id
                 HAVING COUNT(DISTINCT p.user_id) = 2
                    AND (SELECT COUNT(*) FROM chat_participants q WHERE q.thread_id = p.thread_id) = 2
                  ORDER BY p.thread_id DESC
                  LIMIT 1"
            );
            $st->execute([':a' => $me, ':b' => $recipient]);
            $threadId = (int)$st->fetchColumn();
            $created  = false;

            if (!$threadId) {
                $rateLimit('start');
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO chat_threads (subject) VALUES (:s)')
                        ->execute([':s' => $subject !== '' ? chat_substr($subject, 0, 255) : null]);
                    $threadId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO chat_participants (thread_id, user_id) VALUES (:t, :u), (:t2, :u2)')
                        ->execute([':t' => $threadId, ':u' => $me, ':t2' => $threadId, ':u2' => $recipient]);
                    $pdo->commit();
                    $created = true;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } elseif ($subject !== '') {
                // диалог уже есть — обновляем тему (например, новый № заказа)
                $pdo->prepare('UPDATE chat_threads SET subject = :s WHERE id = :t')
                    ->execute([':s' => chat_substr($subject, 0, 255), ':t' => $threadId]);
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute([':n' => $lockName]);
        }
        json_out(['thread_id' => $threadId, 'created' => $created]);
    }

    // ------------------------------------------------«Печатает…»
    case 'typing': {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if ($threadId > 0 && chat_is_participant($threadId, $me)) {
            $pdo->prepare(
                'UPDATE chat_participants SET typing_until = DATE_ADD(NOW(), INTERVAL 5 SECOND)
                  WHERE thread_id = :t AND user_id = :u'
            )->execute([':t' => $threadId, ':u' => $me]);
        }
        json_out(['ok' => true]);
    }

    default:
        json_out(['error' => 'unknown_action'], 404);
    }
} catch (ChatClientError $e) {
    // Ошибка ввода/файла — это не сбой сервера: 400, текст можно показать
    json_out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    // Текст исключения — в лог сервера; клиенту деталей не раскрываем
    // (сообщения PDO могут содержать имена таблиц/колонок и параметры запросов).
    error_log('[chat] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . ' action=' . $action);
    json_out(['error' => 'server'], 500);
}
