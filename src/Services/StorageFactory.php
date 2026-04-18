<?php

namespace TurnkeyAgentic\Core\Services;

/**
 * Factory for storage drivers. Reads STORAGE_DRIVER from env.
 *
 * Env:
 *   STORAGE_DRIVER=local   → StorageService (filesystem, default)
 *   STORAGE_DRIVER=ssh     → SshStorageService (remote via SSH/SCP)
 *   STORAGE_DRIVER=drive   → DriveStorageService (Google Drive)
 */
class StorageFactory
{
    private static ?StorageDriverInterface $instance = null;

    public static function make(?string $driver = null): StorageDriverInterface
    {
        $driver = $driver ?? env('STORAGE_DRIVER', 'local');

        return match ($driver) {
            'local'  => new StorageService(),
            'ssh'    => new SshStorageService(),
            'drive'  => new DriveStorageService(),
            default  => throw new \InvalidArgumentException("Unknown storage driver: {$driver}"),
        };
    }

    /**
     * Get or create the shared storage instance (singleton per request).
     */
    public static function default(): StorageDriverInterface
    {
        if (self::$instance === null) {
            self::$instance = self::make();
        }
        return self::$instance;
    }

    /**
     * Reset the shared instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
