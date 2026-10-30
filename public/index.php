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

// Files from assets/, served through here because the live web root only holds a loader.
if (isset($_GET['asset'])) {
    $assets = ['standby.gif' => 'image/gif'];
    $file = is_string($_GET['asset']) ? $_GET['asset'] : '';
    if (!isset($assets[$file])) notFound();
    header('Content-Type: ' . $assets[$file]);
    header('Cache-Control: public, max-age=86400');
    readfile("$root/assets/$file");
    exit;
}

require "$root/src/Store.php";
require "$root/src/Queue.php";
require "$root/src/YouTube.php";
require "$root/src/Auth.php";
$store = new Store($config['data_dir']);

// Settings the admin can change; saved values in the 'settings' document override config.php.
const SETTINGS = [
    'rotation_seconds' => [10, 3600], 'max_queue_length' => [1, 200], 'per_user_limit' => [1, 50],
    'overlay_volume' => [0, 100], 'submissions_open' => 'bool', 'approval' => 'bool',
    'submit_cooldown_seconds' => [0, 3600], 'replay_cooldown_minutes' => [0, 1440],
];
$config = array_merge(['submissions_open' => true, 'approval' => false, 'submit_cooldown_seconds' => 60,
    'replay_cooldown_minutes' => 60], $config,
    array_intersect_key($store->read('settings'), SETTINGS));
$queue = new Queue($store, $config);
$auth = new Auth($store, $config['admin_password_hash'], $_SERVER['REMOTE_ADDR'] ?? '');

$h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES);

function notFound(): never
{
    http_response_code(404);
    exit('Not found');
}

// Validates and saves admin settings (only keys in SETTINGS). Null or an error message.
function saveSettings(Store $store, array $input): ?string
{
    $clean = [];
    foreach (array_intersect_key($input, SETTINGS) as $key => $value) {
        $rule = SETTINGS[$key];
        if ($rule === 'bool') {
            if (!is_bool($value)) return "$key must be true or false.";
            $clean[$key] = $value;
        } else {
            if (!is_int($value) || $value < $rule[0] || $value > $rule[1]) return "$key must be a whole number from $rule[0] to $rule[1].";
            $clean[$key] = $value;
        }
    }
    $store->update('settings', function (array &$settings) use ($clean) { $settings = $clean + $settings; });
    return null;
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
// Keyed hash of the listener's IP for limits, so raw IPs are never stored.
$ipKey = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $config['overlay_token'] . $config['admin_password_hash']);

$api = $_GET['api'] ?? null;
$view = $_GET['view'] ?? 'user';
$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $api === null && $view === 'user') {
    $name = is_string($_POST['name'] ?? null) ? trim(preg_replace('/[\p{C}]+/u', '', $_POST['name']) ?? '') : '';
    $name = mb_substr($name, 0, 30);
    $input = is_string($_POST['video'] ?? null) ? $_POST['video'] : '';
    // Honeypot: the hidden "website" field is only ever filled in by bots. Pretend it worked.
    $isBot = ($_POST['website'] ?? '') !== '';
    $video = $isBot ? ['ok' => true, 'title' => 'your video'] : (new YouTube())->check($input);
    $error = $isBot ? null : ($video['ok'] ? $queue->add($video, $name, $listener, false, $ipKey) : $video['error']);
    setCookieValue('name', $name, 31536000);
    $result = $error === null
        ? ['ok' => true, 'message' => $config['approval']
            ? "Sent “{$video['title']}” to the DJ for approval."
            : "Added “{$video['title']}” to the queue."]
        : ['ok' => false, 'message' => $error];
    if ($wantsJson) {
        header('Content-Type: application/json');
        exit(json_encode($result));
    }
    setCookieValue('flash', json_encode($result), 60);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

// Admin form posts: login, logout (later admin actions add cases here). Redirects back with a flash message.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $api === null && $view === 'admin') {
    $auth->startSession();
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $error = $auth->login(is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
    } elseif (!$auth->loggedIn() || !$auth->checkCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Session expired. Reload the admin page and try again.');
    } elseif ($action === 'logout') {
        $auth->logout();
        $error = null;
    } else {
        $error = 'Unknown action.';
    }
    if ($error !== null) setCookieValue('flash', json_encode(['ok' => false, 'message' => $error]), 60);
    header('Location: ?view=admin', true, 303);
    exit;
}

// The overlay page and its API need ?token= matching config overlay_token (unset = disabled).
$overlayAllowed = fn(): bool => is_string($_GET['token'] ?? null) && $config['overlay_token'] !== ''
    && hash_equals($config['overlay_token'], $_GET['token']);

