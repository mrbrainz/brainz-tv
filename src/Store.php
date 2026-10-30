<?php
declare(strict_types=1);

// JSON file storage, no database. Each document is <dir>/<name>.json, guarded by
// <dir>/<name>.lock. Writes go to a temp file and are renamed into place, so readers
// never see half-written JSON even if PHP dies mid-write.
final class Store
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data dir $dir");
        }
        // Belt and braces in case the data dir ever ends up under the docroot.
        $htaccess = "$dir/.htaccess";
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }

    // Current contents, or $default if the document doesn't exist yet.
    public function read(string $name, array $default = []): array
    {
        return $this->withLock($name, LOCK_SH, fn() => $this->load($name, $default));
    }

    // Runs $change on the document under an exclusive lock and saves the result.
    // $change gets the data by reference; its return value is passed back.
    public function update(string $name, callable $change, array $default = []): mixed
    {
        return $this->withLock($name, LOCK_EX, function () use ($name, $change, $default) {
            $data = $this->load($name, $default);
            $result = $change($data);
            $this->save($name, $data);
            return $result;
        });
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("Bad document name $name");
        }
        return "$this->dir/$name";
    }

    private function withLock(string $name, int $mode, callable $fn): mixed
    {
        $lock = fopen($this->path($name) . '.lock', 'c');
        if ($lock === false || !flock($lock, $mode)) {
            throw new RuntimeException("Cannot lock $name");
        }
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(string $name, array $default): array
    {
        $file = $this->path($name) . '.json';
        if (!is_file($file)) {
            return $default;
        }
        // Throw rather than fall back to $default, so a damaged file is never silently overwritten.
        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    private function save(string $name, array $data): void
    {
        $file = $this->path($name) . '.json';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write $name");
        }
    }
}
