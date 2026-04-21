<?php

namespace TurnkeyAgentic\Core\Services;

class StorageService implements StorageDriverInterface
{
    protected string $root;

    public function __construct()
    {
        $root = env('STORAGE_ROOT');

        if (empty($root)) {
            $root = WRITEPATH . 'production_storage';
        }

        $this->root = rtrim($root, '/');
    }

    public function path(string $relativePath): string
    {
        return $this->root . '/' . ltrim($relativePath, '/');
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->path($relativePath));
    }

    public function put(string $relativePath, string $contents): bool
    {
        $full = $this->path($relativePath);
        $dir  = dirname($full);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($full, $contents) !== false;
    }

    public function moveIn(string $sourcePath, string $relativePath): bool
    {
        $dest = $this->path($relativePath);
        $dir  = dirname($dest);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return rename($sourcePath, $dest);
    }

    public function get(string $relativePath): string|false
    {
        $full = $this->path($relativePath);
        return is_file($full) ? file_get_contents($full) : false;
    }

    public function size(string $relativePath): int|false
    {
        $full = $this->path($relativePath);
        return is_file($full) ? filesize($full) : false;
    }

    public function delete(string $relativePath): bool
    {
        $full = $this->path($relativePath);
        if (file_exists($full)) {
            return unlink($full);
        }
        return true;
    }

    public function ensureDir(string $relativeDir): string
    {
        $full = $this->path($relativeDir);
        if (!is_dir($full)) {
            mkdir($full, 0755, true);
        }
        return $full;
    }

    public function moveInAndGetId(string $sourcePath, string $relativePath): ?string
    {
        return $this->moveIn($sourcePath, $relativePath) ? $relativePath : null;
    }

    public function getById(string $storageId): string|false
    {
        return $this->get($storageId);
    }

    public function root(): string
    {
        return $this->root;
    }
}
