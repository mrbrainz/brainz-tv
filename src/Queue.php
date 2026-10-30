<?php
declare(strict_types=1);

// Queue state in the 'state' document:
//   now:       null | {item..., started_at}
//   queue:     [item...] where item = {uid, id, title, author, thumbnail, name, by, added_at}
//   pending:   [item...] awaiting admin approval (approval mode only)
//   paused_at: null | time rotation was paused (the current video's clock stops)
// `by` is the submitter's anonymous listener cookie, used to show "your" entries.
// Rotation is lazy (no cron): every snapshot() advances the queue if the current video's
// time is up. Finished videos go to the 'history' document (newest first, capped).
// Videos the overlay's player refused are listed by video ID in the 'unplayable' document.
// The admin blocklist ('blocklist': {videos: {id: title}, channels: {lowercase name: name}}) and
// listener limits (per_user_limit, submit_cooldown_seconds, replay_cooldown_minutes) apply to
// listener adds only. Listeners are matched by cookie (`by`) or hashed IP (`ip`); last submit
// times per IP are kept in 'submits'.
// Config keys used: rotation_seconds, max_queue_length, submissions_open, approval, plus the limits above.
final class Queue
{
    public const EMPTY = ['now' => null, 'queue' => [], 'pending' => [], 'paused_at' => null];
    private const HISTORY_LIMIT = 100;

    /** @var callable(): int */
    private $clock;
    private array $ended = [];

