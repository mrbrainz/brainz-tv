<?php
declare(strict_types=1);

// Queue state in the 'state' document:
//   now:   null | {item..., started_at}
//   queue: [item...] where item = {uid, id, title, author, thumbnail, name, by, added_at}
// `by` is the submitter's anonymous listener cookie, used to show "your" entries.
final class Queue
{
    public const EMPTY = ['now' => null, 'queue' => []];

    public function __construct(private Store $store, private array $config) {}

    public function snapshot(): array
    {
        return $this->store->read('state', self::EMPTY);
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
