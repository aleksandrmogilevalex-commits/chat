-- ============================================================
-- Чат «заказчик ↔ исполнитель» (Telegram-подобный), PHP 8 + MySQL
-- Выполните этот SQL в базе вашего сайта.
-- Таблицы с префиксом chat_ не конфликтуют с вашими.
-- ============================================================

CREATE TABLE chat_threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(255) NULL,                 -- тема (например, «Логотип для сайта» или № заказа)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  last_read_id INT UNSIGNED NOT NULL DEFAULT 0, -- id последнего прочитанного сообщения (галочки ✓✓, счётчик)
  last_read DATETIME NULL,                   -- когда читал в последний раз (информационно)
  typing_until DATETIME NULL,                -- «печатает…» до этого времени
  UNIQUE KEY uniq_thread_user (thread_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  body TEXT NULL,                            -- текст сообщения
  file_path VARCHAR(500) NULL,               -- относительный путь файла внутри uploads/chat
  file_name VARCHAR(255) NULL,               -- оригинальное имя файла
  file_size INT UNSIGNED NULL,
  is_image TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_thread (thread_id, id),
  KEY idx_thread_sender (thread_id, sender_id, id)   -- счётчик непрочитанных
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Присутствие (онлайн-статус). Отдельная таблица, чтобы не трогать вашу users.
CREATE TABLE chat_presence (
  user_id INT UNSIGNED PRIMARY KEY,
  last_seen DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- МИГРАЦИИ ДЛЯ УЖЕ РАБОТАЮЩИХ УСТАНОВОК (новая установка — пропустите)
-- ============================================================
--
-- 1) Обновление до версии с last_read_id (прочитанность по id сообщения,
--    а не по времени). Выполните один раз, затем разверните новые файлы:
--
--   ALTER TABLE chat_participants
--     ADD COLUMN last_read_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id;
--   ALTER TABLE chat_messages ADD KEY idx_thread_sender (thread_id, sender_id, id);
--   -- перенести старые отметки «прочитано» (по времени) в id:
--   UPDATE chat_participants p
--      SET p.last_read_id = COALESCE((SELECT MAX(m.id) FROM chat_messages m
--                                      WHERE m.thread_id = p.thread_id
--                                        AND m.created_at <= p.last_read), 0)
--    WHERE p.last_read IS NOT NULL;
--
-- 2) Только если обновляетесь с самых первых версий, где время хранилось
--    в локальном поясе (сейчас — UTC): сдвиньте старые строки на ваш
--    старый сдвиг (Киев зимой — 3 часа, летом — 2):
--
--   UPDATE chat_messages SET created_at = created_at - INTERVAL 3 HOUR;
--   UPDATE chat_threads SET created_at = created_at - INTERVAL 3 HOUR,
--                           updated_at = updated_at - INTERVAL 3 HOUR;
--   UPDATE chat_participants SET last_read    = last_read    - INTERVAL 3 HOUR,
--                                typing_until = typing_until - INTERVAL 3 HOUR;
--   UPDATE chat_presence SET last_seen = last_seen - INTERVAL 3 HOUR;
--
-- ============================================================

-- ============================================================
-- ДЕМО-ДАННЫЕ (только для локального теста; на продакшене удалите)
-- Замените на таблицу пользователей вашего сайта (см. helpers.php, chat_user_info).
-- ============================================================
CREATE TABLE chat_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO chat_users (id, name) VALUES
  (1, 'Анна Петренко'),   -- заказчик
  (2, 'Игорь Ковалёв'),   -- исполнитель
  (3, 'Максим Белый');    -- второй исполнитель

INSERT INTO chat_threads (id, subject) VALUES (1, 'Логотип для сайта');

INSERT INTO chat_messages (id, thread_id, sender_id, body, created_at) VALUES
  (1, 1, 2, 'Добрый день! Увидел ваш заказ на логотип. Готов взяться.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (2, 1, 1, 'Здравствуйте! Какие сроки?', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (3, 1, 2, 'Первые варианты сделаю за 2 дня, правки — ещё 2–3 дня.', DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
  (4, 1, 1, 'Первый вариант нравится, давайте развивать', DATE_SUB(NOW(), INTERVAL 5 MINUTE));

-- Анна прочитала всё (4), Игорь — до сообщения 3 (последнее от Анны для него непрочитано)
INSERT INTO chat_participants (thread_id, user_id, last_read_id, last_read) VALUES
  (1, 1, 4, DATE_SUB(NOW(), INTERVAL 5 MINUTE)),
  (1, 2, 3, DATE_SUB(NOW(), INTERVAL 20 MINUTE));
