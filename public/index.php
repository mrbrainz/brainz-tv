<?php
declare(strict_types=1);

// Single entry point: ?view=user|admin|overlay renders a page, ?api=... returns JSON.
$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing config.php: copy config.example.php to config.php and fill it in.');
}
$config = require $configPath;

require dirname(__DIR__) . '/src/Store.php';
$store = new Store($config['data_dir']);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);

function notFound(): never
{
    http_response_code(404);
    exit('Not found');
}

if (isset($_GET['api'])) {
    $routes = [];
    $handler = is_string($_GET['api']) ? ($routes[$_GET['api']] ?? notFound()) : notFound();
    header('Content-Type: application/json');
    echo json_encode($handler($config));
    exit;
}

$views = [
    'user' => 'brainz-tv',
    'admin' => 'brainz-tv admin',
    'overlay' => 'brainz-tv overlay',
];
$view = $_GET['view'] ?? 'user';
$title = is_string($view) ? ($views[$view] ?? notFound()) : notFound();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $h($title) ?></title>
  <style>
    :root { color-scheme: light dark; --bg: #fff; --fg: #111; }
    @media (prefers-color-scheme: dark) { :root { --bg: #111; --fg: #eee; } }
    body { margin: 0; padding: 2rem 1rem; font: 16px/1.5 system-ui, sans-serif; background: var(--bg); color: var(--fg); }
    body.overlay { background: transparent; }
    main { max-width: 40rem; margin: 0 auto; }
  </style>
</head>
<body class="<?= $h($view) ?>">
  <main>
    <h1><?= $h($title) ?></h1>
    <p><?= $h($view) ?> view placeholder.</p>
  </main>
</body>
</html>
