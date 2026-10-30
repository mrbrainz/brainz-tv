<?php
declare(strict_types=1);

// Queue state in the 'state' document:
//   now:   null | {item..., started_at}
//   queue: [item...] where item = {uid, id, title, author, thumbnail, name, by, added_at}
// `by` is the submitter's anonymous listener cookie, used to show "your" entries.
// Rotation is lazy (no cron): every snapshot() advances the queue if the current video's
// time is up. Finished videos go to the 'history' document (newest first, capped).
final class Queue
{
    public const EMPTY = ['now' => null, 'queue' => []];
    private const HISTORY_LIMIT = 100;

    /** @var callable(): int */
    private $clock;

    public function __construct(private Store $store, private array $config, ?callable $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function snapshot(): array
    {
        $state = $this->store->read('state', self::EMPTY);
        if (!$this->due($state)) {
            return $state;
        }
        // Re-check under the exclusive lock: a concurrent poll may already have advanced.
        $finished = null;
        $state = $this->store->update('state', function (array &$state) use (&$finished) {
            if ($this->due($state)) {
                $finished = $state['now'];
                $next = array_shift($state['queue']);
                $state['now'] = $next === null ? null : $next + ['started_at' => ($this->clock)()];
            }
            return $state;
        }, self::EMPTY);
        if ($finished !== null) {
            $this->store->update('history', function (array &$history) use ($finished) {
                array_unshift($history, $finished + ['ended_at' => ($this->clock)()]);
                $history = array_slice($history, 0, self::HISTORY_LIMIT);
            });
        }
        return $state;
    }

    // Time left on the current video in seconds, or null when nothing is playing.
    public function remaining(array $state): ?int
    {
        return $state['now'] === null ? null
            : max(0, $state['now']['started_at'] + $this->config['rotation_seconds'] - ($this->clock)());
    }

    private function due(array $state): bool
    {
        return $state['now'] === null ? $state['queue'] !== [] : $this->remaining($state) === 0;
    }

    // Adds a checked video (from YouTube::check). Returns null or a listener-facing error.
    public function add(array $video, string $name, string $by): ?string
    {
        return $this->store->update('state', function (array &$state) use ($video, $name, $by) {
            if (count($state['queue']) >= $this->config['max_queue_length']) {
                return 'The queue is full right now. Try again after a few videos have played.';
            }
            $state['queue'][] = [
                'uid' => bin2hex(random_bytes(6)),
                'id' => $video['id'],
                'title' => $video['title'],
                'author' => $video['author'],
                'thumbnail' => $video['thumbnail'],
                'name' => $name,
                'by' => $by,
                'added_at' => time(),
            ];
            return null;
        }, self::EMPTY);
    }

    // Public view of the state: drops `by`, flags the viewer's own entries.
    public static function forListener(array $state, string $by): array
    {
        $strip = function (?array $item) use ($by): ?array {
            if ($item === null) return null;
            $item['mine'] = $item['by'] === $by;
            unset($item['by']);
            return $item;
        };
        return ['now' => $strip($state['now']), 'queue' => array_map($strip, $state['queue'])];
    }
}