    public function __construct(private Store $store, private array $config, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function snapshot(): array
    {
        $state = $this->store->read('state', self::EMPTY) + self::EMPTY;
        if (!$this->due($state)) {
            return $state;
        }
        // Re-check under the exclusive lock: a concurrent poll may already have advanced.
        return $this->change(function (array &$state) {
            if ($this->due($state)) $this->advance($state);
        })[1];
    }

    // Time left on the current video in seconds, or null when nothing is playing. Frozen while paused.
    public function remaining(array $state): ?int
    {
        return $state['now'] === null ? null
            : max(0, $state['now']['started_at'] + $this->config['rotation_seconds'] - ($state['paused_at'] ?? ($this->clock)()));
    }

    // Adds a checked video (from YouTube::check). Returns null or a listener-facing error.
    // Admin adds skip the open/full/approval checks.
    public function add(array $video, string $name, string $by, bool $admin = false, string $ip = ''): ?string
    {
        if (isset($this->store->read('unplayable')[$video['id']])) {
            return "YouTube won't let that video play on stream. Try a different upload of it.";
        }
        $now = ($this->clock)();
        if (!$admin) {
            $blocked = $this->store->read('blocklist', ['videos' => [], 'channels' => []]);
            if (isset($blocked['videos'][$video['id']]) || isset($blocked['channels'][mb_strtolower($video['author'])])) {
                return "That video can't be requested here.";
            }
            $since = $now - $this->config['replay_cooldown_minutes'] * 60;
            foreach ($this->store->read('history') as $played) {
                if ($played['ended_at'] < $since) break; // newest first
                if ($played['id'] === $video['id']) return 'That video played recently. Pick something else for now.';
            }
            $wait = $this->store->update('submits', function (array &$submits) use ($ip, $now) {
                $submits = array_filter($submits, fn($t) => $t > $now - 3600);
                return $ip === '' ? 0 : max(0, ($submits[$ip] ?? 0) + $this->config['submit_cooldown_seconds'] - $now);
            });
            if ($wait > 0) return "You can add another video in $wait seconds.";
        }
        $error = $this->change(function (array &$state) use ($video, $name, $by, $admin, $ip, $now) {
            if (!$admin && !$this->config['submissions_open']) {
                return 'Requests are closed right now.';
            }
            if (!$admin && count($state['queue']) + count($state['pending']) >= $this->config['max_queue_length']) {
                return 'The queue is full right now. Try again after a few videos have played.';
            }
            $waiting = [...$state['queue'], ...$state['pending']];
            if (!$admin && in_array($video['id'], array_column([...$waiting, ...array_filter([$state['now']])], 'id'), true)) {
                return "That video is already in the queue.";
            }
            $mine = array_filter($waiting, fn($i) => $i['by'] === $by || ($ip !== '' && ($i['ip'] ?? '') === $ip));
            if (!$admin && count($mine) >= $this->config['per_user_limit']) {
                return 'You already have ' . count($mine) . ' video(s) waiting. Add more once one has played.';
            }
            $item = [
                'uid' => bin2hex(random_bytes(6)),
                'id' => $video['id'],
                'title' => $video['title'],
                'author' => $video['author'],
                'thumbnail' => $video['thumbnail'],
                'name' => $name,
                'by' => $by,
                'ip' => $ip,
                'added_at' => $now,
            ];
            $state[!$admin && $this->config['approval'] ? 'pending' : 'queue'][] = $item;
            return null;
        })[0];
        if ($error === null && !$admin && $ip !== '') {
            $this->store->update('submits', function (array &$submits) use ($ip, $now) { $submits[$ip] = $now; });
        }
        return $error;
    }

    // Admin blocklist. $kind is 'video' (key = video ID), 'channel' (key = channel name) or
    // 'unplayable' (unblock only: lets a flagged video be requested again).
    public function block(string $kind, string $key, string $label = ''): ?string
    {
        if ($key === '' || !in_array($kind, ['video', 'channel'], true)) return 'Nothing to block.';
        $this->store->update('blocklist', function (array &$list) use ($kind, $key, $label) {
            if ($kind === 'video') $list['videos'][$key] = $label;
            else $list['channels'][mb_strtolower($key)] = $key;
        }, ['videos' => [], 'channels' => []]);
        return null;
    }

    public function unblock(string $kind, string $key): ?string
    {
        if ($kind === 'unplayable') {
            $this->store->update('unplayable', function (array &$list) use ($key) { unset($list[$key]); });
            return null;
        }
        if (!in_array($kind, ['video', 'channel'], true)) return 'Unknown list.';
        $this->store->update('blocklist', function (array &$list) use ($kind, $key) {
            unset($list[$kind === 'video' ? 'videos' : 'channels'][$kind === 'video' ? $key : mb_strtolower($key)]);
        }, ['videos' => [], 'channels' => []]);
        return null;
    }

    // Admin actions. Each returns null or an error message.
    public function skip(): ?string
    {
        return $this->change(function (array &$state) {
            if ($state['now'] === null && $state['queue'] === []) return 'Nothing to skip.';
            $this->advance($state);
            return null;
        })[0];
    }

    // The overlay's player refused the current video: flag its ID and move on. Ignored unless
    // $uid is still the one playing, so a late or repeated report can't skip the next video.
    public function unplayable(string $uid): ?string
    {
        [$id, $state] = $this->change(function (array &$state) use ($uid) {
            if (($state['now']['uid'] ?? null) !== $uid) return null;
            $id = $state['now']['id'];
            $this->advance($state);
            return $id;
        });
        if ($id === null) return 'Not the current video.';
        $this->store->update('unplayable', function (array &$list) use ($id) { $list[$id] = ($this->clock)(); });
        return null;
    }

    public function pause(bool $paused): ?string
    {
        return $this->change(function (array &$state) use ($paused) {
            $now = ($this->clock)();
            if ($paused && $state['paused_at'] === null) {
                $state['paused_at'] = $now;
            } elseif (!$paused && $state['paused_at'] !== null) {
                // Shift the start time so the video resumes with the time it had left.
                if ($state['now'] !== null) $state['now']['started_at'] += $now - $state['paused_at'];
                $state['paused_at'] = null;
            }
            return null;
        })[0];
    }

    public function remove(string $uid): ?string
    {
        return $this->change(function (array &$state) use ($uid) {
            foreach (['queue', 'pending'] as $list) {
                $before = count($state[$list]);
                $state[$list] = array_values(array_filter($state[$list], fn($i) => $i['uid'] !== $uid));
                if (count($state[$list]) < $before) return null;
            }
            return 'That video is no longer queued.';
        })[0];
    }

    // Moves a queued item by $by places (negative = towards the front), clamped to the ends.
    public function move(string $uid, int $by): ?string
    {
        return $this->change(function (array &$state) use ($uid, $by) {
            $from = array_search($uid, array_column($state['queue'], 'uid'), true);
            if ($from === false) return 'That video is no longer queued.';
            $to = max(0, min(count($state['queue']) - 1, $from + $by));
            $item = array_splice($state['queue'], $from, 1);
            array_splice($state['queue'], $to, 0, $item);
            return null;
        })[0];
    }

    public function approve(string $uid): ?string
    {
        return $this->change(function (array &$state) use ($uid) {
            $at = array_search($uid, array_column($state['pending'], 'uid'), true);
            if ($at === false) return 'That request is no longer pending.';
            $state['queue'][] = array_splice($state['pending'], $at, 1)[0];
            return null;
        })[0];
    }

    public function clear(): ?string
    {
        return $this->change(function (array &$state) {
            $state['queue'] = [];
            return null;
        })[0];
    }

    // Public view of the state: drops `by` and other listeners' pending requests, flags the viewer's own entries.
    public static function forListener(array $state, string $by): array
    {
        $strip = function (?array $item) use ($by): ?array {
            if ($item === null) return null;
            $item['mine'] = $item['by'] === $by;
            unset($item['by'], $item['ip']);
            return $item;
        };
        return [
            'now' => $strip($state['now']),
            'queue' => array_map($strip, $state['queue']),
            'my_pending' => count(array_filter($state['pending'], fn($i) => $i['by'] === $by)),
            'paused' => $state['paused_at'] !== null,
        ];
    }

    private function due(array $state): bool
    {
        if ($state['paused_at'] !== null) return false;
        return $state['now'] === null ? $state['queue'] !== [] : $this->remaining($state) === 0;
    }

    // Ends the current video (if any) and starts the next. Paused stays paused, at the new video's start.
    private function advance(array &$state): void
    {
        $now = ($this->clock)();
        if ($state['now'] !== null) $this->ended[] = $state['now'];
        $next = array_shift($state['queue']);
        $state['now'] = $next === null ? null : $next + ['started_at' => $now];
        if ($state['paused_at'] !== null) $state['paused_at'] = $now;
    }

    // Runs $fn on the state under the exclusive lock. Returns [$fn's result, new state].
    private function change(callable $fn): array
    {
        $this->ended = [];
        [$result, $state] = $this->store->update('state', function (array &$state) use ($fn) {
            $state += self::EMPTY;
            return [$fn($state), $state];
        }, self::EMPTY);
        if ($this->ended) {
            $ended = $this->ended;
            $this->store->update('history', function (array &$history) use ($ended) {
                foreach ($ended as $item) array_unshift($history, $item + ['ended_at' => ($this->clock)()]);
                $history = array_slice($history, 0, self::HISTORY_LIMIT);
            });
        }
        return [$result, $state];
    }
}
