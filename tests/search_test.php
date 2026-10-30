<?php
declare(strict_types=1);

// Run: php tests/search_test.php          (offline, fake instances)
//      php tests/search_test.php --live   (also queries the instances in config.example.php)
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Search.php';

$failures = 0;
$expect = function (string $label, mixed $got, mixed $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
};
$dir = sys_get_temp_dir() . '/brainz-search-' . bin2hex(random_bytes(4));
$store = new Store($dir);
$t = 1000;
$clock = function () use (&$t) { return $t; };
$calls = [];
$video = fn(string $id, array $extra = []) => $extra + ['type' => 'video', 'videoId' => $id, 'title' => "T $id", 'author' => 'A', 'lengthSeconds' => 60];
$good = json_encode([$video('aaaaaaaaaaa'), ['type' => 'channel', 'author' => 'x'], $video('bbbbbbbbbbb', ['liveNow' => true]),
    $video('ccccccccccc', ['isUpcoming' => true]), $video('bad id'), $video('ddddddddddd')]);
$fetch = function (string $url) use (&$calls, $good) {
    $calls[] = parse_url($url, PHP_URL_HOST);
    return match (parse_url($url, PHP_URL_HOST)) {
        'down.test' => [0, ''],
        'html.test' => [200, '<html>blocked</html>'],
        'good.test' => [200, $good],
    };
};
$search = new Search($store, ['https://down.test', 'https://html.test/', 'https://good.test'], $fetch, $clock);

$expect('too short', $search->search(' a ', 'ip1'), ['error' => 'Type at least 2 characters.']);
$r = $search->search('  Daft   Punk ', 'ip1');
$expect('falls through to working instance', $calls, ['down.test', 'html.test', 'good.test']);
$expect('filters non-videos, live, upcoming, bad IDs', array_column($r['results'] ?? [], 'id'), ['aaaaaaaaaaa', 'ddddddddddd']);
$expect('youtube thumbnail', $r['results'][0]['thumbnail'] ?? null, 'https://i.ytimg.com/vi/aaaaaaaaaaa/mqdefault.jpg');
$calls = [];
$expect('cached (case/space-insensitive)', [array_column($search->search('daft punk', 'ip1')['results'] ?? [], 'id'), $calls], [['aaaaaaaaaaa', 'ddddddddddd'], []]);
$t += 3601;
$search->search('daft punk', 'ip1');
$expect('cache expires', count($calls), 3);
for ($i = 0; $i < 9; $i++) $search->search("q$i", 'ip1'); // 10 in this minute with the one above
$expect('rate limited at 10/min', $search->search('one more', 'ip1'), ['error' => 'Too many searches. Wait a minute and try again.']);
$expect('other IP fine', isset($search->search('one more', 'ip2')['results']), true);
$t += 61;
$expect('limit resets', isset($search->search('again', 'ip1')['results']), true);
$down = new Search($store, ['https://down.test'], $fetch, $clock);
$expect('all down', $down->search('nothing here', 'ip3'), ['error' => "Search isn't working right now. Paste a YouTube link instead."]);

if (in_array('--live', $argv, true)) {
    $config = require __DIR__ . '/../config.example.php';
    foreach ($config['invidious_instances'] as $base) {
        $live = new Search(new Store($dir . '/live-' . md5($base)), [$base]);
        $r = $live->search('daft punk one more time', 'live');
        echo "live $base: " . (isset($r['results']) ? count($r['results']) . ' results, first: ' . ($r['results'][0]['title'] ?? '-') : $r['error']) . "\n";
    }
}

exec('rm -rf ' . escapeshellarg($dir));
echo $failures ? "$failures failure(s)\n" : "ok: search fallback, filtering, cache, rate limit\n";
exit($failures ? 1 : 0);
