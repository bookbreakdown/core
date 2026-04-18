<?php

namespace TurnkeyAgentic\Core\Services;

interface StorageDriverInterface
{
    /**
     * Get the absolute path (or URI) for a relative path.
     * For local: filesystem path. For SSH: user@host:path. For Drive: folder/file.
     */
    public function path(string $relativePath): string;

    /** Check if a file exists at the relative path. */
    public function exists(string $relativePath): bool;

    /** Write string contents to relative path. Creates directories as needed. */
    public function put(string $relativePath, string $contents): bool;

    /** Move a local file into storage at relative path. Source file may be removed. */
    public function moveIn(string $sourcePath, string $relativePath): bool;

    /**
     * Move a local file into storage and return a storage identifier.
     * For local/SSH: returns the relative path. For Drive: returns the file ID.
     */
    public function moveInAndGetId(string $sourcePath, string $relativePath): ?string;

    /** Read file contents from relative path. Returns false on failure. */
    public function get(string $relativePath): string|false;

    /**
     * Read file contents by storage identifier (from moveInAndGetId).
     * For local/SSH this is the same as get(). For Drive: downloads by file ID.
     */
    public function getById(string $storageId): string|false;

    /** Get file size in bytes. Returns false on failure. */
    public function size(string $relativePath): int|false;

    /** Delete a file at relative path. Returns true if gone (including already absent). */
    public function delete(string $relativePath): bool;

    /** Ensure a directory exists at relative path. Returns the full path. */
    public function ensureDir(string $relativeDir): string;

    /** Get the storage root (base path, folder ID, etc.). */
    public function root(): string;
}
