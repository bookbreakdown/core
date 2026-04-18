<?php

namespace TurnkeyAgentic\Core\Services;

/**
 * SSH/SCP storage driver for remote file operations.
 *
 * Env config:
 *   SSH_STORAGE_HOST     — remote hostname or IP
 *   SSH_STORAGE_USER     — SSH username (default: current user)
 *   SSH_STORAGE_PORT     — SSH port (default: 22)
 *   SSH_STORAGE_KEY      — path to private key (default: ~/.ssh/id_rsa)
 *   SSH_STORAGE_ROOT     — absolute path on remote server (e.g. /var/www/app/writable/production_storage)
 */
class SshStorageService implements StorageDriverInterface
{
    private string $host;
    private string $user;
    private int    $port;
    private string $keyPath;
    private string $root;

    public function __construct()
    {
        $this->host    = env('SSH_STORAGE_HOST') ?: throw new \RuntimeException('SSH_STORAGE_HOST required');
        $this->user    = env('SSH_STORAGE_USER') ?: get_current_user();
        $this->port    = (int) (env('SSH_STORAGE_PORT') ?: 22);
        $this->keyPath = env('SSH_STORAGE_KEY')  ?: ($_SERVER['HOME'] ?? '/root') . '/.ssh/id_rsa';
        $this->root    = rtrim(env('SSH_STORAGE_ROOT') ?: '/var/www/app/writable/production_storage', '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relativePath): string
    {
        return $this->root . '/' . ltrim($relativePath, '/');
    }

    public function exists(string $relativePath): bool
    {
        $remote = $this->path($relativePath);
        $exit   = $this->ssh("test -f " . escapeshellarg($remote));
        return $exit === 0;
    }

    public function put(string $relativePath, string $contents): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_put_');
        file_put_contents($tmp, $contents);
        $result = $this->moveIn($tmp, $relativePath);
        @unlink($tmp); // clean up if moveIn copied instead of moved
        return $result;
    }

    public function moveIn(string $sourcePath, string $relativePath): bool
    {
        $remote    = $this->path($relativePath);
        $remoteDir = dirname($remote);

        // Ensure remote directory exists
        $this->ssh("mkdir -p " . escapeshellarg($remoteDir));

        // SCP the file
        $exit = $this->scp($sourcePath, $remote);
        if ($exit === 0) {
            @unlink($sourcePath);
            return true;
        }

        return false;
    }

    public function moveInAndGetId(string $sourcePath, string $relativePath): ?string
    {
        return $this->moveIn($sourcePath, $relativePath) ? $relativePath : null;
    }

    public function get(string $relativePath): string|false
    {
        $remote = $this->path($relativePath);
        $tmp    = tempnam(sys_get_temp_dir(), 'ssh_get_');

        $exit = $this->scpFrom($remote, $tmp);
        if ($exit !== 0) {
            @unlink($tmp);
            return false;
        }

        $contents = file_get_contents($tmp);
        @unlink($tmp);
        return $contents;
    }

    public function getById(string $storageId): string|false
    {
        return $this->get($storageId);
    }

    public function size(string $relativePath): int|false
    {
        $remote = $this->path($relativePath);
        $output = $this->sshOutput("stat -c%s " . escapeshellarg($remote));
        if ($output === null) {
            return false;
        }
        $val = trim($output);
        return ctype_digit($val) ? (int) $val : false;
    }

    public function delete(string $relativePath): bool
    {
        $remote = $this->path($relativePath);
        $this->ssh("rm -f " . escapeshellarg($remote));
        return true;
    }

    public function ensureDir(string $relativeDir): string
    {
        $remote = $this->path($relativeDir);
        $this->ssh("mkdir -p " . escapeshellarg($remote));
        return $remote;
    }

    // ── SSH helpers ──────────────────────────────────────────────────────────

    private function sshBaseArgs(): string
    {
        return sprintf(
            '-o StrictHostKeyChecking=no -o BatchMode=yes -i %s -p %d',
            escapeshellarg($this->keyPath),
            $this->port
        );
    }

    private function ssh(string $remoteCommand): int
    {
        $cmd = sprintf(
            'ssh %s %s@%s %s 2>/dev/null',
            $this->sshBaseArgs(),
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remoteCommand)
        );

        exec($cmd, $output, $exit);
        return $exit;
    }

    private function sshOutput(string $remoteCommand): ?string
    {
        $cmd = sprintf(
            'ssh %s %s@%s %s 2>/dev/null',
            $this->sshBaseArgs(),
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remoteCommand)
        );

        exec($cmd, $output, $exit);
        return $exit === 0 ? implode("\n", $output) : null;
    }

    private function scp(string $localPath, string $remotePath): int
    {
        $cmd = sprintf(
            'scp %s -P %d %s %s@%s:%s 2>/dev/null',
            str_replace('-p', '-P', $this->sshBaseArgs()),  // scp uses -P not -p for port
            $this->port,
            escapeshellarg($localPath),
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remotePath)
        );

        // Fix: sshBaseArgs already has -p, scp needs different format
        $cmd = sprintf(
            'scp -o StrictHostKeyChecking=no -o BatchMode=yes -i %s -P %d %s %s@%s:%s 2>/dev/null',
            escapeshellarg($this->keyPath),
            $this->port,
            escapeshellarg($localPath),
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remotePath)
        );

        exec($cmd, $output, $exit);
        return $exit;
    }

    private function scpFrom(string $remotePath, string $localPath): int
    {
        $cmd = sprintf(
            'scp -o StrictHostKeyChecking=no -o BatchMode=yes -i %s -P %d %s@%s:%s %s 2>/dev/null',
            escapeshellarg($this->keyPath),
            $this->port,
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remotePath),
            escapeshellarg($localPath)
        );

        exec($cmd, $output, $exit);
        return $exit;
    }
}
