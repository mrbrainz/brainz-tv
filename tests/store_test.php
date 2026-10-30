<?php
declare(strict_types=1);

// Run: php tests/store_test.php
// Spawns parallel writers against a temp data dir and checks no update is lost.
require __DIR__ . '/../src/Store.php';

if (($argv[1] ?? '') === 'child') {
    [, , $dir, $id] = $argv;
    (new Store($dir))->update('state', function (array &$s) use ($id) {
        $s['queue'][] = ['id' => $id];
        usleep(random_int(0, 2000)); // widen the race window
    }, ['queue' => []]);
    exit(0);
}

$fail = function (string $msg): never { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };
$dir = sys_get_temp_dir() . '/brainz-store-' . bin2hex(random_bytes(4));
$store = new Store($dir);

if ($store->read('state', ['queue' => []]) !== ['queue' => []]) $fail('default not returned for missing file');
if (!is_file("$dir/.htaccess")) $fail('.htaccess not created');

$n = 20;
$procs = [];
for ($i = 0; $i < $n; $i++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, 'child', $dir, "v$i"], [], $pipes);
}
foreach ($procs as $p) if (proc_close($p) !== 0) $fail('child failed');

$queue = $store->read('state')['queue'];
$ids = array_column($queue, 'id');
sort($ids);
$want = array_map(fn($i) => "v$i", range(0, $n - 1));
sort($want);
if ($ids !== $want) $fail('lost or duplicated updates: got ' . count($ids) . " of $n");
if (glob("$dir/*.tmp")) $fail('temp files left behind');

$result = $store->update('state', function (array &$s) { $s['queue'] = []; return 'cleared'; });
if ($result !== 'cleared' || $store->read('state')['queue'] !== []) $fail('update return/value');

file_put_contents("$dir/broken.json", '{not json');
try { $store->read('broken'); $fail('corrupt JSON not detected'); } catch (JsonException) {}
try { $store->read('../x'); $fail('bad name accepted'); } catch (InvalidArgumentException) {}

array_map('unlink', glob("$dir/{*,.htaccess}", GLOB_BRACE));
rmdir($dir);
echo "ok: $n parallel writers, no lost updates\n";
