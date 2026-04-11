<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Client;
use Google\Service\Tasks;
use Google\Service\Tasks\Task;
use Google\Service\Tasks\TaskList;

/**
 * GoogleTasksService — Core adapter for Google Tasks API.
 *
 * Mirrors the OAuth boot pattern established in GoogleDriveService.
 * Auth and token failures throw \RuntimeException with descriptive messages
 * so callers can react safely rather than guessing.
 *
 * Default task list ID is resolved on first use and cached for the
 * lifetime of this instance.
 */
class GoogleTasksService
{
    private Client $client;
    private Tasks $tasks;
    private string $tokenPath;
    private ?string $defaultTasklistId = null;

    public function __construct(?string $tokenPath = null)
    {
        $this->tokenPath = $tokenPath
            ?? env('GOOGLE_APPLICATION_CREDENTIALS')
            ?? '';

        if ($this->tokenPath === '') {
            throw new \RuntimeException('GoogleTasksService: GOOGLE_APPLICATION_CREDENTIALS is not set');
        }

        if (!is_file($this->tokenPath)) {
            throw new \RuntimeException("GoogleTasksService: token file not found: {$this->tokenPath}");
        }

        if (!is_readable($this->tokenPath)) {
            throw new \RuntimeException("GoogleTasksService: token file not readable: {$this->tokenPath}");
        }

        $this->client = new Client();
        $this->client->setApplicationName('TurnkeyAgentic Google Tasks');
        $this->client->setScopes([Tasks::TASKS]);

        $this->bootAuth();

        $this->tasks = new Tasks($this->client);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    /**
     * Boot OAuth credentials and refresh the token if expired.
     * Persists the refreshed token back to disk.
     *
     * @throws \RuntimeException on any auth or token failure
     */
    private function bootAuth(): void
    {
        $contents = file_get_contents($this->tokenPath);

        if ($contents === false) {
            throw new \RuntimeException("GoogleTasksService: cannot read token file: {$this->tokenPath}");
        }

        $json = json_decode($contents, true);

        if (!is_array($json)) {
            throw new \RuntimeException("GoogleTasksService: cannot parse token file: {$this->tokenPath}");
        }

        if (isset($json['type']) && $json['type'] === 'service_account') {
            // Service account path — tasks scope must be granted
            $this->client->setAuthConfig($this->tokenPath);
            return;
        }

        if (!isset($json['refresh_token'])) {
            throw new \RuntimeException(
                "GoogleTasksService: token file is neither a service account nor an OAuth token " .
                "(no refresh_token): {$this->tokenPath}"
            );
        }

        $oauthClientPath = env('GOOGLE_OAUTH_CLIENT_PATH', '/home/ladieu/.config/google/oauth_client.json');

        if (!is_file($oauthClientPath)) {
            throw new \RuntimeException("GoogleTasksService: OAuth client file not found: {$oauthClientPath}");
        }

        $oauthContents = file_get_contents($oauthClientPath);

        if ($oauthContents === false) {
            throw new \RuntimeException("GoogleTasksService: cannot read OAuth client file: {$oauthClientPath}");
        }

        $clientCreds = json_decode($oauthContents, true);

        if (!is_array($clientCreds)) {
            throw new \RuntimeException("GoogleTasksService: cannot parse OAuth client file: {$oauthClientPath}");
        }

        $installed = $clientCreds['installed'] ?? $clientCreds['web'] ?? null;

        if ($installed === null) {
            throw new \RuntimeException(
                "GoogleTasksService: OAuth client file has no 'installed' or 'web' key: {$oauthClientPath}"
            );
        }

        $this->client->setClientId($installed['client_id']);
        $this->client->setClientSecret($installed['client_secret']);
        $this->client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $this->client->setAccessToken($json);

        if ($this->client->isAccessTokenExpired()) {
            $refreshed = $this->client->fetchAccessTokenWithRefreshToken($json['refresh_token']);

            if (isset($refreshed['error'])) {
                throw new \RuntimeException(
                    "GoogleTasksService: OAuth token refresh failed: " .
                    ($refreshed['error_description'] ?? $refreshed['error'])
                );
            }

            $merged = array_merge($json, $refreshed);
            file_put_contents($this->tokenPath, json_encode($merged, JSON_PRETTY_PRINT));
        }
    }

    // ── Task list resolution ──────────────────────────────────────────────────

    /**
     * Return the ID of the default ("@default") task list.
     * Result is cached for the lifetime of this instance.
     *
     * @throws \RuntimeException if no task lists are returned
     */
    public function getDefaultTasklistId(): string
    {
        if ($this->defaultTasklistId !== null) {
            return $this->defaultTasklistId;
        }

        $result = $this->tasks->tasklists->listTasklists(['maxResults' => 1]);
        $lists  = $result->getItems();

        if (empty($lists)) {
            throw new \RuntimeException('GoogleTasksService: no task lists found for this account');
        }

        /** @var TaskList $first */
        $first = $lists[0];
        $this->defaultTasklistId = $first->getId();

        return $this->defaultTasklistId;
    }

    /**
     * Resolve a task list ID: return the provided ID or fall back to the default.
     */
    private function resolveTasklistId(?string $tasklistId): string
    {
        return $tasklistId ?? $this->getDefaultTasklistId();
    }

    // ── Task operations ───────────────────────────────────────────────────────

    /**
     * Create a task and return its Google Tasks ID.
     *
     * @param  string      $title      Task title
     * @param  string      $notes      Optional detail notes
     * @param  string|null $due        RFC-3339 due date string, or null for no due date
     * @param  string|null $tasklistId Target task list ID; null = default list
     * @return string                  Google Tasks task ID
     * @throws \RuntimeException on API or auth failure
     */
    public function createTask(
        string $title,
        string $notes = '',
        ?string $due = null,
        ?string $tasklistId = null
    ): string {
        $listId = $this->resolveTasklistId($tasklistId);

        $task = new Task();
        $task->setTitle($title);

        if ($notes !== '') {
            $task->setNotes($notes);
        }

        if ($due !== null) {
            $task->setDue($due);
        }

        $created = $this->tasks->tasks->insert($listId, $task);

        return $created->getId();
    }

    /**
     * Mark a task as completed.
     *
     * @throws \RuntimeException on API or auth failure
     */
    public function completeTask(string $taskId, ?string $tasklistId = null): void
    {
        $listId = $this->resolveTasklistId($tasklistId);

        $patch = new Task();
        $patch->setStatus('completed');

        $this->tasks->tasks->patch($listId, $taskId, $patch);
    }

    /**
     * Fetch a task by ID and return its raw resource fields as an array.
     *
     * @return array{id: string, title: string, status: string, notes?: string, due?: string, completed?: string}
     * @throws \RuntimeException if the task is not found or API call fails
     */
    public function getTask(string $taskId, ?string $tasklistId = null): array
    {
        $listId = $this->resolveTasklistId($tasklistId);

        try {
            $task = $this->tasks->tasks->get($listId, $taskId);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleTasksService: getTask failed for task {$taskId}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this->normalizeTask($task);
    }

    /**
     * List tasks in a task list.
     *
     * @param  string|null $tasklistId   null = default list
     * @param  bool        $showCompleted  Include completed tasks
     * @return array[]  Array of normalized task resource arrays
     * @throws \RuntimeException on API or auth failure
     */
    public function listTasks(?string $tasklistId = null, bool $showCompleted = false): array
    {
        $listId = $this->resolveTasklistId($tasklistId);

        $params = [
            'showCompleted' => $showCompleted ? 'true' : 'false',
            'showHidden'    => $showCompleted ? 'true' : 'false',
        ];

        $result = $this->tasks->tasks->listTasks($listId, $params);
        $items  = $result->getItems() ?? [];

        return array_map([$this, 'normalizeTask'], $items);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalize a Task object to a plain array.
     *
     * @return array{id: string, title: string, status: string, notes?: string, due?: string, completed?: string}
     */
    private function normalizeTask(Task $task): array
    {
        $out = [
            'id'     => (string) $task->getId(),
            'title'  => (string) $task->getTitle(),
            'status' => (string) $task->getStatus(),
        ];

        if ($task->getNotes() !== null) {
            $out['notes'] = $task->getNotes();
        }

        if ($task->getDue() !== null) {
            $out['due'] = $task->getDue();
        }

        if ($task->getCompleted() !== null) {
            $out['completed'] = $task->getCompleted();
        }

        return $out;
    }
}
