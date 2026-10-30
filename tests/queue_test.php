<?php
declare(strict_types=1);

// Run: php tests/queue_test.php
// Rotation with a fake clock, then parallel polls racing on an expired video.
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Queue.php';

$video = fn(string $id) => ['id' => $id, 'title' => $id, 'author' => '', 'thumbnail' => ''];
$config = ['rotation_seconds' => 20, 'max_queue_length' => 20];

if (($argv[1] ?? '') === 'child') {
    time_sleep_until((float) $argv[3]); // start all children together
    (new Queue(new Store($argv[2]), $config))->snapshot();
    exit(0);
}

$failures = 0;
$expect = function (string $label, mixed $got, mixed $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
};
$tmp = fn() => sys_get_temp_dir() . '/brainz-queue-' . bin2hex(random_bytes(4));
$playing = fn(array $s) => $s['now']['id'] ?? null;

// Fake clock.
$dir = $tmp();
$t = 1000;
$store = new Store($dir);
$q = new Queue($store, $config, function () use (&$t) { return $t; });

$expect('empty stays empty', $q->snapshot(), Queue::EMPTY);
$q->add($video('a'), '', 'x');
$q->add($video('b'), '', 'x');
$s = $q->snapshot();
$expect('first starts immediately', [$playing($s), $s['now']['started_at'], count($s['queue'])], ['a', 1000, 1]);
$t = 1019;
$expect('still a at 19s', $playing($q->snapshot()), 'a');
$expect('remaining 1s', $q->remaining($q->snapshot()), 1);
$t = 1020;
$s = $q->snapshot();
$expect('b at 20s', [$playing($s), $s['now']['started_at'], $s['queue']], ['b', 1020, []]);
$t = 5000; // long gap with nobody polling: advance one step, never skip queued items
$q->add($video('c'), '', 'x');
$s = $q->snapshot();
$expect('c after long gap', [$playing($s), $s['now']['started_at']], ['c', 5000]);
$t = 5020;
$s = $q->snapshot();
$expect('empty when queue runs out', [$s['now'], $q->remaining($s)], [null, null]);
$history = $store->read('history');
$expect('history newest first', array_column($history, 'id'), ['c', 'b', 'a']);
$expect('history ended_at', $history[0]['ended_at'], 5020);
$q->add($video('d'), '', 'x');
$expect('new item starts when idle', $playing($q->snapshot()), 'd');

// Parallel polls on an expired video: exactly one advance.
$dir2 = $tmp();
$store2 = new Store($dir2);
$store2->update('state', function (array &$s) use ($video) {
    $s = ['now' => $video('p0') + ['started_at' => time() - 60], 'queue' => [$video('p1'), $video('p2'), $video('p3')]];
});
$procs = [];
$go = (string) (microtime(true) + 0.5);
for ($i = 0; $i < 10; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, 'child', $dir2, $go], [], $pipes);
foreach ($procs as $p) proc_close($p);
$s = $store2->read('state');
$expect('one advance under race', [$playing($s), array_column($s['queue'], 'id')], ['p1', ['p2', 'p3']]);
$expect('one history entry', array_column($store2->read('history'), 'id'), ['p0']);

// Deterministic version of that race: every read() returns the stale pre-advance state,
// as if each poll read just before another one advanced. Only the re-check under the lock stops a double advance.
final class StaleStore extends Store
{
    public ?array $stale = null;
    public function read(string $name, array $default = []): array
    {
        return $name === 'state' && $this->stale ? $this->stale : parent::read($name, $default);
    }
}
$dir3 = $tmp();
$store3 = new StaleStore($dir3);
$store3->update('state', function (array &$s) use ($video) {
    $s = ['now' => $video('s0') + ['started_at' => 0], 'queue' => [$video('s1'), $video('s2')]];
});
$store3->stale = $store3->read('state');
$q3 = new Queue($store3, $config, fn() => 100);
$q3->snapshot();
$q3->snapshot();
$store3->stale = null;
$expect('stale reads advance once', $playing($store3->read('state')), 's1');

foreach ([$dir, $dir2, $dir3] as $d) { array_map('unlink', glob("$d/{*,.htaccess}", GLOB_BRACE)); rmdir($d); }
echo $failures ? "$failures failure(s)\n" : "ok: rotation + 10 racing polls\n";
exit($failures ? 1 : 0);
