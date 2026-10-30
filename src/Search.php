<?php
declare(strict_types=1);

// Video search through public Invidious instances (no YouTube API key). The server searches on
// the listener's behalf, trying each configured instance in turn. Results are cached for an hour
// in 'search_cache'; searches per hashed IP are limited in 'search_rate'.
final class Search
{
    private const CACHE_TTL = 3600;
    private const CACHE_MAX = 200;
    private const PER_MINUTE = 10;
    private const RESULTS = 12;

    /** @var callable(string): array{int, string} */
    private $fetch;
    /** @var callable(): int */
    private $clock;

    // $fetch(url) returns [httpStatus, body]; injectable for tests.
    public function __construct(private Store $store, private array $instances, ?callable $fetch = null, ?callable $clock = null)
    {
        $this->fetch = $fetch ?? self::curlFetch(...);
        $this->clock = $clock ?? time(...);
    }

    // ['results' => [{id, title, author, length, thumbnail}]] or ['error' => listener-facing message].
    public function search(string $query, string $ip): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 2) {
            return ['error' => 'Type at least 2 characters.'];
        }
        $query = mb_substr($query, 0, 100);
        $key = mb_strtolower($query);
        $now = ($this->clock)();

        $limited = $this->store->update('search_rate', function (array &$rate) use ($ip, $now) {
            foreach ($rate as $k => $times) {
                $rate[$k] = array_values(array_filter($times, fn($t) => $t > $now - 60));
                if (!$rate[$k]) unset($rate[$k]);
            }
            if (count($rate[$ip] ?? []) >= self::PER_MINUTE) return true;
            $rate[$ip][] = $now;
            return false;
        });
        if ($limited) {
            return ['error' => 'Too many searches. Wait a minute and try again.'];
        }

        $cached = $this->store->read('search_cache')[$key] ?? null;
        if ($cached && $cached['at'] > $now - self::CACHE_TTL) {
            return ['results' => $cached['results']];
        }

        foreach ($this->instances as $base) {
            $results = $this->fromInstance(rtrim((string) $base, '/'), $query);
            if ($results === null) continue;
            $this->store->update('search_cache', function (array &$cache) use ($key, $results, $now) {
                $cache = array_filter($cache, fn($e) => $e['at'] > $now - self::CACHE_TTL);
                $cache[$key] = ['at' => $now, 'results' => $results];
                if (count($cache) > self::CACHE_MAX) {
                    uasort($cache, fn($a, $b) => $b['at'] <=> $a['at']);
                    $cache = array_slice($cache, 0, self::CACHE_MAX, true);
                }
            });
            return ['results' => $results];
        }
        return ['error' => "Search isn't working right now. Paste a YouTube link instead."];
    }

    // Parsed results, or null if this instance failed (so the next one is tried).
    private function fromInstance(string $base, string $query): ?array
    {
        [$status, $body] = ($this->fetch)($base . '/api/v1/search?type=video&q=' . rawurlencode($query));
        $items = $status === 200 ? json_decode($body, true) : null;
        if (!is_array($items) || !array_is_list($items)) return null;
        $results = [];
        foreach ($items as $item) {
            $id = $item['videoId'] ?? null;
            if (($item['type'] ?? '') !== 'video' || !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)
                || !empty($item['liveNow']) || !empty($item['isUpcoming'])) {
                continue;
            }
            $results[] = [
                'id' => $id,
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 200),
                'author' => mb_substr((string) ($item['author'] ?? ''), 0, 100),
                'length' => (int) ($item['lengthSeconds'] ?? 0),
                'thumbnail' => "https://i.ytimg.com/vi/$id/mqdefault.jpg",
            ];
            if (count($results) === self::RESULTS) break;
        }
        return $results;
    }

    private static function curlFetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'brainz-tv search']);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, (string) $body];
    }
}
