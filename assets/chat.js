/* ============================================================
   Telegram-подобный чат: заказчик ↔ исполнитель
   Vanilla JS без зависимостей. Транспорт: AJAX-поллинг (3 с).
   Использование:
     ChatWidget.mount(document.getElementById('chat'), { api: '/chat/api.php' });
     ChatWidget.openWith(userId, 'Тема заказа'); // кнопка «Написать» на странице заказа
   ============================================================ */
(function () {
  'use strict';

  var state = {
    api: 'api.php',
    me: null,
    threads: [],
    activeId: null,
    msgs: {},        // threadId -> массив сообщений
    afterId: {},     // threadId -> последний полученный id
    other: {},       // threadId -> {id,last_read_ts,typing,online,name}
    typingSentAt: 0,
    timer: null,
    els: {},
    seq: 0,
    loadingOlder: false,
    started: false,
    csrfPromise: null, // промис с CSRF-токеном (кэшируется)
    docBound: false,   // глобальный listener висит один раз
    lastSig: ''        // сигнатура списка диалогов — не перерисовывать зря
  };

  /* ---------------- Утилиты ---------------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function hueOf(seed) {
    var h = 0;
    seed = String(seed);
    for (var i = 0; i < seed.length; i++) { h = (h * 31 + seed.charCodeAt(i)) >>> 0; }
    return h % 360;
  }
  function avatarStyle(seed) {
    var h = hueOf(seed);
    return 'background:linear-gradient(135deg,hsl(' + h + ',62%,55%),hsl(' + ((h + 40) % 360) + ',68%,42%))';
  }
  function initials(name) {
    var p = String(name || '?').trim().split(/\s+/);
    var s = (p[0] ? p[0][0] : '?') + (p[1] ? p[1][0] : '');
    return s.toUpperCase();
  }

  var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }
  function fmtTime(ts) {
    var d = new Date(ts * 1000);
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }
  function fmtDay(ts) {
    var d = new Date(ts * 1000), now = new Date(), y = new Date(now.getTime() - 86400000);
    if (sameDay(d, now)) return 'Сегодня';
    if (sameDay(d, y)) return 'Вчера';
    return d.getDate() + ' ' + MONTHS[d.getMonth()]
      + (d.getFullYear() !== now.getFullYear() ? ' ' + d.getFullYear() : '');
  }
  function fmtListTime(ts) {
    var d = new Date(ts * 1000), now = new Date(), y = new Date(now.getTime() - 86400000);
    if (sameDay(d, now)) return fmtTime(ts);
    if (sameDay(d, y)) return 'Вчера';
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
  }
  function fmtSize(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return bytes + ' Б';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ';
    return (bytes / 1048576).toFixed(1) + ' МБ';
  }

  /* ---------------- API ---------------- */
  /** URL file.php рядом с api.php (правильно работает при встраивании на любой странице). */
  function fileUrl(id) {
    return state.api.replace(/[^/]*$/, '') + 'file.php?id=' + encodeURIComponent(id);
  }

  /** Низкий уровень: сам fetch, без CSRF. token — для POST. */
  function rawApi(action, params, method, token) {
    var url = state.api + '?action=' + encodeURIComponent(action);
    var opt = { method: method || 'GET', credentials: 'same-origin' };
    if (opt.method === 'GET') {
      Object.keys(params || {}).forEach(function (k) {
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      });
    } else {
      var fd = new FormData();
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null) fd.append(k, params[k]);
      });
      opt.body = fd;
      if (token) opt.headers = { 'X-CSRF-Token': token };
    }
    return fetch(url, opt).then(function (r) {
      // Ответ может быть не-JSON (например, 502 от прокси) — не роняем виджет
      return r.json().catch(function () { return { error: 'bad_response' }; }).then(function (data) {
        if (!r.ok) throw new Error((data && data.error) || ('HTTP ' + r.status));
        return data;
      });
    });
  }

  /** Промис с CSRF-токеном (один запрос, кэшируется; сбрасывается при ошибке). */
  function csrfPromise() {
    if (!state.csrfPromise) {
      state.csrfPromise = rawApi('csrf', null, 'GET').then(function (d) {
        return d.token;
      }).catch(function (e) {
        state.csrfPromise = null;
        throw e;
      });
    }
    return state.csrfPromise;
  }

  /**
   * API. Для POST подставляется заголовок X-CSRF-Token; если сервер ответил
   * «csrf» (например, сессия пересоздана) — берём новый токен и повторяем один раз.
   */
  function api(action, params, method) {
    if (method !== 'POST') {
      return rawApi(action, params, method);
    }
    function postOnce(token, mayRetry) {
      return rawApi(action, params, 'POST', token).catch(function (e) {
        if (mayRetry && String(e.message) === 'csrf') {
          state.csrfPromise = null;
          return csrfPromise().then(function (t2) { return postOnce(t2, false); });
        }
        throw e;
      });
    }
    return csrfPromise().then(function (token) { return postOnce(token, true); });
  }

  /* ---------------- Шаблон каркаса ---------------- */
  function layout() {
    return ''
      + '<aside class="tg-side">'
      + '  <div class="tg-side-head"><span class="tg-dot"></span>Сообщения</div>'
      + '  <div class="tg-list"></div>'
      + '</aside>'
      + '<section class="tg-main">'
      + '  <div class="tg-empty">Выберите чат, чтобы начать переписку</div>'
      + '  <div class="tg-chat" hidden>'
      + '    <header class="tg-head">'
      + '      <button class="tg-back" title="Назад">&#8592;</button>'
      + '      <div class="tg-ava sm"><span class="tg-ava-txt"></span><i class="tg-online"></i></div>'
      + '      <div class="tg-head-body">'
      + '        <div class="tg-head-name"></div>'
      + '        <div class="tg-head-status"></div>'
      + '      </div>'
      + '    </header>'
      + '    <div class="tg-msgs"><div class="tg-conn">Переподключение…</div><div class="tg-scroll"></div></div>'
      + '    <footer class="tg-composer">'
      + '      <button class="tg-attach" title="Прикрепить файл">&#128206;</button>'
      + '      <div class="tg-input-wrap"><textarea class="tg-input" placeholder="Сообщение…" rows="1"></textarea></div>'
      + '      <button class="tg-send" title="Отправить">'
      + '        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>'
      + '      </button>'
      + '      <input type="file" hidden>'
      + '    </footer>'
      + '  </div>'
      + '</section>';
  }

  /* ---------------- Монтирование ---------------- */
  function mount(container, opts) {
    opts = opts || {};
    state.api = opts.api || state.api;
    container.classList.add('tg');
    container.innerHTML = layout();

    var q = function (s) { return container.querySelector(s); };
    state.els = {
      root: container, list: q('.tg-list'), empty: q('.tg-empty'), chat: q('.tg-chat'),
      ava: q('.tg-head .tg-ava'), avaTxt: q('.tg-ava-txt'), name: q('.tg-head-name'),
      status: q('.tg-head-status'), scroll: q('.tg-scroll'), input: q('.tg-input'),
      send: q('.tg-send'), attach: q('.tg-attach'), file: q('input[type=file]'),
      back: q('.tg-back'), conn: q('.tg-conn')
    };

    state.els.list.addEventListener('click', function (e) {
      var item = e.target.closest('.tg-item');
      if (item) openThread(parseInt(item.dataset.id, 10));
    });
    state.els.back.addEventListener('click', closeThread);
    state.els.send.addEventListener('click', sendCurrent);
    state.els.attach.addEventListener('click', function () { state.els.file.click(); });
    state.els.file.addEventListener('change', function () { if (state.els.file.files.length) sendCurrent(); });

    state.els.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendCurrent(); }
    });
    state.els.input.addEventListener('input', function () {
      autoGrow();
      var now = Date.now();
      if (state.activeId && now - state.typingSentAt > 3000) {
        state.typingSentAt = now;
        api('typing', { thread_id: state.activeId }, 'POST').catch(function () {});
      }
    });

    state.els.scroll.addEventListener('scroll', function () {
      if (state.els.scroll.scrollTop < 60 && !state.loadingOlder) loadOlder();
    });

    // клик по «Повторить» у несостоявшегося сообщения (делегирование)
    state.els.scroll.addEventListener('click', function (e) {
      var r = e.target.closest ? e.target.closest('.tg-retry') : null;
      if (!r || !state.activeId) return;
      var arr = state.msgs[state.activeId] || [];
      for (var i = 0; i < arr.length; i++) {
        if (arr[i].id === r.dataset.msgid) { resend(arr[i]); break; }
      }
    });

    // document-обработчик вешаем один раз, даже при повторном mount()
    if (!state.docBound) {
      state.docBound = true;
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) tick(); // вернулись на вкладку — сразу обновиться
      });
    }

    if (state.started) return;
    state.started = true;
    tick();
  }

  function autoGrow() {
    var t = state.els.input;
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight, 132) + 'px';
  }

  /* ---------------- Открытие диалога ---------------- */
  function openThread(id) {
    state.activeId = id;
    state.msgs[id] = [];
    state.afterId[id] = 0;
    state.els.empty.hidden = true;
    state.els.chat.hidden = false;
    state.els.root.classList.add('open');
    state.els.scroll.innerHTML = '<div class="tg-loader"></div>';
    renderThreads();
    tick();
  }

  function closeThread() {
    state.activeId = null;
    state.els.root.classList.remove('open');
    state.els.chat.hidden = true;
    state.els.empty.hidden = false;
    renderThreads();
  }

  /** Открыть (или найти/создать) диалог с пользователем — для кнопки «Написать исполнителю». */
  function openWith(userId, subject) {
    return api('start', { recipient_id: userId, subject: subject || '' }, 'POST')
      .then(function (d) { openThread(d.thread_id); return d.thread_id; });
  }

  /* ---------------- Поллинг ---------------- */
  function tick() {
    var params = {
      focused: (document.visibilityState === 'visible' && document.hasFocus()) ? 1 : 0
    };
    if (state.activeId) {
      params.thread_id = state.activeId;
      params.after_id = state.afterId[state.activeId] || 0;
    }
    api('sync', params).then(function (d) {
      state.me = d.me;
      state.threads = d.threads || [];
      state.els.conn.classList.remove('show');
      if (d.open) applyOpen(d.open);
      // Поллинг идёт каждые 3 с — пересобираем список только когда данные
      // реально изменились (иначе теряются hover/выделение текста, лишний CPU)
      var sig = JSON.stringify(d.threads || []);
      if (sig !== state.lastSig) {
        renderThreads();
        updateTitle();
        state.lastSig = sig;
      }
    }).catch(function (e) {
      state.els.conn.classList.toggle('show', String(e.message).indexOf('auth') === -1);
    }).then(function () {
      clearTimeout(state.timer);
      state.timer = setTimeout(tick, document.hidden ? 10000 : 3000);
    });
  }

  function applyOpen(open) {
    var id = open.thread_id;
    if (id !== state.activeId) return;
    var arr = state.msgs[id] || (state.msgs[id] = []);
    var known = {};
    arr.forEach(function (m) { if (typeof m.id === 'number') known[m.id] = true; });
    (open.messages || []).forEach(function (m) {
      if (!known[m.id]) arr.push(m);
    });
    if (arr.length) {
      var maxId = 0;
      arr.forEach(function (m) { if (typeof m.id === 'number' && m.id > maxId) maxId = m.id; });
      state.afterId[id] = maxId;
    }
    var o = open.other;
    if (o) {
      var t = null;
      state.threads.forEach(function (th) { if (th.id === id) t = th; });
      o.name = t && t.other ? t.other.name : (o.name || '');
      state.other[id] = o;
    }
    renderMessages();
  }

  /* ---------------- Отправка ---------------- */
  function sendCurrent() {
    if (!state.activeId) return;
    var input = state.els.input, file = state.els.file.files[0];
    var text = input.value.trim();
    if (!text && !file) return;

    var tid = state.activeId;
    var tmp = {
      id: 'tmp' + (++state.seq),
      sender_id: state.me ? state.me.id : 0,
      body: text,
      ts: Math.floor(Date.now() / 1000),
      pending: true,
      has_file: !!file,
      is_image: file ? !!file.type.match(/^image\//) : false,
      file_name: file ? file.name : null,
      file_size: file ? file.size : null,
      file: file || null   // держим File, чтобы можно было «Повторить»
    };
    (state.msgs[tid] = state.msgs[tid] || []).push(tmp);
    input.value = ''; autoGrow();
    state.els.file.value = '';
    renderMessages(true);

    doSend(tid, tmp);
  }

  /** Общая отправка сообщения (первая попытка и «Повторить»). */
  function doSend(tid, m) {
    m.pending = true; m.fail = false; m.error = '';
    renderMessages();
    var fd = { thread_id: tid, body: m.body };
    if (m.file) fd.file = m.file;
    api('send', fd, 'POST').then(function (d) {
      var arr = state.msgs[tid] || [];
      for (var i = 0; i < arr.length; i++) {
        if (arr[i].id === m.id) { arr.splice(i, 1); break; }
      }
      // поллинг мог успеть получить это же сообщение раньше ответа send — не дублируем
      if (arr.every(function (x) { return x.id !== d.message.id; })) {
        arr.push(d.message);
      }
      arr.sort(function (a, b) {
        var an = typeof a.id === 'number', bn = typeof b.id === 'number';
        if (an && bn) return a.id - b.id;
        return an ? -1 : (bn ? 1 : 0); // временные (pending) — в конец
      });
      var maxId = 0;
      arr.forEach(function (x) { if (typeof x.id === 'number' && x.id > maxId) maxId = x.id; });
      state.afterId[tid] = maxId;
      if (tid === state.activeId) renderMessages(true);
    }).catch(function (e) {
      m.pending = false; m.fail = true; m.error = e.message;
      renderMessages();
    });
  }

  /** Повторить несостоявшееся сообщение (клик по «Повторить»). */
  function resend(m) {
    doSend(state.activeId, m);
  }

  /* ---------------- История вверх ---------------- */
  function loadOlder() {
    if (!state.activeId || state.loadingOlder) return;
    var tid = state.activeId; // фиксируем тред: пользователь может переключиться, пока идёт запрос
    var arr = state.msgs[tid] || [];
    var first = null;
    for (var i = 0; i < arr.length; i++) {
      if (typeof arr[i].id === 'number') { first = arr[i].id; break; }
    }
    if (!first) return;
    state.loadingOlder = true;
    api('history', { thread_id: tid, before_id: first }).then(function (d) {
      if ((d.messages || []).length) {
        state.msgs[tid] = d.messages.concat(state.msgs[tid] || []);
        if (tid === state.activeId) {
          var sc = state.els.scroll, prev = sc.scrollHeight - sc.scrollTop;
          renderMessages();
          sc.scrollTop = sc.scrollHeight - prev; // держим позицию прокрутки
        }
      }
      state.loadingOlder = false;
    }).catch(function () { state.loadingOlder = false; });
  }

  /* ---------------- Рендер: список диалогов ---------------- */
  function renderThreads() {
    var html = '';
    state.threads.forEach(function (t) {
      var name = t.other ? t.other.name : '…';
      var online = t.other && t.other.online;
      var prev = '', time = '';
      if (t.typing) {
        prev = '<span class="tg-prev typing">печатает…</span>';
      } else if (t.last) {
        var txt = esc(t.last.text || '');
        if (t.last.mine) txt = '<span class="you">Вы:</span> ' + txt;
        prev = '<span class="tg-prev">' + txt + '</span>';
        time = fmtListTime(t.last.ts);
      } else {
        prev = '<span class="tg-prev">Нет сообщений</span>';
      }
      var badge = t.unread > 0 ? '<span class="tg-badge">' + (t.unread > 99 ? '99+' : t.unread) + '</span>' : '';
      html += ''
        + '<div class="tg-item' + (t.id === state.activeId ? ' active' : '') + '" data-id="' + t.id + '">'
        + '  <div class="tg-ava' + (online ? ' is-online' : '') + '" style="' + avatarStyle(t.other ? t.other.id : t.id) + '">'
        +      esc(initials(name)) + '<i class="tg-online"></i></div>'
        + '  <div class="tg-item-body">'
        + '    <div class="tg-row1"><span class="tg-name">' + esc(name) + '</span>'
        + '      <span class="tg-time' + (t.unread > 0 ? ' unread' : '') + '">' + time + '</span></div>'
        + '    <div class="tg-row2">' + prev + badge + '</div>'
        + (t.subject ? '<div class="tg-subject">' + esc(t.subject) + '</div>' : '')
        + '  </div>'
        + '</div>';
    });
    state.els.list.innerHTML = html || '<div style="padding:20px;color:#707579">Пока нет диалогов</div>';
  }

  /* ---------------- Рендер: сообщения ---------------- */
  function renderMessages(scrollBottom) {
    if (!state.activeId) return;
    var tid = state.activeId;
    var arr = state.msgs[tid] || [];
    var o = state.other[tid] || {};
    var sc = state.els.scroll;
    var pinned = scrollBottom === true || (sc.scrollHeight - sc.scrollTop - sc.clientHeight < 90);

    // шапка
    var th = null;
    state.threads.forEach(function (x) { if (x.id === tid) th = x; });
    var name = (th && th.other) ? th.other.name : (o.name || '');
    var online = o.online || (th && th.other && th.other.online);
    state.els.ava.style.cssText = avatarStyle(th && th.other ? th.other.id : 1);
    state.els.avaTxt.textContent = initials(name);
    state.els.ava.classList.toggle('is-online', !!online);
    state.els.name.textContent = name;
    var st;
    if (o.typing) st = 'печатает…';
    else if (online) st = 'в сети';
    else st = 'был(а) недавно';
    if (th && th.subject) st = th.subject + ' · ' + st;
    state.els.status.textContent = st;
    state.els.status.className = 'tg-head-status' + (o.typing ? ' typing' : (online ? ' online' : ''));

    // лента
    var html = '';
    var lastDay = '';
    arr.forEach(function (m) {
      var day = fmtDay(m.ts);
      if (day !== lastDay) {
        html += '<div class="tg-day"><span>' + esc(day) + '</span></div>';
        lastDay = day;
      }
      var mine = state.me && m.sender_id === state.me.id;
      var cls = 'tg-m ' + (mine ? 'out' : 'in');
      var inner = '';

      if (m.has_file) {
        var canLink = !m.pending && !m.fail && typeof m.id === 'number';
        if (m.is_image && canLink) {
          cls += ' img' + (m.body ? ' has-text' : '');
          inner += '<img src="' + esc(fileUrl(m.id)) + '" alt="" loading="lazy">';
        } else {
          // пока сообщение не сохранено на сервере (pending/fail) — ссылки ещё нет
          var nameHtml = canLink
            ? '<a class="tg-file-name" href="' + esc(fileUrl(m.id)) + '" download>' + esc(m.file_name || 'файл') + '</a>'
            : '<span class="tg-file-name">' + esc(m.file_name || 'файл') + '</span>';
          inner += '<div class="tg-file">'
            + '<div class="tg-file-ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M5 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8l-6-6H5zm8 1.5L19.5 10H13V3.5zM7 12h10v2H7v-2zm0 4h7v2H7v-2z"/></svg></div>'
            + '<div>' + nameHtml
            + '<div class="tg-file-size">' + esc(fmtSize(m.file_size)) + '</div></div>'
            + '</div>';
        }
      }
      if (m.body) inner += '<div class="tg-text">' + esc(m.body) + '</div>';

      // время + галочки (у своих)
      var checks = '';
      if (mine) {
        if (m.fail) checks = '<span class="tg-checks pending" style="color:#e53935" title="' + esc(m.error || 'Ошибка') + '">!</span>';
        else if (m.pending) checks = '<span class="tg-checks pending">&#128337;</span>';
        else {
          var read = o.last_read_ts && o.last_read_ts >= m.ts;
          checks = read
            ? '<span class="tg-checks" title="Прочитано">&#10003;&#10003;</span>'
            : '<span class="tg-checks single" title="Отправлено">&#10003;</span>';
        }
      }
      inner += '<span class="tg-meta">' + fmtTime(m.ts) + checks + '</span>';

      if (mine && m.fail) {
        inner += '<div class="tg-retry-row"><span class="tg-retry" role="button" data-msgid="'
          + esc(String(m.id)) + '">Повторить отправление</span></div>';
      }

      if (m.pending) cls += ' pending';
      if (m.fail) cls += ' fail';
      html += '<div class="' + cls.trim() + '"><div class="tg-bubble">' + inner + '</div></div>';
    });
    sc.innerHTML = html || '<div class="tg-loader"></div>';
    if (pinned) sc.scrollTop = sc.scrollHeight;
  }

  function updateTitle() {
    var n = 0;
    state.threads.forEach(function (t) { n += t.unread || 0; });
    document.title = (n > 0 ? '(' + n + ') ' : '') + (state.baseTitle || (state.baseTitle = document.title));
  }

  /* ---------------- Публичный API ---------------- */
  window.ChatWidget = { mount: mount, openWith: openWith };
})();
