<?php
declare(strict_types=1);

// Run: php tests/queue_test.php
// Rotation with a fake clock, then parallel polls racing on an expired video.
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Queue.php';

$video = fn(string $id) => ['id' => $id, 'title' => $id, 'author' => '', 'thumbnail' => ''];
$config = ['rotation_seconds' => 20, 'max_queue_length' => 20, 'submissions_open' => true, 'approval' => false,
    'per_user_limit' => 99, 'submit_cooldown_seconds' => 0, 'replay_cooldown_minutes' => 0];

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

// Admin actions.
$dirA = $tmp();
$t = 1000;
$storeA = new Store($dirA);
$qa = new Queue($storeA, $config, function () use (&$t) { return $t; });
$ids = fn() => array_column($qa->snapshot()['queue'], 'id');
foreach (['a', 'b', 'c', 'd'] as $v) $qa->add($video($v), '', 'x');
$expect('a playing', $playing($qa->snapshot()), 'a');
$uid = fn(string $id) => array_column($qa->snapshot()['queue'], 'uid', 'id')[$id];
$qa->move($uid('d'), -10);
$expect('move to front', $ids(), ['d', 'b', 'c']);
$qa->move($uid('d'), 1);
$expect('move down one', $ids(), ['b', 'd', 'c']);
$qa->remove($uid('d'));
$expect('remove', $ids(), ['b', 'c']);
$expect('remove missing', $qa->remove('nope') !== null, true);
$t = 1005;
$qa->pause(true);
$t = 1100;
$s = $qa->snapshot();
$expect('paused: no advance, time frozen', [$playing($s), $qa->remaining($s)], ['a', 15]);
$qa->pause(false);
$s = $qa->snapshot();
$expect('resumed with time left', [$playing($s), $qa->remaining($s)], ['a', 15]);
$t = 1115;
$expect('advances after resumed time', $playing($qa->snapshot()), 'b');
$qa->skip();
$expect('skip', [$playing($qa->snapshot()), $ids()], ['c', []]);
$expect('history from skip', array_slice(array_column($storeA->read('history'), 'id'), 0, 2), ['b', 'a']);
$qa->add($video('e'), '', 'x');
$qa->clear();
$expect('clear keeps now', [$playing($qa->snapshot()), $ids()], ['c', []]);
$closed = new Queue($storeA, ['submissions_open' => false] + $config, fn() => $t);
$expect('closed rejects listeners', $closed->add($video('f'), '', 'x') !== null, true);
$expect('closed allows admin', $closed->add($video('f'), '', 'x', true), null);
$appr = new Queue($storeA, ['approval' => true] + $config, fn() => $t);
$appr->add($video('g'), '', 'listener');
$s = $appr->snapshot();
$expect('approval: goes to pending', [array_column($s['pending'], 'id'), $ids()], [['g'], ['f']]);
$expect('listener sees own pending only', [Queue::forListener($s, 'listener')['my_pending'], Queue::forListener($s, 'other')['my_pending']], [1, 0]);
$expect('pending hidden from listeners', array_key_exists('pending', Queue::forListener($s, 'listener')), false);
$appr->approve($s['pending'][0]['uid']);
$expect('approve', [$appr->snapshot()['pending'], $ids()], [[], ['f', 'g']]);
$full = new Queue($storeA, ['max_queue_length' => 2] + $config, fn() => $t);
$expect('full counts queue', $full->add($video('h'), '', 'x') !== null, true);

// Unplayable videos.
$qa->clear();
$qa->skip();
foreach (['u1', 'u2'] as $v) $qa->add($video($v), '', 'x');
$s = $qa->snapshot();
$expect('u1 playing', $playing($s), 'u1');
$expect('stale uid ignored', $qa->unplayable('nope') !== null, true);
$expect('unplayable skips', [$qa->unplayable($s['now']['uid']), $playing($qa->snapshot())], [null, 'u2']);
$expect('repeat report ignored', [$qa->unplayable($s['now']['uid']) !== null, $playing($qa->snapshot())], [true, 'u2']);
$expect('flagged ID refused', $qa->add($video('u1'), '', 'x') !== null, true);
$expect('flagged ID refused for admin too', $qa->add($video('u1'), '', 'x', true) !== null, true);
$expect('flag stored', array_keys($storeA->read('unplayable')), ['u1']);

