<?php
declare(strict_types=1);

// Run: php tests/auth_test.php
// Password check and login throttling with a fake clock (no session: CLI).
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Auth.php';

$failures = 0;
$expect = function (string $label, mixed $got, mixed $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
};
$dir = sys_get_temp_dir() . '/brainz-auth-' . bin2hex(random_bytes(4));
$store = new Store($dir);
$hash = password_hash('right-password', PASSWORD_DEFAULT);
$t = 1000;
$clock = function () use (&$t) { return $t; };
$auth = new Auth($store, $hash, '1.2.3.4', $clock);
$other = new Auth($store, $hash, '5.6.7.8', $clock);

$expect('disabled without hash', (new Auth($store, '', '1.2.3.4'))->login('x') !== null, true);
$expect('right password', $auth->login('right-password'), null);
$expect('wrong password', $auth->login('nope'), 'Wrong password.');
for ($i = 0; $i < 3; $i++) $auth->login('nope');
$expect('5th fail still allowed', $auth->login('nope'), 'Wrong password.');
$expect('locked after 5 fails, even with right password', str_starts_with((string) $auth->login('right-password'), 'Too many'), true);
$expect('other IP unaffected', $other->login('right-password'), null);
$expect('IP stored hashed', array_key_exists('1.2.3.4', $store->read('logins')), false);
$t += 61;
$expect('lock lifts after 1 min', $auth->login('nope'), 'Wrong password.');
$expect('6th fail locks for 2 min', str_starts_with((string) $auth->login('right-password'), 'Too many'), true);
$t += 121;
$expect('success after lock', $auth->login('right-password'), null);
$expect('success clears count', $auth->login('nope'), 'Wrong password.');
$t += 86401;
$auth->login('nope');
$expect('old entries pruned', count($store->read('logins')), 1);
$expect('csrf rejects without session', $auth->checkCsrf(''), false);

// Parallel guesses: each attempt's first logins update starts the next attempt, so all 10 begin
// before any password is checked. Only 5 may be tried; the rest must hit the lockout.
final class NestingStore extends Store
{
    public int $left = 0;
    public ?Closure $next = null;
    public function update(string $name, callable $change, array $default = []): mixed
    {
        $result = parent::update($name, $change, $default);
        if ($name === 'logins' && $this->left > 0) { $this->left--; ($this->next)(); }
        return $result;
    }
}
$racing = new NestingStore($dir);
$guesser = new Auth($racing, $hash, '9.9.9.9', $clock);
$tried = 0;
$racing->next = function () use ($guesser, &$tried) { if ($guesser->login('nope') === 'Wrong password.') $tried++; };
$racing->left = 9;
($racing->next)();
$expect('parallel guesses capped at 5', $tried, 5);

array_map('unlink', glob("$dir/{*,.htaccess}", GLOB_BRACE));
rmdir($dir);
echo $failures ? "$failures failure(s)\n" : "ok: login + throttling\n";
exit($failures ? 1 : 0);
