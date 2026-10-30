<?php
declare(strict_types=1);

// Single entry point: ?view=user|admin|overlay renders a page, ?api=... returns JSON.
$root = dirname(__DIR__);
$configPath = "$root/config.php";
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing config.php: copy config.example.php to config.php and fill it in.');
}
$config = require $configPath;

require "$root/src/Store.php";
require "$root/src/Queue.php";
require "$root/src/YouTube.php";
$store = new Store($config['data_dir']);
$queue = new Queue($store, $config);

$h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES);

function notFound(): never
{
    http_response_code(404);
    exit('Not found');
}

function setCookieValue(string $name, string $value, int $ttl): void
{
    setcookie($name, $value, [
        'expires' => $ttl ? time() + $ttl : 0, 'path' => '/', 'httponly' => true,
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on', 'samesite' => 'Lax',
    ]);
}

// Anonymous listener ID, so people can spot their own queue entries.
$listener = $_COOKIE['lid'] ?? '';
if (!is_string($listener) || !preg_match('/^[a-f0-9]{32}$/', $listener)) {
    $listener = bin2hex(random_bytes(16));
    setCookieValue('lid', $listener, 31536000);
}

$api = $_GET['api'] ?? null;
$view = $_GET['view'] ?? 'user';
$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $api === null && $view === 'user') {
    $name = is_string($_POST['name'] ?? null) ? trim(preg_replace('/[\p{C}]+/u', '', $_POST['name']) ?? '') : '';
    $name = mb_substr($name, 0, 30);
    $input = is_string($_POST['video'] ?? null) ? $_POST['video'] : '';
    $video = (new YouTube())->check($input);
    $error = $video['ok'] ? $queue->add($video, $name, $listener) : $video['error'];
    setCookieValue('name', $name, 31536000);
    $result = $error === null
        ? ['ok' => true, 'message' => "Added “{$video['title']}” to the queue."]
        : ['ok' => false, 'message' => $error];
    if ($wantsJson) {
        header('Content-Type: application/json');
        exit(json_encode($result));
    }
    setCookieValue('flash', json_encode($result), 60);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

// The overlay page and its API need ?token= matching config overlay_token (unset = disabled).
$overlayAllowed = fn(): bool => is_string($_GET['token'] ?? null) && $config['overlay_token'] !== ''
    && hash_equals($config['overlay_token'], $_GET['token']);

if ($api !== null) {
    $routes = [
        'overlay' => function () use ($queue, $overlayAllowed) {
            if (!$overlayAllowed()) {
                http_response_code(403);
                return ['error' => 'Bad overlay token'];
            }
            $state = $queue->snapshot();
            $now = $state['now'];
            return ['now' => $now === null ? null : [
                'uid' => $now['uid'], 'id' => $now['id'], 'title' => $now['title'], 'name' => $now['name'],
                'offset' => max(0, time() - $now['started_at']), 'remaining' => $queue->remaining($state),
            ]];
        },
        'queue' => function () use ($queue, $listener) {
            $state = $queue->snapshot();
            return Queue::forListener($state, $listener) + ['remaining' => $queue->remaining($state)];
        },
    ];
    $handler = is_string($api) ? ($routes[$api] ?? notFound()) : notFound();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($handler());
    exit;
}

$views = [
    'user' => 'brainz-tv',
    'admin' => 'brainz-tv admin',
    'overlay' => 'brainz-tv overlay',
];
$title = is_string($view) ? ($views[$view] ?? notFound()) : notFound();

if ($view === 'overlay') {
    if (!$overlayAllowed()) {
        http_response_code(403);
        exit($config['overlay_token'] === '' ? 'Overlay disabled: set overlay_token in config.php.' : 'Bad overlay token.');
    }
    require "$root/src/overlay.php";
    exit;
}

$flash = json_decode(is_string($_COOKIE['flash'] ?? null) ? $_COOKIE['flash'] : '', true);
if ($flash) setCookieValue('flash', '', -3600);
$savedName = is_string($_COOKIE['name'] ?? null) ? mb_substr($_COOKIE['name'], 0, 30) : '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $h($title) ?></title>
  <style>
    :root { color-scheme: light dark; --bg: #fff; --fg: #111; --muted: #666; --line: #ddd; --card: #f6f6f6; --accent: #9146ff; --ok: #1a7f37; --err: #c62828; }
    @media (prefers-color-scheme: dark) { :root { --bg: #111; --fg: #eee; --muted: #999; --line: #333; --card: #1c1c1c; --ok: #4ac26b; --err: #ff6b6b; } }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 1.5rem 1rem; font: 16px/1.5 system-ui, sans-serif; background: var(--bg); color: var(--fg); }
    body.overlay { background: transparent; }
    main { max-width: 40rem; margin: 0 auto; }
    h1 { margin: 0 0 1rem; } h2 { font-size: 1.1rem; margin: 1.5rem 0 .5rem; }
    form { display: grid; gap: .5rem; }
    input, button { font: inherit; padding: .6rem .75rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); width: 100%; }
    button { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; cursor: pointer; }
    button:disabled { opacity: .6; }
    .msg { margin: .75rem 0 0; } .msg.ok { color: var(--ok); } .msg.err { color: var(--err); }
    ol { list-style: none; padding: 0; margin: 0; display: grid; gap: .5rem; }
    .item { display: grid; grid-template-columns: auto 96px 1fr; gap: .75rem; align-items: center; background: var(--card); border-radius: 8px; padding: .5rem; }
    .item.mine { outline: 2px solid var(--accent); }
    .item img { width: 96px; aspect-ratio: 16/9; object-fit: cover; border-radius: 4px; display: block; }
    .pos { font-weight: 700; min-width: 1.5rem; text-align: center; color: var(--muted); }
    .title { font-weight: 600; overflow-wrap: anywhere; }
    .meta, .empty { color: var(--muted); font-size: .875rem; }
  </style>
</head>
<body class="<?= $h($view) ?>">
<?php if ($view === 'user'):
    $state = Queue::forListener($queue->snapshot(), $listener); ?>
  <main>
    <h1>brainz-tv</h1>
    <form method="post" id="submit">
      <input name="name" maxlength="30" placeholder="Your name (optional)" value="<?= $h($savedName) ?>" autocomplete="nickname">
      <input name="video" required placeholder="YouTube link or video ID" autocomplete="off">
      <button>Add to queue</button>
    </form>
    <p class="msg <?= $flash ? ($flash['ok'] ? 'ok' : 'err') : '' ?>" id="msg" role="status"><?= $h($flash['message'] ?? '') ?></p>

    <h2>Now playing</h2>
    <div id="now">
      <?php if ($state['now']): $i = $state['now']; ?>
        <ol><li class="item<?= $i['mine'] ? ' mine' : '' ?>"><span class="pos">▶</span><img src="<?= $h($i['thumbnail']) ?>" alt=""><div><div class="title"><?= $h($i['title']) ?></div><div class="meta"><?= $h($i['author']) ?><?= $i['name'] !== '' ? ' · added by ' . $h($i['name']) : '' ?></div></div></li></ol>
      <?php else: ?><p class="empty">Nothing playing.</p><?php endif; ?>
    </div>

    <h2>Up next</h2>
    <div id="queue">
      <?php if ($state['queue']): ?><ol>
        <?php foreach ($state['queue'] as $n => $i): ?>
          <li class="item<?= $i['mine'] ? ' mine' : '' ?>"><span class="pos"><?= $n + 1 ?></span><img src="<?= $h($i['thumbnail']) ?>" alt="" loading="lazy"><div><div class="title"><?= $h($i['title']) ?></div><div class="meta"><?= $h($i['author']) ?><?= $i['name'] !== '' ? ' · added by ' . $h($i['name']) : '' ?><?= $i['mine'] ? ' · yours' : '' ?></div></div></li>
        <?php endforeach; ?>
      </ol><?php else: ?><p class="empty">The queue is empty. Add something!</p><?php endif; ?>
    </div>
  </main>
  <script>
  // Progressive enhancement: the form and lists work without JS; this submits in place and refreshes the lists.
  (() => {
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const row = (i, pos) => `<li class="item${i.mine ? ' mine' : ''}"><span class="pos">${pos}</span><img src="${esc(i.thumbnail)}" alt="" loading="lazy"><div><div class="title">${esc(i.title)}</div><div class="meta">${esc(i.author)}${i.name ? ' · added by ' + esc(i.name) : ''}${i.mine && pos !== '▶' ? ' · yours' : ''}</div></div></li>`;
    async function refresh() {
      try {
        const s = await (await fetch('?api=queue', {cache: 'no-store'})).json();
        $('now').innerHTML = s.now ? `<ol>${row(s.now, '▶')}</ol>` : '<p class="empty">Nothing playing.</p>';
        $('queue').innerHTML = s.queue.length ? `<ol>${s.queue.map((i, n) => row(i, n + 1)).join('')}</ol>` : '<p class="empty">The queue is empty. Add something!</p>';
      } catch {}
    }
    $('submit').addEventListener('submit', async e => {
      e.preventDefault();
      const form = e.target, button = form.querySelector('button'), msg = $('msg');
      button.disabled = true;
      try {
        const r = await (await fetch(location.pathname, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}})).json();
        msg.textContent = r.message;
        msg.className = 'msg ' + (r.ok ? 'ok' : 'err');
        if (r.ok) { form.video.value = ''; refresh(); }
      } catch {
        msg.textContent = 'Something went wrong. Try again.';
        msg.className = 'msg err';
      }
      button.disabled = false;
    });
    setInterval(() => document.hidden || refresh(), 10000);
  })();
  </script>
<?php else: ?>
  <main>
    <h1><?= $h($title) ?></h1>
    <p><?= $h($view) ?> view placeholder.</p>
  </main>
<?php endif; ?>
</body>
</html>