// Listener limits and blocklist.
$dirL = $tmp();
$t = 10000;
$storeL = new Store($dirL);
$limits = ['per_user_limit' => 2, 'submit_cooldown_seconds' => 30, 'replay_cooldown_minutes' => 10] + $config;
$ql = new Queue($storeL, $limits, function () use (&$t) { return $t; });
$expect('first add', $ql->add($video('l1'), '', 'me', false, 'ip1'), null);
$expect('cooldown', $ql->add($video('l2'), '', 'me', false, 'ip1'), 'You can add another video in 30 seconds.');
$expect('other IP not cooled down', $ql->add($video('l2'), '', 'you', false, 'ip2'), null);
$t += 30;
$expect('duplicate (playing)', $ql->add($video('l1'), '', 'me', false, 'ip1'), 'That video is already in the queue.');
$expect('duplicate (queued)', $ql->add($video('l2'), '', 'me', false, 'ip1'), 'That video is already in the queue.');
$expect('rejected adds keep cooldown clear', $ql->add($video('l3'), '', 'me', false, 'ip1'), null);
$t += 30;
$expect('per-user limit by cookie', str_starts_with((string) $ql->add($video('l4'), '', 'me', false, 'ip9'), 'You already have 2'), true);
$expect('per-user limit by IP', str_starts_with((string) $ql->add($video('l4'), '', 'new-cookie', false, 'ip1'), 'You already have 2'), true);
$expect('admin bypasses limits', $ql->add($video('l5'), '', 'admin', true), null);
$ql->skip(); // starts l1
$ql->skip(); // ends l1
$t += 60;
$expect('replay cooldown', $ql->add($video('l1'), '', 'other', false, 'ip3'), 'That video played recently. Pick something else for now.');
$t += 601;
$ql->skip(); $ql->skip(); $ql->skip(); $ql->skip();
$expect('replay allowed after cooldown', $ql->add($video('l1'), '', 'other', false, 'ip3'), null);
$ql->block('video', 'b1', 'Some title');
$ql->block('channel', 'Bad Channel');
$expect('blocked video', $ql->add($video('b1'), '', 'z', false, 'ip4'), "That video can't be requested here.");
$expect('blocked channel, any case', $ql->add(['author' => 'bad channel'] + $video('b2'), '', 'z', false, 'ip4'), "That video can't be requested here.");
$expect('admin can add blocked', $ql->add($video('b1'), '', 'admin', true), null);
$ql->unblock('video', 'b1');
$ql->unblock('channel', 'BAD CHANNEL');
$expect('unblocked', $storeL->read('blocklist'), ['videos' => [], 'channels' => []]);
$storeL->update('unplayable', function (array &$l) { $l['x1'] = 1; });
$ql->unblock('unplayable', 'x1');
$expect('unflag unplayable', $storeL->read('unplayable'), []);
$expect('ip hidden from listeners', array_key_exists('ip', Queue::forListener($ql->snapshot(), 'z')['now'] ?? []), false);
$t += 7200;
$ql->add($video('l9'), '', 'q', false, 'ip5');
$expect('old submit times pruned', array_keys($storeL->read('submits')), ['ip5']);

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

foreach ([$dir, $dir2, $dir3, $dirA, $dirL] as $d) { array_map('unlink', glob("$d/{*,.htaccess}", GLOB_BRACE)); rmdir($d); }
echo $failures ? "$failures failure(s)\n" : "ok: rotation + 10 racing polls\n";
exit($failures ? 1 : 0);
