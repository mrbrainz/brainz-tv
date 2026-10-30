<?php
declare(strict_types=1);

// Run: php tests/youtube_test.php          (offline)
//      php tests/youtube_test.php --live   (also hits real YouTube oEmbed)
require __DIR__ . '/../src/YouTube.php';

$failures = 0;
$expect = function (string $label, mixed $got, mixed $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
};

$id = 'dQw4w9WgXcQ';
$valid = [
    $id,
    " $id ",
    "https://www.youtube.com/watch?v=$id",
    "http://youtube.com/watch?v=$id",
    "youtube.com/watch?v=$id",
    "https://m.youtube.com/watch?v=$id&feature=share",
    "https://music.youtube.com/watch?v=$id&list=RDAMVM$id",
    "https://www.youtube.com/watch?feature=shared&v=$id&t=42s",
    "https://www.youtube.com/watch?v=$id&list=PL123&index=2",
    "https://youtu.be/$id",
    "https://youtu.be/$id?si=abc123&t=10",
    "https://www.youtube.com/shorts/$id",
    "https://www.youtube.com/shorts/$id?feature=share",
    "https://www.youtube.com/embed/$id?autoplay=1",
    "https://www.youtube-nocookie.com/embed/$id",
    "https://www.youtube.com/live/$id?si=x",
    "https://www.youtube.com/v/$id",
    "HTTPS://WWW.YOUTUBE.COM/watch?v=$id",
];
foreach ($valid as $input) $expect("parse $input", YouTube::parseId($input), $id);

$invalid = [
    '', 'hello', 'dQw4w9WgXc', 'dQw4w9WgXcQQ', 'dQw4w9WgX!Q',
    "https://www.youtube.com/watch?v=",
    "https://www.youtube.com/watch?v=short",
    "https://www.youtube.com/watch?v[]=$id",
    "https://www.youtube.com/channel/UC1234567890",
    "https://www.youtube.com/playlist?list=PL123",
    "https://evil.com/watch?v=$id",
    "https://youtube.com.evil.com/watch?v=$id",
    "https://vimeo.com/12345",
    "javascript:alert(1)",
];
foreach ($invalid as $input) $expect("reject $input", YouTube::parseId($input), null);

// check() against canned oEmbed responses.
$fake = fn(int $status, string $body = '') => new YouTube(fn() => [$status, $body]);
$ok = $fake(200, '{"title":"Never Gonna Give You Up","author_name":"Rick Astley"}')->check($id);
$expect('check ok', [$ok['ok'], $ok['id'], $ok['title'], $ok['author']], [true, $id, 'Never Gonna Give You Up', 'Rick Astley']);
foreach ([401 => 'embedded', 403 => 'embedded', 404 => 'private', 400 => 'private', 0 => 'reach', 500 => 'reach'] as $status => $word) {
    $r = $fake($status)->check($id);
    $expect("check $status", [$r['ok'], str_contains($r['error'], $word)], [false, true]);
}
$expect('check junk skips fetch', (new YouTube(fn() => throw new Exception('fetched')))->check('nope')['ok'], false);
$expect('check bad 200 body', $fake(200, 'not json')->check($id)['ok'], false);

if (in_array('--live', $argv, true)) {
    $yt = new YouTube();
    $r = $yt->check("https://youtu.be/$id");
    $expect('live ok', [$r['ok'], $r['id']], [true, $id]);
    echo "live title: " . ($r['title'] ?? $r['error']) . "\n";
    $expect('live missing', $yt->check('aaaaaaaaaaa')['ok'], false);
}

echo $failures ? "$failures failure(s)\n" : 'ok: ' . count($valid) . ' valid, ' . count($invalid) . " invalid forms\n";
exit($failures ? 1 : 0);
