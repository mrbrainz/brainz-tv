<?php
declare(strict_types=1);

// Admin login: one password (hash in config), PHP session, CSRF token, per-IP throttling.
// Failed logins are counted in the 'logins' document by hashed IP; after FREE_TRIES the IP is
// locked out for a doubling period (1, 2, 4... minutes, max 1 hour).
final class Auth
{
    private const FREE_TRIES = 5;
    private const MAX_LOCK = 3600;

    /** @var callable(): int */
    private $clock;

    public function __construct(private Store $store, private string $hash, private string $ip, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function enabled(): bool
    {
        return $this->hash !== '';
    }

    // Starts the admin session. Only called on admin routes, so listeners never get a session.
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('btv_admin');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public function loggedIn(): bool
    {
        // Sessions are tied to the current password hash, so changing the password logs everyone out.
        return ($_SESSION['admin'] ?? false) === true && $this->enabled()
            && hash_equals(hash('sha256', $this->hash), $_SESSION['pw'] ?? '');
    }

    // Null on success, otherwise an error message.
    public function login(string $password): ?string
    {
        if (!$this->enabled()) {
            return 'Admin is disabled: set admin_password_hash in config.php.';
        }
        $key = hash('sha256', $this->ip);
        $now = ($this->clock)();
        // Check the lockout and count this attempt as a failure in one locked step, so parallel
        // guesses are all counted. A correct password clears the entry below.
        $wait = $this->store->update('logins', function (array &$logins) use ($key, $now) {
            $logins = array_filter($logins, fn($e) => $e['last'] > $now - 86400);
            $wait = max(0, ($logins[$key]['until'] ?? 0) - $now);
            if ($wait > 0) return $wait;
            $fails = ($logins[$key]['fails'] ?? 0) + 1;
            $until = $fails >= self::FREE_TRIES ? $now + min(self::MAX_LOCK, 60 * 2 ** ($fails - self::FREE_TRIES)) : 0;
            $logins[$key] = ['fails' => $fails, 'until' => $until, 'last' => $now];
            return 0;
        });
        if ($wait > 0) {
            return 'Too many failed attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        }
        if (!password_verify($password, $this->hash)) {
            usleep(300000); // slows down scripted guessing
            return 'Wrong password.';
        }
        $this->store->update('logins', function (array &$logins) use ($key) { unset($logins[$key]); });
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION = ['admin' => true, 'pw' => hash('sha256', $this->hash), 'csrf' => bin2hex(random_bytes(32))];
        }
        return null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            setcookie(session_name(), '', ['expires' => 1] + array_diff_key(session_get_cookie_params(), ['lifetime' => 0]));
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public function checkCsrf(mixed $token): bool
    {
        return is_string($token) && $this->csrfToken() !== '' && hash_equals($this->csrfToken(), $token);
    }
}
