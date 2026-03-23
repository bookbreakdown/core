<?php

namespace TurnkeyAgentic\Core\Services;

class DriveStorageService
{
    private GoogleDriveService $drive;
    private string $rootFolderId;

    /** @var array<string, string> folder name/key -> Drive folder ID */
    private array $folderCache = [];

    public function __construct(?GoogleDriveService $drive = null)
    {
        $this->drive = $drive ?? new GoogleDriveService();

        $rootId = $this->drive->getRootFolderId();
        if ($rootId === null) {
            throw new \RuntimeException('DriveStorageService requires GOOGLE_DRIVE_ROOT_FOLDER_ID');
        }

        $this->rootFolderId = $rootId;
    }

    public function root(): string
    {
        return $this->rootFolderId;
    }

    /**
     * Upload string content to Drive at relative path.
     * Path format: {folder_name_or_id}/{filename}
     */
    public function put(string $relativePath, string $contents): bool
    {
        [$folderKey, $filename] = $this->parsePath($relativePath);
        $folderId = $this->resolveOrCreateFolder($folderKey);

        // Replace existing file if present
        $existingId = $this->findFileId($filename, $folderId);
        if ($existingId !== null) {
            $this->drive->deleteFile($existingId);
        }

        $mimeType = $this->mimeFromFilename($filename);
        $file = $this->drive->uploadText($filename, $contents, $folderId, $mimeType);

        return $file->getId() !== null;
    }

    /**
     * Download file contents from Drive by relative path.
     */
    public function get(string $relativePath): string|false
    {
        [$folderKey, $filename] = $this->parsePath($relativePath);
        $folderId = $this->resolveFolder($folderKey);

        if ($folderId === null) {
            return false;
        }

        $fileId = $this->findFileId($filename, $folderId);
        if ($fileId === null) {
            return false;
        }

        return $this->drive->downloadFile($fileId);
    }

    /**
     * Check if file exists on Drive at relative path.
     */
    public function exists(string $relativePath): bool
    {
        [$folderKey, $filename] = $this->parsePath($relativePath);
        $folderId = $this->resolveFolder($folderKey);

        if ($folderId === null) {
            return false;
        }

        return $this->findFileId($filename, $folderId) !== null;
    }

    /**
     * Delete file from Drive at relative path.
     */
    public function delete(string $relativePath): bool
    {
        [$folderKey, $filename] = $this->parsePath($relativePath);
        $folderId = $this->resolveFolder($folderKey);

        if ($folderId === null) {
            return true; // already gone
        }

        $fileId = $this->findFileId($filename, $folderId);
        if ($fileId === null) {
            return true; // already gone
        }

        $this->drive->deleteFile($fileId);

        return true;
    }

    /**
     * Upload a local file to Drive at relative path.
     */
    public function moveIn(string $sourcePath, string $relativePath): bool
    {
        return $this->moveInAndGetId($sourcePath, $relativePath) !== null;
    }

    /**
     * Upload a local file to Drive at relative path, returning the Drive file ID.
     */
    public function moveInAndGetId(string $sourcePath, string $relativePath): ?string
    {
        [$folderKey, $filename] = $this->parsePath($relativePath);
        $folderId = $this->resolveOrCreateFolder($folderKey);

        $existingId = $this->findFileId($filename, $folderId);
        if ($existingId !== null) {
            $this->drive->deleteFile($existingId);
        }

        $file = $this->drive->uploadFile($filename, $sourcePath, $folderId);

        $id = $file->getId();
        return $id !== null && $id !== '' ? $id : null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function parsePath(string $relativePath): array
    {
        $parts = explode('/', ltrim($relativePath, '/'), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(
                "Drive path must be '{folder}/{filename}', got: {$relativePath}"
            );
        }

        return $parts;
    }

    /**
     * Resolve a folder name/ID to its Drive folder ID (read-only, no creation).
     * Returns null if the folder does not exist.
     */
    private function resolveFolder(string $nameOrId): ?string
    {
        if (isset($this->folderCache[$nameOrId])) {
            return $this->folderCache[$nameOrId];
        }

        $files = $this->drive->searchByName(
            $nameOrId,
            $this->rootFolderId,
            'application/vnd.google-apps.folder'
        );

        if (empty($files)) {
            return null;
        }

        $id = $files[0]->getId();
        $this->folderCache[$nameOrId] = $id;

        return $id;
    }

    /**
     * Resolve a folder name/ID to its Drive folder ID, creating it if missing.
     */
    private function resolveOrCreateFolder(string $nameOrId): string
    {
        $id = $this->resolveFolder($nameOrId);

        if ($id !== null) {
            return $id;
        }

        $folder = $this->drive->createFolder($nameOrId, $this->rootFolderId);
        $id = $folder->getId();
        $this->folderCache[$nameOrId] = $id;

        return $id;
    }

    /**
     * Find a file by name within a Drive folder. Returns null if not found.
     */
    private function findFileId(string $filename, string $folderId): ?string
    {
        $files = $this->drive->searchByName($filename, $folderId);

        if (empty($files)) {
            return null;
        }

        return $files[0]->getId();
    }

    private function mimeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => 'application/pdf',
            'json' => 'application/json',
            'html' => 'text/html',
            'txt'  => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
