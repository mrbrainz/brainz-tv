<?php
// Admin dashboard (logged in). Rendered client-side from ?api=admin-state; actions POST to ?api=admin-action.
// Uses $h, $auth and $flash from index.php.
?>
  <style>
    .bar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; }
    .bar h1 { margin: 0; }
    .bar form, .row { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
    .bar button, .row button, .acts button { width: auto; padding: .4rem .7rem; font-size: .875rem; }
    button.ghost { background: transparent; color: var(--fg); border-color: var(--line); font-weight: 400; }
    button.danger { background: var(--err); border-color: var(--err); }
    .item { grid-template-columns: auto 96px 1fr auto; }
    .acts { display: flex; gap: .25rem; flex-wrap: wrap; justify-content: flex-end; }
    .settings { display: grid; grid-template-columns: 1fr 7rem; gap: .5rem 1rem; align-items: center; }
    .settings label.check { grid-column: 1 / -1; display: flex; gap: .5rem; align-items: center; }
    .settings input[type=checkbox] { width: auto; }
    .settings button { grid-column: 1 / -1; }
    .badge { font-size: .75rem; padding: .1rem .4rem; border-radius: 4px; background: var(--accent); color: #fff; margin-left: .4rem; }
    @media (max-width: 480px) { .item { grid-template-columns: 64px 1fr; } .item .pos { display: none; } .item img { width: 64px; } .acts { grid-column: 1 / -1; } }
  </style>
  <main>
    <div class="bar">
      <h1>brainz-tv admin</h1>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf" value="<?= $h($auth->csrfToken()) ?>">
        <button class="ghost">Log out</button>
      </form>
    </div>
    <p class="msg" id="msg" role="status"><?= $h($flash['message'] ?? '') ?></p>

    <h2>Now playing <span id="paused-badge"></span></h2>
    <div id="now"></div>
    <div class="row" style="margin-top:.5rem">
      <button data-act="skip">Skip</button>
      <button data-act="pause" id="pause-btn" class="ghost">Pause</button>
    </div>

    <div id="pending-wrap" hidden>
      <h2>Waiting for approval</h2>
      <div id="pending"></div>
    </div>

    <h2>Up next</h2>
    <div id="queue"></div>
    <div class="row" style="margin-top:.5rem"><button data-act="clear" class="danger">Clear queue</button></div>

    <h2>Add a video</h2>
    <form id="add" class="row">
      <input name="video" required placeholder="YouTube link or video ID" style="flex:2 1 14rem">
      <input name="name" maxlength="30" placeholder="Credit (optional)" style="flex:1 1 8rem">
      <button>Add</button>
    </form>

    <h2>Settings</h2>
    <form id="settings" class="settings">
      <label for="s-rot">Seconds per video</label><input id="s-rot" name="rotation_seconds" type="number" min="10" max="3600" required>
      <label for="s-max">Max queue length</label><input id="s-max" name="max_queue_length" type="number" min="1" max="200" required>
      <label for="s-per">Per-listener limit</label><input id="s-per" name="per_user_limit" type="number" min="1" max="50" required>
      <label for="s-vol">Overlay volume (0 = muted)</label><input id="s-vol" name="overlay_volume" type="number" min="0" max="100" required>
      <label class="check"><input type="checkbox" name="submissions_open"> Requests open</label>
      <label class="check"><input type="checkbox" name="approval"> Approve requests before they're queued</label>
      <button>Save settings</button>
    </form>

    <h2>Recently played</h2>
    <div id="history"></div>
  </main>
  <script>
  (() => {
    const csrf = <?= json_encode($auth->csrfToken()) ?>;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const mmss = s => s == null ? '' : Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    const row = (i, pos, acts = '') => `<li class="item"><span class="pos">${pos}</span><img src="${esc(i.thumbnail)}" alt="" loading="lazy"><div><div class="title">${esc(i.title)}</div><div class="meta">${esc(i.author)}${i.name ? ' · ' + esc(i.name) : ''}</div></div><div class="acts">${acts}</div></li>`;
    const list = (items, empty) => items.length ? `<ol>${items.join('')}</ol>` : `<p class="empty">${empty}</p>`;
    let state = null, settingsDirty = false, remaining = null;

    function say(text, ok) { $('msg').textContent = text; $('msg').className = 'msg ' + (ok ? 'ok' : 'err'); }

    function render() {
      const s = state;
      $('now').innerHTML = s.now ? `<ol>${row(s.now, '▶', `<span class="meta" id="left">${mmss(remaining)}</span>`)}</ol>` : '<p class="empty">Nothing playing.</p>';
      $('paused-badge').innerHTML = s.paused_at ? '<span class="badge">paused</span>' : '';
      $('pause-btn').textContent = s.paused_at ? 'Resume' : 'Pause';
      $('pause-btn').dataset.act = s.paused_at ? 'resume' : 'pause';
      $('pending-wrap').hidden = !s.pending.length && !s.settings.approval;
      $('pending').innerHTML = list(s.pending.map((i, n) => row(i, n + 1,
        `<button data-act="approve" data-uid="${esc(i.uid)}">Approve</button><button data-act="remove" data-uid="${esc(i.uid)}" class="ghost">Reject</button>`)), 'No requests waiting.');
      $('queue').innerHTML = list(s.queue.map((i, n) => row(i, n + 1,
        `<button data-act="move" data-by="-1" data-uid="${esc(i.uid)}" class="ghost" aria-label="Move up">↑</button><button data-act="move" data-by="1" data-uid="${esc(i.uid)}" class="ghost" aria-label="Move down">↓</button><button data-act="remove" data-uid="${esc(i.uid)}" class="ghost" aria-label="Remove">✕</button>`)), 'The queue is empty.');
      $('history').innerHTML = list(s.history.map(i => row(i, '')), 'Nothing yet.');
      if (!settingsDirty) {
        const f = $('settings');
        for (const [k, v] of Object.entries(s.settings)) f[k].type === 'checkbox' ? (f[k].checked = v) : (f[k].value = v);
      }
    }

    async function load() {
      try {
        const r = await fetch('?api=admin-state', {cache: 'no-store'});
        if (r.status === 401) return location.reload();
        state = await r.json();
        remaining = state.remaining;
        render();
      } catch {}
    }

    async function act(body) {
      try {
        const r = await fetch('?api=admin-action', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(body)});
        if (r.status === 401 || r.status === 403) return say('Session expired. Reload the page and log in again.');
        const {error} = await r.json();
        if (error) say(error); else if (body.action === 'settings' || body.action === 'add') say(body.action === 'add' ? 'Added.' : 'Settings saved.', true);
        await load();
        return !error;
      } catch { say('Something went wrong. Try again.'); }
    }

    document.addEventListener('click', e => {
      const b = e.target.closest('button[data-act]');
      if (!b) return;
      if (b.dataset.act === 'clear' && !confirm('Clear the whole queue?')) return;
      act({action: b.dataset.act, uid: b.dataset.uid, by: b.dataset.by ? Number(b.dataset.by) : undefined});
    });
    $('add').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target;
      if (await act({action: 'add', video: f.video.value, name: f.name.value})) f.reset();
    });
    $('settings').addEventListener('input', () => { settingsDirty = true; });
    $('settings').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target, settings = {};
      for (const el of f.elements) if (el.name) settings[el.name] = el.type === 'checkbox' ? el.checked : Number(el.value);
      settingsDirty = false;
      await act({action: 'settings', settings});
    });
    // Count down locally between polls.
    setInterval(() => {
      if (state && state.now && !state.paused_at && remaining > 0 && $('left')) $('left').textContent = mmss(--remaining);
    }, 1000);
    setInterval(() => document.hidden || load(), 5000);
    load();
  })();
  </script>