if ($api !== null) {
    $routes = [
        'overlay' => function () use ($queue, $overlayAllowed, $config) {
            if (!$overlayAllowed()) {
                http_response_code(403);
                return ['error' => 'Bad overlay token'];
            }
            $state = $queue->snapshot();
            $now = $state['now'];
            return ['volume' => max(0, min(100, (int) $config['overlay_volume'])), 'now' => $now === null ? null : [
                'uid' => $now['uid'], 'id' => $now['id'], 'title' => $now['title'], 'name' => $now['name'],
                'offset' => max(0, ($state['paused_at'] ?? time()) - $now['started_at']), 'remaining' => $queue->remaining($state),
            ], 'paused' => $state['paused_at'] !== null];
        },
        // POST from the overlay when YouTube refuses to play the current video.
        'overlay-error' => function () use ($queue, $overlayAllowed) {
            if (!$overlayAllowed() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(403);
                return ['error' => 'Bad overlay token'];
            }
            return ['error' => $queue->unplayable(is_string($_GET['uid'] ?? null) ? $_GET['uid'] : '')];
        },
        'queue' => function () use ($queue, $listener) {
            $state = $queue->snapshot();
            return Queue::forListener($state, $listener) + ['remaining' => $queue->remaining($state)];
        },
    ];
    // admin-* routes need a logged-in session, and a X-CSRF-Token header when they change things.
    $routes['admin-state'] = function () use ($queue, $store, $config) {
        $state = $queue->snapshot();
        return $state + [
            'remaining' => $queue->remaining($state),
            'settings' => array_intersect_key($config, SETTINGS),
            'history' => array_slice($store->read('history'), 0, 10),
            'blocklist' => $store->read('blocklist', ['videos' => [], 'channels' => []]),
            'unplayable' => $store->read('unplayable'),
        ];
    };
    // POST JSON {action, uid?, by?, video?, name?, settings?} → {error: null|string}
    $routes['admin-action'] = function () use ($queue, $store) {
        // block: {kind: video|channel, key, label?}; unblock: {kind: video|channel|unplayable, key}
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') notFound();
        $in = json_decode((string) file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : [];
        $uid = is_string($in['uid'] ?? null) ? $in['uid'] : '';
        return ['error' => match ($in['action'] ?? null) {
            'skip' => $queue->skip(),
            'pause' => $queue->pause(true),
            'resume' => $queue->pause(false),
            'clear' => $queue->clear(),
            'remove' => $queue->remove($uid),
            'approve' => $queue->approve($uid),
            'move' => $queue->move($uid, is_int($in['by'] ?? null) ? $in['by'] : 0),
            'add' => (function () use ($queue, $in) {
                $video = (new YouTube())->check(is_string($in['video'] ?? null) ? $in['video'] : '');
                $name = is_string($in['name'] ?? null) ? mb_substr(trim($in['name']), 0, 30) : '';
                return $video['ok'] ? $queue->add($video, $name, 'admin', true) : $video['error'];
            })(),
            'block' => $queue->block((string) ($in['kind'] ?? ''), is_string($in['key'] ?? null) ? trim($in['key']) : '',
                is_string($in['label'] ?? null) ? $in['label'] : ''),
            'unblock' => $queue->unblock((string) ($in['kind'] ?? ''), is_string($in['key'] ?? null) ? $in['key'] : ''),
            'settings' => saveSettings($store, is_array($in['settings'] ?? null) ? $in['settings'] : []),
            default => 'Unknown action.',
        }];
    };
    if (is_string($api) && str_starts_with($api, 'admin-')) {
        $auth->startSession();
        if (!$auth->loggedIn() || ($_SERVER['REQUEST_METHOD'] !== 'GET' && !$auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            http_response_code($auth->loggedIn() ? 403 : 401);
            header('Content-Type: application/json');
            exit(json_encode(['error' => $auth->loggedIn() ? 'Bad CSRF token' : 'Not logged in']));
        }
    }
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

if ($view === 'admin') {
    $auth->startSession();
    header('Cache-Control: no-store');
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
    <?php if (!$config['submissions_open']): ?><p class="msg err">Requests are closed right now.</p><?php endif; ?>
    <form method="post" id="submit"<?= $config['submissions_open'] ? '' : ' hidden' ?>>
      <input name="name" maxlength="30" placeholder="Your name (optional)" value="<?= $h($savedName) ?>" autocomplete="nickname">
      <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
      <input name="video" required placeholder="YouTube link or video ID" autocomplete="off">
      <button>Add to queue</button>
    </form>
    <p class="msg <?= $flash ? ($flash['ok'] ? 'ok' : 'err') : '' ?>" id="msg" role="status"><?= $h($flash['message'] ?? '') ?></p>
    <p class="meta" id="pending-note"><?= $state['my_pending'] ? $h("You have {$state['my_pending']} request(s) waiting for the DJ's approval.") : '' ?></p>

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
        $('pending-note').textContent = s.my_pending ? `You have ${s.my_pending} request(s) waiting for the DJ's approval.` : '';
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
<?php elseif (!$auth->loggedIn()): ?>
  <main>
    <h1>brainz-tv admin</h1>
    <?php if (!$auth->enabled()): ?>
      <p class="msg err">Admin is disabled: set admin_password_hash in config.php (see <code>php tools/hash-password.php</code>).</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" required placeholder="Password" autocomplete="current-password" autofocus>
        <button>Log in</button>
      </form>
    <?php endif; ?>
    <p class="msg err" role="status"><?= $h($flash['message'] ?? '') ?></p>
  </main>
<?php else: require "$root/src/admin.php"; ?>
<?php endif; ?>
</body>
</html>
