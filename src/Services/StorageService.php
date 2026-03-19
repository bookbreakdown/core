<?php

namespace TurnkeyAgentic\Core\Services;

class StorageService
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
        return file_get_contents($this->path($relativePath));
    }

    public function size(string $relativePath): int|false
    {
        return filesize($this->path($relativePath));
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

    public function root(): string
    {
        return $this->root;
    }
}
