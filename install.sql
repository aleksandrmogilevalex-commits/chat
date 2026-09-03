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
  last_read DATETIME NULL,                   -- до какого момента прочитано (галочки ✓✓)
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
  KEY idx_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Присутствие (онлайн-статус). Отдельная таблица, чтобы не трогать вашу users.
CREATE TABLE chat_presence (
  user_id INT UNSIGNED PRIMARY KEY,
  last_seen DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
INSERT INTO chat_participants (thread_id, user_id, last_read) VALUES
  (1, 1, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
  (1, 2, DATE_SUB(NOW(), INTERVAL 2 MINUTE));

INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES
  (1, 2, 'Добрый день! Увидел ваш заказ на логотип. Готов взяться.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (1, 1, 'Здравствуйте! Какие сроки?', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (1, 2, 'Первые варианты сделаю за 2 дня, правки — ещё 2–3 дня.', DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
  (1, 1, 'Первый вариант нравится, давайте развивать', DATE_SUB(NOW(), INTERVAL 5 MINUTE));
