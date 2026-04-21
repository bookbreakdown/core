<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\ModifyMessageRequest;

/**
 * GmailService — Core adapter for Gmail API (gmail.modify scope).
 *
 * Mirrors the OAuth boot pattern established in GoogleTasksService.
 * Auth and token failures throw \RuntimeException with descriptive messages
 * so callers can react safely rather than guessing.
 *
 * Scope used: gmail.modify
 * The service NEVER sends email. Send methods are intentionally absent.
 *
 * All message-level and thread-level identifiers are preserved in return values
 * so callers can later use either for different presentation/processing needs.
 *
 * Token refresh:
 *   If the access token has expired, bootAuth() uses the refresh_token to
 *   obtain a new one and persists it back to the token file before continuing.
 *   Any failure in this path throws \RuntimeException immediately.
 */
class GmailService
{
    private Client $client;
    private Gmail  $gmail;
    private string $tokenPath;
    private ?string $oauthClientPath;

    /** @var array<string,string>  labelName → labelId cache for this instance */
    private array $labelCache = [];

    public function __construct(?string $tokenPath = null, ?string $oauthClientPath = null)
    {
        $this->tokenPath = $tokenPath
            ?? env('GOOGLE_APPLICATION_CREDENTIALS', '')
            ?: '';

        $this->oauthClientPath = $oauthClientPath;

        if ($this->tokenPath === '') {
            throw new \RuntimeException('GmailService: GOOGLE_APPLICATION_CREDENTIALS is not set');
        }

        if (!is_file($this->tokenPath)) {
            throw new \RuntimeException("GmailService: token file not found: {$this->tokenPath}");
        }

        $this->client = new Client();
        $this->client->setApplicationName('TurnkeyAgentic Email Autopilot');
        $this->client->setScopes([Gmail::GMAIL_MODIFY]);

        $this->bootAuth();

        $this->gmail = new Gmail($this->client);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    /**
     * Boot OAuth credentials from the token file.
     * Automatically refreshes an expired access token and persists the new one.
     *
     * @throws \RuntimeException on missing file, parse failure, or refresh failure.
     */
    private function bootAuth(): void
    {
        $contents = file_get_contents($this->tokenPath);

        if ($contents === false) {
            throw new \RuntimeException("GmailService: cannot read token file: {$this->tokenPath}");
        }

        $json = json_decode($contents, true);

        if (!is_array($json)) {
            throw new \RuntimeException("GmailService: cannot parse token file: {$this->tokenPath}");
        }

        if (isset($json['type']) && $json['type'] === 'service_account') {
            // Service account — gmail.modify must be granted via domain-wide delegation
            $this->client->setAuthConfig($this->tokenPath);
            return;
        }

        if (!isset($json['refresh_token'])) {
            throw new \RuntimeException(
                'GmailService: token file is neither a service account nor an OAuth token ' .
                "(no refresh_token): {$this->tokenPath}"
            );
        }

        $oauthClientPath = $this->oauthClientPath
            ?? env('GOOGLE_OAUTH_CLIENT_PATH', '/home/ladieu/.config/google/oauth_client.json');

        if (!is_file($oauthClientPath)) {
            throw new \RuntimeException("GmailService: OAuth client file not found: {$oauthClientPath}");
        }

        $clientCreds = json_decode((string) file_get_contents($oauthClientPath), true);

        if (!is_array($clientCreds)) {
            throw new \RuntimeException("GmailService: cannot parse OAuth client file: {$oauthClientPath}");
        }

        $installed = $clientCreds['installed'] ?? $clientCreds['web'] ?? null;

        if ($installed === null) {
            throw new \RuntimeException(
                "GmailService: OAuth client file has no 'installed' or 'web' key: {$oauthClientPath}"
            );
        }

        $this->client->setClientId($installed['client_id']);
        $this->client->setClientSecret($installed['client_secret']);
        $this->client->setRedirectUri($installed['redirect_uris'][0] ?? 'urn:ietf:wg:oauth:2.0:oob');

        $this->client->setAccessToken($json);

        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $json['refresh_token'];
            $newToken     = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($newToken['error'])) {
                throw new \RuntimeException(
                    'GmailService: token refresh failed: ' . ($newToken['error_description'] ?? $newToken['error'])
                );
            }

            // Persist refresh_token if not included in new token response
            if (!isset($newToken['refresh_token'])) {
                $newToken['refresh_token'] = $refreshToken;
            }

            file_put_contents($this->tokenPath, json_encode($newToken));
            $this->client->setAccessToken($newToken);
        }
    }

    // ── User ──────────────────────────────────────────────────────────────────

    /**
     * Return the authenticated user's profile.
     *
     * @return array{emailAddress: string, messagesTotal: int, threadsTotal: int, historyId: string}
     */
    public function getProfile(): array
    {
        $profile = $this->gmail->users->getProfile('me');
        return [
            'emailAddress'  => $profile->getEmailAddress() ?? '',
            'messagesTotal' => (int) $profile->getMessagesTotal(),
            'threadsTotal'  => (int) $profile->getThreadsTotal(),
            'historyId'     => (string) $profile->getHistoryId(),
        ];
    }

    // ── Messages ──────────────────────────────────────────────────────────────

    /**
     * List message IDs matching a Gmail search query (single page).
     *
     * @return array<int, array{id: string, threadId: string}>  Both identifiers preserved.
     */
    public function listMessages(string $query = 'is:unread in:inbox', int $maxResults = 50): array
    {
        $response = $this->gmail->users_messages->listUsersMessages('me', [
            'q'          => $query,
            'maxResults' => min($maxResults, 500),
        ]);

        $messages = $response->getMessages() ?? [];
        $result   = [];

        foreach ($messages as $m) {
            $result[] = [
                'id'       => $m->getId(),
                'threadId' => $m->getThreadId(),
            ];
        }

        return $result;
    }

    /**
     * List ALL message IDs matching a query, handling pagination automatically.
     *
     * @param callable|null $onPage  Optional callback(int $pageNum, int $totalSoFar) for progress reporting.
     * @return array<int, array{id: string, threadId: string}>
     */
    public function listAllMessages(string $query, int $maxResults = 5000, ?callable $onPage = null): array
    {
        $result    = [];
        $pageToken = null;
        $page      = 0;

        do {
            $params = [
                'q'          => $query,
                'maxResults' => min(500, $maxResults - count($result)),
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response  = $this->gmail->users_messages->listUsersMessages('me', $params);
            $messages  = $response->getMessages() ?? [];
            $pageToken = $response->getNextPageToken();
            $page++;

            foreach ($messages as $m) {
                $result[] = [
                    'id'       => $m->getId(),
                    'threadId' => $m->getThreadId(),
                ];

                if (count($result) >= $maxResults) {
                    break 2;
                }
            }

            if ($onPage !== null) {
                $onPage($page, count($result));
            }

        } while ($pageToken !== null && count($result) < $maxResults);

        return $result;
    }

    /**
     * Fetch a full message. Returns all headers, body parts, labels, and identifiers.
     *
     * @return array{
     *   id: string,
     *   threadId: string,
     *   labelIds: string[],
     *   snippet: string,
     *   internalDateMs: int,
     *   headers: array<string, string>,
     *   bodyText: string,
     *   bodyHtml: string,
     * }
     * @throws \RuntimeException if the message is not found.
     */
    public function getMessage(string $messageId): array
    {
        $message = $this->gmail->users_messages->get('me', $messageId, ['format' => 'full']);

        return [
            'id'             => $message->getId(),
            'threadId'       => $message->getThreadId(),
            'labelIds'       => $message->getLabelIds() ?? [],
            'snippet'        => $message->getSnippet() ?? '',
            'internalDateMs' => (int) $message->getInternalDate(),
            'headers'        => $this->extractHeaders($message->getPayload()),
            'bodyText'       => $this->extractBody($message->getPayload(), 'text/plain'),
            'bodyHtml'       => $this->extractBody($message->getPayload(), 'text/html'),
        ];
    }

    /**
     * Fetch a thread, returning its message list with full headers.
     *
     * @return array{id: string, snippet: string, historyId: string, messages: array}
     */
    public function getThread(string $threadId): array
    {
        $thread = $this->gmail->users_threads->get('me', $threadId, ['format' => 'full']);

        $messages = [];
        foreach ($thread->getMessages() ?? [] as $msg) {
            $messages[] = [
                'id'             => $msg->getId(),
                'threadId'       => $msg->getThreadId(),
                'labelIds'       => $msg->getLabelIds() ?? [],
                'snippet'        => $msg->getSnippet() ?? '',
                'internalDateMs' => (int) $msg->getInternalDate(),
                'headers'        => $this->extractHeaders($msg->getPayload()),
                'bodyText'       => $this->extractBody($msg->getPayload(), 'text/plain'),
            ];
        }

        return [
            'id'        => $thread->getId(),
            'snippet'   => $thread->getSnippet() ?? '',
            'historyId' => (string) $thread->getHistoryId(),
            'messages'  => $messages,
        ];
    }

    // ── Labels ────────────────────────────────────────────────────────────────

    /**
     * List all labels in the user's mailbox.
     *
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function listLabels(): array
    {
        $response = $this->gmail->users_labels->listUsersLabels('me');
        $result   = [];

        foreach ($response->getLabels() ?? [] as $label) {
            $result[] = [
                'id'   => $label->getId(),
                'name' => $label->getName(),
                'type' => $label->getType() ?? 'user',
            ];
        }

        return $result;
    }

    /**
     * Look up or create a label by its full name.
     *
     * @param string $name  Full label name including any hierarchy (e.g. "AutoPilot/Work")
     * @return string  Gmail label ID
     */
    public function getOrCreateLabel(string $name): string
    {
        // Check local cache first (avoids repeated API calls in one run)
        if (isset($this->labelCache[$name])) {
            return $this->labelCache[$name];
        }

        // Check existing labels from API
        $existing = $this->listLabels();
        foreach ($existing as $label) {
            if ($label['name'] === $name) {
                $this->labelCache[$name] = $label['id'];
                return $label['id'];
            }
        }

        // Create the label
        $newLabel = new Label();
        $newLabel->setName($name);
        $newLabel->setLabelListVisibility('labelShow');
        $newLabel->setMessageListVisibility('show');

        $created = $this->gmail->users_labels->create('me', $newLabel);

        $labelId = $created->getId();
        $this->labelCache[$name] = $labelId;

        return $labelId;
    }

    // ── Message modifications ─────────────────────────────────────────────────

    /**
     * Apply a label ID to a message.
     */
    public function applyLabel(string $messageId, string $labelId): void
    {
        $this->modifyMessage($messageId, [$labelId], []);
    }

    /**
     * Remove a label ID from a message.
     * Silently no-ops if the message does not carry the label.
     */
    public function removeLabel(string $messageId, string $labelId): void
    {
        $this->modifyMessage($messageId, [], [$labelId]);
    }

    /**
     * Archive a message (remove from INBOX).
     * Does NOT delete. Does NOT mark as read.
     * This is a distinct side effect from markRead().
     */
    public function archiveMessage(string $messageId): void
    {
        $this->modifyMessage($messageId, [], ['INBOX']);
    }

    /**
     * Mark a message as read (remove UNREAD label).
     * This is a distinct side effect from archiveMessage().
     */
    public function markRead(string $messageId): void
    {
        $this->modifyMessage($messageId, [], ['UNREAD']);
    }

    /**
     * Low-level label modification.
     *
     * @param string[] $addLabelIds
     * @param string[] $removeLabelIds
     */
    public function modifyMessage(string $messageId, array $addLabelIds, array $removeLabelIds): void
    {
        $request = new ModifyMessageRequest();
        $request->setAddLabelIds($addLabelIds);
        $request->setRemoveLabelIds($removeLabelIds);

        $this->gmail->users_messages->modify('me', $messageId, $request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extract all headers from a message payload into a flat key→value map.
     * Multi-value headers (rare) retain only the last value.
     *
     * @return array<string, string>  Header names are lowercased for consistent access.
     */
    private function extractHeaders(?\Google\Service\Gmail\MessagePart $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $headers = [];
        foreach ($payload->getHeaders() ?? [] as $header) {
            $headers[strtolower($header->getName())] = $header->getValue();
        }

        return $headers;
    }

    /**
     * Extract a text body part of a given MIME type from a message payload.
     * Handles both simple messages (single part) and multipart messages.
     * Returns empty string if the MIME type is not found.
     *
     * @param string $mimeType  e.g. 'text/plain' or 'text/html'
     */
    private function extractBody(?\Google\Service\Gmail\MessagePart $payload, string $mimeType): string
    {
        if ($payload === null) {
            return '';
        }

        // Simple single-part message
        if ($payload->getMimeType() === $mimeType) {
            return $this->decodeBody($payload->getBody());
        }

        // Multipart — recurse into parts
        foreach ($payload->getParts() ?? [] as $part) {
            $text = $this->extractBodyFromPart($part, $mimeType);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Recursively search a message part (and its sub-parts) for the target MIME type.
     */
    private function extractBodyFromPart(\Google\Service\Gmail\MessagePart $part, string $mimeType): string
    {
        if ($part->getMimeType() === $mimeType) {
            return $this->decodeBody($part->getBody());
        }

        foreach ($part->getParts() ?? [] as $subPart) {
            $text = $this->extractBodyFromPart($subPart, $mimeType);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Decode a Gmail body part (base64url encoded).
     */
    private function decodeBody(?\Google\Service\Gmail\MessagePartBody $body): string
    {
        if ($body === null) {
            return '';
        }

        $data = $body->getData();
        if ($data === null || $data === '') {
            return '';
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'));
        return $decoded !== false ? $decoded : '';
    }
}
