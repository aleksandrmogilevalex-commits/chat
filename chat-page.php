<?php
require __DIR__ . '/helpers.php';
$cfg = chat_config();
$me  = chat_current_user_id();
$dbError = null;
try {
    $demoUsers = $cfg['demo_auth'] ? chat_users_info([1, 2, 3]) : [];
} catch (Throwable $e) {
    // БД недоступна и т.п. — показываем страницу, а не «белый экран»
    error_log('[chat] ' . $e->getMessage());
    $demoUsers = [];
    $dbError = 'База данных чата недоступна. Проверьте настройки в config.php и выполните install.sql.';
}

$guestMsg = 'Войдите на сайт, чтобы видеть свои сообщения.';
if ($cfg['demo_auth'] && !$me) {
    $guestMsg .= '<br><br>Тест: <a href="?act_as=1" style="color:#3390ec">войти как Анна (заказчик)</a>';
}
if ($dbError !== null) {
    $guestMsg = '<b style="color:#e53935">' . htmlspecialchars($dbError) . '</b>';
    $me = null; // виджет без БД не запускаем
}
$guestBox = '<div style="background:#fff;border-radius:14px;max-width:520px;margin:40px auto;'
    . 'padding:28px;text-align:center;box-shadow:0 2px 20px rgba(23,33,43,.12)">' . $guestMsg . '</div>';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Сообщения</title>
<style>
  html, body { margin: 0; height: 100%; }
  body {
    background: linear-gradient(160deg, #8fb8d8, #b9d0e2 60%, #dbe6f0);
    font: 15px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
  .page { height: 100%; padding: 18px 14px; box-sizing: border-box; }
  .demo-bar {
    max-width: 1100px; margin: 0 auto 12px;
    background: #fff; border-radius: 12px;
    padding: 10px 16px; font-size: 14px; color: #444;
    box-shadow: 0 2px 10px rgba(23,33,43,.10);
  }
  .demo-bar a { color: #3390ec; font-weight: 600; text-decoration: none; margin-right: 14px; }
  .demo-bar a:hover { text-decoration: underline; }
  .demo-bar .cur { background: #3390ec; color: #fff; border-radius: 6px; padding: 2px 8px; }
  .demo-bar code { background: #f1f3f5; border-radius: 5px; padding: 1px 6px; }
  .has-bar #chat { height: calc(100% - 56px); }
  #chat { height: 100%; }
</style>
<link rel="stylesheet" href="assets/chat.css">
</head>
<body<?php if ($cfg['demo_auth']) echo ' class="has-bar"'; ?>>
<div class="page">

<?php if ($cfg['demo_auth']): ?>
  <div class="demo-bar">
    Демо-режим, вы вошли как:
    <?php foreach ($demoUsers as $u): ?>
      <?php if ($me === $u['id']): ?>
        <span class="cur"><?= htmlspecialchars($u['name']) ?></span>
      <?php else: ?>
        <a href="?act_as=<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($me): ?>
      &nbsp;·&nbsp; <a href="#" id="demo-write">Написать <?= $me === 3 ? 'Анне' : 'Максиму' ?></a>
      <small style="color:#888">(пример ChatWidget.openWith)</small>
    <?php endif; ?>
    &nbsp;·&nbsp; на продакшене выключите <code>demo_auth</code> в config.php
  </div>
<?php endif; ?>

  <div id="chat"></div>
</div>

<script src="assets/chat.js"></script>
<script>
<?php if ($me): ?>
  ChatWidget.mount(document.getElementById('chat'), { api: 'api.php' });
  // Пример кнопки «Написать исполнителю» со страницы заказа:
  var w = document.getElementById('demo-write');
  if (w) w.addEventListener('click', function (e) {
    e.preventDefault();
    ChatWidget.openWith(<?= $me === 3 ? 1 : 3 ?>, 'Заказ №128 — логотип');
  });
<?php else: ?>
  document.getElementById('chat').innerHTML = <?= json_encode($guestBox, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
<?php endif; ?>
</script>
</body>
</html>
