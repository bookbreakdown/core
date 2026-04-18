<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveService
{
    private Client $client;
    private Drive $drive;
    private string $credentialsPath;
    private ?string $sharedDriveId;
    private ?string $rootFolderId;

    /** Separate OAuth-authenticated Drive instance for writes (consumer Gmail hybrid). */
    private ?Drive $writeDrive = null;

    public function __construct(
        ?string $credentialsPath = null,
        ?string $sharedDriveId = null,
        ?string $rootFolderId = null
    ) {
        $this->credentialsPath = $credentialsPath
            ?? env('GOOGLE_DRIVE_CREDENTIALS_PATH')
            ?? env('GOOGLE_APPLICATION_CREDENTIALS')
            ?? '';

        if ($this->credentialsPath === '') {
            throw new \RuntimeException('Missing GOOGLE_DRIVE_CREDENTIALS_PATH or GOOGLE_APPLICATION_CREDENTIALS');
        }

        if (!is_file($this->credentialsPath)) {
            throw new \RuntimeException("Google Drive credentials file not found: {$this->credentialsPath}");
        }

        if (!is_readable($this->credentialsPath)) {
            throw new \RuntimeException("Google Drive credentials file is not readable: {$this->credentialsPath}");
        }

        $this->sharedDriveId = $sharedDriveId ?? env('GOOGLE_DRIVE_SHARED_DRIVE_ID') ?: null;
        $this->rootFolderId  = $rootFolderId ?? env('GOOGLE_DRIVE_ROOT_FOLDER_ID') ?: null;

        $this->client = new Client();
        $this->client->setApplicationName('Book Breakdown Google Drive');
        $this->client->setScopes([Drive::DRIVE]);

        $this->bootAuth();

        $this->drive = new Drive($this->client);

        // Hybrid mode: if the primary credential is a service account and an
        // OAuth write token exists, boot a separate client for write operations.
        // Consumer Gmail SAs have zero storage quota and cannot create files.
        $this->bootWriteClient();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    /**
     * Detect credential type and initialise the Google Client accordingly.
     *
     * Service account JSON  → setAuthConfig() (server-to-server)
     * OAuth token JSON       → setAuthConfig() with client credentials + setAccessToken() + auto-refresh
     */
    private function bootAuth(): void
    {
        $contents = file_get_contents($this->credentialsPath);

        if ($contents === false) {
            throw new \RuntimeException("Cannot read credentials file: {$this->credentialsPath}");
        }

        $json = json_decode($contents, true);

        if (!is_array($json)) {
            throw new \RuntimeException("Cannot parse credentials file: {$this->credentialsPath}");
        }

        if (isset($json['type']) && $json['type'] === 'service_account') {
            // Service account — standard path
            $this->client->setAuthConfig($this->credentialsPath);
            return;
        }

        // OAuth token file (has refresh_token, no type=service_account)
        if (!isset($json['refresh_token'])) {
            throw new \RuntimeException("Credentials file is neither a service account nor an OAuth token: {$this->credentialsPath}");
        }

        $oauthClientPath = env('GOOGLE_OAUTH_CLIENT_PATH', '/home/ladieu/.config/google/oauth_client.json');

        if (!is_file($oauthClientPath)) {
            throw new \RuntimeException("OAuth client credentials not found: {$oauthClientPath}");
        }

        $oauthContents = file_get_contents($oauthClientPath);

        if ($oauthContents === false) {
            throw new \RuntimeException("Cannot read OAuth client credentials: {$oauthClientPath}");
        }

        $clientCreds = json_decode($oauthContents, true);
        $installed   = $clientCreds['installed'] ?? $clientCreds['web'] ?? null;

        if ($installed === null) {
            throw new \RuntimeException("Cannot parse OAuth client credentials: {$oauthClientPath}");
        }

        $this->client->setClientId($installed['client_id']);
        $this->client->setClientSecret($installed['client_secret']);
        $this->client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');

        // Set the stored token — the client will auto-refresh if expired
        $this->client->setAccessToken($json);

        if ($this->client->isAccessTokenExpired()) {
            $refreshed = $this->client->fetchAccessTokenWithRefreshToken($json['refresh_token']);

            if (isset($refreshed['error'])) {
                throw new \RuntimeException("OAuth token refresh failed: " . ($refreshed['error_description'] ?? $refreshed['error']));
            }

            // Persist the refreshed token back to disk
            $merged = array_merge($json, $refreshed);
            file_put_contents($this->credentialsPath, json_encode($merged, JSON_PRETTY_PRINT));
        }
    }

    /**
     * If primary credentials are a service account, look for an OAuth write
     * token at GOOGLE_DRIVE_WRITE_TOKEN_PATH. Service accounts on consumer
     * Gmail have zero storage quota — writes must go through a user token.
     */
    private function bootWriteClient(): void
    {
        // Only needed when primary auth is a service account
        $json = json_decode(file_get_contents($this->credentialsPath) ?: '', true);
        if (!is_array($json) || ($json['type'] ?? '') !== 'service_account') {
            return; // Primary is already OAuth — writes go through $this->drive
        }

        $writeTokenPath = env('GOOGLE_DRIVE_WRITE_TOKEN_PATH', '');
        if ($writeTokenPath === '' || !is_file($writeTokenPath)) {
            return; // No write token configured — writes will fail with quota error
        }

        $tokenJson = json_decode(file_get_contents($writeTokenPath) ?: '', true);
        if (!is_array($tokenJson) || !isset($tokenJson['refresh_token'])) {
            return;
        }

        $oauthClientPath = env('GOOGLE_OAUTH_CLIENT_PATH', '/home/ladieu/.config/google/oauth_client.json');
        if (!is_file($oauthClientPath)) {
            return;
        }

        $clientCreds = json_decode(file_get_contents($oauthClientPath) ?: '', true);
        $installed   = $clientCreds['installed'] ?? $clientCreds['web'] ?? null;
        if ($installed === null) {
            return;
        }

        $writeClient = new Client();
        $writeClient->setApplicationName('Book Breakdown Google Drive (write)');
        $writeClient->setScopes([Drive::DRIVE]);
        $writeClient->setClientId($installed['client_id']);
        $writeClient->setClientSecret($installed['client_secret']);
        $writeClient->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $writeClient->setAccessToken($tokenJson);

        if ($writeClient->isAccessTokenExpired()) {
            $refreshed = $writeClient->fetchAccessTokenWithRefreshToken($tokenJson['refresh_token']);

            if (isset($refreshed['error'])) {
                return; // Token expired beyond refresh — fall back to SA (will fail on write)
            }

            $merged = array_merge($tokenJson, $refreshed);
            file_put_contents($writeTokenPath, json_encode($merged, JSON_PRETTY_PRINT));
            $writeClient->setAccessToken($merged);
        }

        $this->writeDrive = new Drive($writeClient);
    }

    /**
     * Get the Drive instance for write operations.
     * Returns the OAuth-backed instance if available, otherwise the primary.
     */
    private function getWriteDrive(): Drive
    {
        return $this->writeDrive ?? $this->drive;
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    public function getCredentialsPath(): string
    {
        return $this->credentialsPath;
    }

    public function getServiceAccountEmail(): ?string
    {
        $contents = file_get_contents($this->credentialsPath);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Service account
        if (isset($decoded['client_email'])) {
            return $decoded['client_email'];
        }

        // OAuth — return a hint
        return '[OAuth user token]';
    }

    public function getSharedDriveId(): ?string
    {
        return $this->sharedDriveId;
    }

    public function getRootFolderId(): ?string
    {
        return $this->rootFolderId;
    }

    public function getDrive(): Drive
    {
        return $this->drive;
    }

    // ── Read operations ───────────────────────────────────────────────────────

    public function listFiles(?string $folderId = null, int $pageSize = 25): array
    {
        $targetFolderId = $folderId ?? $this->rootFolderId;
        $params = [
            'pageSize' => $pageSize,
            'fields'   => 'files(id, name, mimeType, parents, webViewLink, size, createdTime)',
            'orderBy'  => 'folder,name',
        ];

        if ($targetFolderId !== null) {
            $params['q'] = sprintf("'%s' in parents and trashed = false", addslashes($targetFolderId));
        } else {
            $params['q'] = 'trashed = false';
        }

        $params = $this->applyDriveOptions($params);

        $result = $this->drive->files->listFiles($params);
        return $result->getFiles();
    }

    public function searchByName(string $name, ?string $folderId = null, ?string $mimeType = null): array
    {
        $q = sprintf("name = '%s' and trashed = false", addslashes($name));

        if ($folderId !== null) {
            $q .= sprintf(" and '%s' in parents", addslashes($folderId));
        }

        if ($mimeType !== null) {
            $q .= sprintf(" and mimeType = '%s'", addslashes($mimeType));
        }

        $params = $this->applyDriveOptions([
            'pageSize' => 5,
            'fields'   => 'files(id, name, mimeType, parents)',
            'q'        => $q,
        ]);

        $result = $this->drive->files->listFiles($params);
        return $result->getFiles();
    }

    public function getFile(string $fileId): DriveFile
    {
        $params = $this->applyDriveOptions([
            'fields' => 'id, name, mimeType, parents, webViewLink, webContentLink, size, createdTime',
        ], false, false);

        return $this->drive->files->get($fileId, $params);
    }

    public function downloadFile(string $fileId): string
    {
        $params   = $this->applyDriveOptions(['alt' => 'media'], false, false);
        $response = $this->drive->files->get($fileId, $params);

        return (string) $response->getBody()->getContents();
    }

    // ── Write operations ──────────────────────────────────────────────────────

    public function createFolder(string $name, ?string $parentId = null): DriveFile
    {
        $metadata = new DriveFile([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);

        $resolvedParentId = $parentId ?? $this->rootFolderId;
        if ($resolvedParentId !== null) {
            $metadata->setParents([$resolvedParentId]);
        }

        $params = $this->applyDriveOptions([
            'fields' => 'id, name, mimeType, parents, webViewLink',
        ], true);

        return $this->getWriteDrive()->files->create($metadata, $params);
    }

    public function uploadText(string $name, string $content, ?string $parentId = null, string $mimeType = 'text/plain'): DriveFile
    {
        $metadata = new DriveFile(['name' => $name]);

        $resolvedParentId = $parentId ?? $this->rootFolderId;
        if ($resolvedParentId !== null) {
            $metadata->setParents([$resolvedParentId]);
        }

        $params = $this->applyDriveOptions([
            'data'       => $content,
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id, name, mimeType, parents, webViewLink, webContentLink',
        ], true);

        return $this->getWriteDrive()->files->create($metadata, $params);
    }

    public function uploadFile(string $name, string $localPath, ?string $parentId = null, ?string $mimeType = null): DriveFile
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException("Local file not found: {$localPath}");
        }

        $metadata = new DriveFile(['name' => $name]);

        $resolvedParentId = $parentId ?? $this->rootFolderId;
        if ($resolvedParentId !== null) {
            $metadata->setParents([$resolvedParentId]);
        }

        $params = $this->applyDriveOptions([
            'data'       => (string) file_get_contents($localPath),
            'mimeType'   => $mimeType ?? mime_content_type($localPath) ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields'     => 'id, name, mimeType, parents, webViewLink, webContentLink',
        ], true);

        return $this->getWriteDrive()->files->create($metadata, $params);
    }

    public function deleteFile(string $fileId): void
    {
        $params = $this->applyDriveOptions([], true);
        $this->getWriteDrive()->files->delete($fileId, $params);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function applyDriveOptions(array $params, bool $isWrite = false, bool $isList = true): array
    {
        $params['supportsAllDrives'] = true;

        if (!$isWrite && $isList) {
            $params['includeItemsFromAllDrives'] = true;

            if ($this->sharedDriveId !== null) {
                $params['driveId'] = $this->sharedDriveId;
                $params['corpora'] = 'drive';
            }
        }

        return $params;
    }
}
