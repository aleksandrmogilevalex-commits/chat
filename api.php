<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

// ============================================================
// API чата. Все ответы — JSON.
//   GET  ?action=sync[&thread_id=&after_id=&focused=1]  — диалоги + новые сообщения
//   GET  ?action=history&thread_id=&before_id=          — история (прокрутка вверх)
//   POST ?action=send     (thread_id, body[, file])     — отправить сообщение
//   POST ?action=start    (recipient_id[, subject])     — найти/создать диалог 1-на-1
//   POST ?action=typing   (thread_id)                   — «печатает…»
// ============================================================

$cfg = chat_config();
$me  = chat_current_user_id();
if (!$me) {
    json_out(['error' => 'auth'], 401);
}
chat_touch_presence($me);
$pdo = chat_db();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

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
                    AND m2.sender_id <> :meA
                    AND m2.created_at > COALESCE(mep.last_read, '1000-01-01')) AS unread
             FROM chat_threads t
             JOIN chat_participants mep ON mep.thread_id = t.id AND mep.user_id = :meB
             LEFT JOIN chat_participants o  ON o.thread_id = t.id AND o.user_id <> :meC
             LEFT JOIN chat_messages lm ON lm.id = (SELECT MAX(m.id) FROM chat_messages m WHERE m.thread_id = t.id)
             ORDER BY t.updated_at DESC
             LIMIT 100"
        );
        $st->execute([':meA' => $me, ':meB' => $me, ':meC' => $me]);
        $rows = $st->fetchAll();

        $names  = chat_users_info(array_map(fn($r) => (int)$r['other_id'], $rows));
        $online = chat_online_map(array_keys($names));
        $now    = time();

        $threads = [];
        foreach ($rows as $r) {
            $oid = (int)$r['other_id'];
            $name = $names[$oid]['name'] ?? ('Пользователь #' . $oid);
            // предпросмотр последнего сообщения
            if ($r['lm_ts'] === null) {
                $last = null;
            } elseif ((int)$r['lm_has_file'] === 1) {
                $last = [
                    'text' => ((int)$r['lm_is_image'] === 1 ? '🖼 Фото' : '📎 Файл')
                            . ($r['lm_file_name'] ? ' · ' . $r['lm_file_name'] : ''),
                    'ts'   => (int)$r['lm_ts'],
                    'mine' => (int)$r['lm_sender'] === $me,
                ];
            } else {
                $last = [
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

        $out = ['me' => ['id' => $me], 'threads' => $threads];

        // --- Открытый диалог: новые сообщения + статус собеседника
        if ($threadId > 0 && chat_is_participant($threadId, $me)) {
            if ($afterId > 0) {
                $st = $pdo->prepare(
                    "SELECT m.id, m.thread_id, m.sender_id, m.body,
                            (m.file_path IS NOT NULL) AS has_file,
                            m.file_name, m.file_size, m.is_image,
                            UNIX_TIMESTAMP(m.created_at) AS ts
                       FROM chat_messages m
                      WHERE m.thread_id = :t AND m.id > :after
                      ORDER BY m.id ASC LIMIT {$cfg['messages_page']}"
                );
                $st->execute([':t' => $threadId, ':after' => $afterId]);
                $msgs = $st->fetchAll();
            } else {
                $st = $pdo->prepare(
                    "SELECT m.id, m.thread_id, m.sender_id, m.body,
                            (m.file_path IS NOT NULL) AS has_file,
                            m.file_name, m.file_size, m.is_image,
                            UNIX_TIMESTAMP(m.created_at) AS ts
                       FROM chat_messages m
                      WHERE m.thread_id = :t
                      ORDER BY m.id DESC LIMIT {$cfg['messages_page']}"
                );
                $st->execute([':t' => $threadId]);
                $msgs = array_reverse($st->fetchAll());
            }

            // собеседник (первый другой участник)
            $st = $pdo->prepare(
                "SELECT user_id, UNIX_TIMESTAMP(last_read) AS lr, UNIX_TIMESTAMP(typing_until) AS tp
                   FROM chat_participants
                  WHERE thread_id = :t AND user_id <> :u
                  LIMIT 1"
            );
            $st->execute([':t' => $threadId, ':u' => $me]);
            $o = $st->fetch();
            $out['open'] = [
                'thread_id' => $threadId,
                'messages'  => array_map('chat_message_row', $msgs),
                'other'     => $o ? [
                    'id'           => (int)$o['user_id'],
                    'last_read_ts' => $o['lr'] !== null ? (int)$o['lr'] : 0,
                    'typing'       => $o['tp'] !== null && (int)$o['tp'] > $now,
                    'online'       => (bool)(chat_online_map([(int)$o['user_id']])[(int)$o['user_id']] ?? false),
                ] : null,
            ];

            // Диалог открыт и вкладка активна — отмечаем прочитанным (галочки ✓✓ у собеседника)
            if ($focused) {
                $pdo->prepare(
                    'UPDATE chat_participants SET last_read = NOW()
                      WHERE thread_id = :t AND user_id = :u'
                )->execute([':t' => $threadId, ':u' => $me]);
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
              ORDER BY m.id DESC LIMIT {$cfg['history_page']}"
        );
        $st->execute([':t' => $threadId, ':before' => $beforeId]);
        $msgs = array_reverse($st->fetchAll());
        json_out(['messages' => array_map('chat_message_row', $msgs)]);
    }

    // ------------------------------------------------Отправка сообщения
    case 'send': {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $body     = trim((string)($_POST['body'] ?? ''));
        if ($threadId <= 0 || !chat_is_participant($threadId, $me)) {
            json_out(['error' => 'forbidden'], 403);
        }
        if ($body === '' && empty($_FILES['file'])) {
            json_out(['error' => 'empty'], 400);
        }
        if (mb_strlen($body) > 4000) {
            $body = mb_substr($body, 0, 4000);
        }

        $filePath = null; $fileName = null; $fileSize = null; $isImage = 0;
        if (!empty($_FILES['file'])) {
            [$filePath, $fileName, $fileSize, $isImage] = chat_save_upload($_FILES['file']);
        }

        $pdo->prepare(
            'INSERT INTO chat_messages (thread_id, sender_id, body, file_path, file_name, file_size, is_image)
             VALUES (:t, :u, :b, :fp, :fn, :fs, :im)'
        )->execute([
            ':t' => $threadId, ':u' => $me, ':b' => $body !== '' ? $body : null,
            ':fp' => $filePath, ':fn' => $fileName, ':fs' => $fileSize, ':im' => $isImage,
        ]);
        $msgId = (int)$pdo->lastInsertId();

        // диалог наверх списка + сброс своего «печатает…»
        $pdo->prepare('UPDATE chat_threads SET updated_at = NOW() WHERE id = :t')->execute([':t' => $threadId]);
        $pdo->prepare(
            'UPDATE chat_participants SET typing_until = NULL, last_read = NOW()
              WHERE thread_id = :t AND user_id = :u'
        )->execute([':t' => $threadId, ':u' => $me]);

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
        $subject   = trim((string)($_POST['subject'] ?? ''));
        if ($recipient <= 0 || $recipient === $me) {
            json_out(['error' => 'bad_recipient'], 400);
        }
        if (!chat_users_info([$recipient])) {
            json_out(['error' => 'unknown_user'], 404);
        }

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
        $threadId = $st->fetchColumn();

        if (!$threadId) {
            $pdo->prepare('INSERT INTO chat_threads (subject) VALUES (:s)')
                ->execute([':s' => $subject !== '' ? mb_substr($subject, 0, 255) : null]);
            $threadId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO chat_participants (thread_id, user_id) VALUES (:t, :u), (:t2, :u2)')
                ->execute([':t' => $threadId, ':u' => $me, ':t2' => $threadId, ':u2' => $recipient]);
        }
        json_out(['thread_id' => (int)$threadId]);
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
} catch (Throwable $e) {
    json_out(['error' => 'server', 'message' => $e->getMessage()], 500);
}
