<?php
declare(strict_types=1);

// Turns listener input into a YouTube video ID and checks it via oEmbed (no API key).
final class YouTube
{
    private const ID = '[A-Za-z0-9_-]{11}';
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** @var callable(string): array{int, string} */
    private $fetch;

    // $fetch(url) returns [httpStatus, body]; injectable for tests.
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? self::curlFetch(...);
    }

    // Bare ID or any common URL form → 11-char ID, or null if it isn't one.
    public static function parseId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^' . self::ID . '$/', $input)) {
            return $input;
        }
        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }
        $url = parse_url($input);
        $host = strtolower($url['host'] ?? '');
        $path = $url['path'] ?? '';
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $candidate = explode('/', ltrim($path, '/'))[0];
        } elseif (in_array($host, self::HOSTS, true)) {
            parse_str($url['query'] ?? '', $query);
            $candidate = $path === '/watch'
                ? ($query['v'] ?? '')
                : (preg_match('#^/(?:shorts|embed|live|v)/([^/]+)#', $path, $m) ? $m[1] : '');
        } else {
            return null;
        }
        return is_string($candidate) && preg_match('/^' . self::ID . '$/', $candidate) ? $candidate : null;
    }

    // ['ok' => true, 'id', 'title', 'author', 'thumbnail'] or ['ok' => false, 'error' => listener-facing message].
    public function check(string $input): array
    {
        $id = self::parseId($input);
        if ($id === null) {
            return ['ok' => false, 'error' => "That doesn't look like a YouTube link or video ID."];
        }
        $watch = "https://www.youtube.com/watch?v=$id";
        [$status, $body] = ($this->fetch)('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($watch));
        $data = $status === 200 ? json_decode($body, true) : null;
        if (is_array($data) && isset($data['title'])) {
            return [
                'ok' => true,
                'id' => $id,
                'title' => (string) $data['title'],
                'author' => (string) ($data['author_name'] ?? ''),
                'thumbnail' => "https://i.ytimg.com/vi/$id/mqdefault.jpg",
            ];
        }
        return ['ok' => false, 'error' => match (true) {
            $status === 401, $status === 403 => "That video can't be embedded, so it can't be shown on stream.",
            $status === 400, $status === 404 => "That video doesn't exist or is private.",
            default => "Couldn't reach YouTube to check that video. Try again in a moment.",
        }];
    }

    private static function curlFetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, (string) $body];
    }
}
