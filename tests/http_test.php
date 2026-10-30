<?php
declare(strict_types=1);

// Run: php tests/http_test.php
// Starts php -S on a throwaway config and data dir, then checks index.php's routing:
// admin login/CSRF gates, settings validation, the honeypot and what ?api=queue exposes.
$failures = 0;
$expect = function (string $label, mixed $got, mixed $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
};
$dir = sys_get_temp_dir() . '/brainz-http-' . bin2hex(random_bytes(4));
mkdir("$dir/data", 0775, true);
file_put_contents("$dir/config.php", '<?php return ' . var_export([
    'admin_password_hash' => password_hash('test-password', PASSWORD_DEFAULT), 'overlay_token' => 'tok',
    'overlay_volume' => 0, 'rotation_seconds' => 180, 'max_queue_length' => 20, 'per_user_limit' => 2,
    'data_dir' => "$dir/data",
], true) . ';');
$item = fn(string $id, string $by) => ['uid' => "u$id", 'id' => $id, 'title' => "T $id", 'author' => 'A',
    'thumbnail' => '', 'name' => '', 'by' => $by, 'ip' => 'secret-ip-key', 'added_at' => 1];
file_put_contents("$dir/data/state.json", json_encode(['now' => null, 'paused_at' => 1,
    'queue' => [$item('aaaaaaaaaaa', 'someone')], 'pending' => [$item('bbbbbbbbbbb', 'someone')]]));

$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['BRAINZ_CONFIG' => "$dir/config.php"]);

$jar = "$dir/cookies.txt";
// [status, body, headers]
$req = function (string $path, ?array $post = null, array $headers = [], ?string $json = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:$port/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 5]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr($raw, $size), substr($raw, 0, $size)];
};
for ($i = 0; $i < 50 && $req('')[0] === 0; $i++) usleep(100000);

// Listener view: no submitter cookies or IP keys, other people's pending requests hidden.
$queue = json_decode($req("?api=queue")[1], true);
$expect('queue hides by/ip', array_keys($queue['queue'][0] ?? []) === array_diff(array_keys($queue['queue'][0] ?? []), ['by', 'ip']), true);
$expect('queue item present', $queue['queue'][0]['id'] ?? null, 'aaaaaaaaaaa');
$expect('others\' pending hidden', [$queue['my_pending'] ?? null, isset($queue['pending'])], [0, false]);

// Honeypot: claims success, adds nothing.
[, $body] = $req('', ['website' => 'spam', 'video' => 'ccccccccccc'], ['Accept: application/json']);
$expect('honeypot says ok', json_decode($body, true)['ok'] ?? null, true);
$expect('honeypot adds nothing', count(json_decode(file_get_contents("$dir/data/state.json"), true)['queue']), 1);

// Admin gates.
$action = fn(array $in, array $h = []) => $req('?api=admin-action', null, ['Content-Type: application/json', ...$h], json_encode($in));
$expect('state needs login', $req('?api=admin-state')[0], 401);
$expect('action needs login', $action(['action' => 'clear'])[0], 401);
$expect('wrong password refused', str_contains($req('?view=admin', ['action' => 'login', 'password' => 'nope'])[2], 'Location: ?view=admin')
    && $req('?api=admin-state')[0] === 401, true);
$req('?view=admin', ['action' => 'login', 'password' => 'test-password']);
$expect('state after login', $req('?api=admin-state')[0], 200);
preg_match('/const csrf = "([a-f0-9]{64})"/', $req('?view=admin')[1], $m);
$csrf = $m[1] ?? '';
$expect('csrf token on page', strlen($csrf), 64);
$expect('action without csrf', $action(['action' => 'clear'])[0], 403);
$expect('action with bad csrf', $action(['action' => 'clear'], ['X-CSRF-Token: ' . str_repeat('0', 64)])[0], 403);
$expect('form post without csrf', $req('?view=admin', ['action' => 'logout'])[0], 403);
$ok = function (array $in) use ($action, $csrf) {
    $r = json_decode($action($in, ["X-CSRF-Token: $csrf"])[1], true);
    return is_array($r) && array_key_exists('error', $r) ? $r['error'] : 'no json';
};

// Settings validation (saveSettings).
$expect('settings saved', $ok(['action' => 'settings', 'settings' => ['rotation_seconds' => 60, 'approval' => true, 'bogus' => 1]]), null);
$expect('below range', $ok(['action' => 'settings', 'settings' => ['rotation_seconds' => 5]]), 'rotation_seconds must be a whole number from 10 to 3600.');
$expect('string not int', $ok(['action' => 'settings', 'settings' => ['per_user_limit' => '3']]), 'per_user_limit must be a whole number from 1 to 50.');
$expect('bool type', $ok(['action' => 'settings', 'settings' => ['submissions_open' => 1]]), 'submissions_open must be true or false.');
$settings = json_decode($req('?api=admin-state')[1], true)['settings'];
$expect('saved values applied', [$settings['rotation_seconds'], $settings['approval']], [60, true]);
$expect('unknown keys dropped', array_key_exists('bogus', json_decode(file_get_contents("$dir/data/settings.json"), true)), false);
$expect('clear with csrf', $ok(['action' => 'clear']), null);

// Admin input is cleaned and capped like listener input.
$ok(['action' => 'block', 'kind' => 'video', 'key' => "dd\u{0007}dd" . str_repeat('d', 200), 'label' => str_repeat('L', 300)]);
$videos = json_decode($req('?api=admin-state')[1], true)['blocklist']['videos'];
$expect('block key cleaned + capped', array_map('strlen', array_keys($videos)), [100]);
$expect('block label capped', array_map('strlen', array_values($videos)), [200]);

// A flash cookie that isn't a {message} object is ignored, not a 500.
foreach (['%22str%22', '1', '%7B%22ok%22%3A1%7D'] as $bad) {
    $expect("bad flash $bad", $req('', null, ["Cookie: flash=$bad"])[0], 200);
}

// Logout clears the session cookie with the same flags it was set with.
$out = $req('?view=admin', ['action' => 'logout', 'csrf' => $csrf])[2];
$expect('logout cookie flags', (bool) preg_match('/Set-Cookie: btv_admin=deleted.*HttpOnly.*SameSite=Strict/i', $out), true);
$expect('logged out', $req('?api=admin-state')[0], 401);

proc_terminate($server);
proc_close($server);
array_map('unlink', glob("$dir/data/{*,.htaccess}", GLOB_BRACE));
rmdir("$dir/data");
array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo $failures ? "$failures failure(s)\n" : "ok: admin gates, settings, honeypot, listener view\n";
exit($failures ? 1 : 0);
