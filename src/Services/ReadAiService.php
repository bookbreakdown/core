<?php

namespace TurnkeyAgentic\Core\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * ReadAiService — Core adapter for the Read AI REST API.
 *
 * Handles OAuth 2.1 token rotation automatically. Each API call refreshes the
 * access token using the stored refresh token, persists the new refresh token,
 * then makes the request.
 *
 * Token lifecycle:
 *   - Access tokens expire after 10 minutes.
 *   - Refresh tokens are single-use and rotate on every refresh.
 *   - The new refresh token is persisted BEFORE making the API call
 *     to prevent token chain breakage on crash.
 *
 * Config keys (read from env or passed in):
 *   READ_AI_CLIENT_ID, READ_AI_CLIENT_SECRET, READ_AI_REFRESH_TOKEN
 *
 * The refresh token is stored in a file so it survives rotation.
 */
class ReadAiService
{
    private const BASE_URL    = 'https://api.read.ai';
    private const AUTH_URL    = 'https://authn.read.ai/oauth2/token';

    private string  $clientId;
    private string  $clientSecret;
    private string  $refreshTokenPath;
    private ?string $accessToken = null;
    private Client  $http;

    /**
     * @param string|null $clientId         Override env READ_AI_CLIENT_ID
     * @param string|null $clientSecret     Override env READ_AI_CLIENT_SECRET
     * @param string|null $refreshTokenPath Path to file storing the current refresh token
     */
    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $refreshTokenPath = null,
    ) {
        $this->clientId     = $clientId ?? env('READ_AI_CLIENT_ID', '');
        $this->clientSecret = $clientSecret ?? env('READ_AI_CLIENT_SECRET', '');

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('ReadAiService: READ_AI_CLIENT_ID and READ_AI_CLIENT_SECRET must be set');
        }

        // Refresh token stored in a file so rotation persists across requests
        $this->refreshTokenPath = $refreshTokenPath
            ?? storage_path('app/read-ai-refresh-token.txt');

        // Seed the file from env if it doesn't exist yet
        if (!is_file($this->refreshTokenPath)) {
            $envToken = env('READ_AI_REFRESH_TOKEN', '');
            if ($envToken === '') {
                throw new \RuntimeException('ReadAiService: No refresh token found. Set READ_AI_REFRESH_TOKEN in .env or provide a token file.');
            }
            $dir = dirname($this->refreshTokenPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->refreshTokenPath, $envToken);
        }

        $this->http = new Client([
            'base_uri' => self::BASE_URL,
            'timeout'  => 30,
            'headers'  => ['Accept' => 'application/json'],
        ]);
    }

    // ── Auth ──────────────────────────────────────────────────────────────

    /**
     * Refresh the access token using the stored refresh token.
     * Persists the new refresh token to disk before returning.
     *
     * @throws \RuntimeException on auth failure
     */
    private function refreshAccessToken(): void
    {
        $refreshToken = trim(file_get_contents($this->refreshTokenPath));

        if ($refreshToken === '') {
            throw new \RuntimeException('ReadAiService: Refresh token file is empty');
        }

        $authClient = new Client(['timeout' => 15]);

        try {
            $response = $authClient->post(self::AUTH_URL, [
                'auth'        => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);
        } catch (ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            throw new \RuntimeException("ReadAiService: Token refresh failed: {$body}");
        }

        $data = json_decode($response->getBody()->getContents(), true);

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new \RuntimeException('ReadAiService: Token refresh response missing tokens');
        }

        // Persist new refresh token BEFORE using the access token
        file_put_contents($this->refreshTokenPath, $data['refresh_token']);

        $this->accessToken = $data['access_token'];
    }

    /**
     * Ensure we have a valid access token. Refreshes if needed.
     */
    private function ensureAuth(): void
    {
        if ($this->accessToken === null) {
            $this->refreshAccessToken();
        }
    }

    /**
     * Make an authenticated GET request. Auto-refreshes on 401.
     *
     * @param  string $path  Full path with query string, e.g. "/v1/meetings?limit=5&expand[]=summary"
     * @return array  Decoded JSON response
     */
    private function get(string $path): array
    {
        $this->ensureAuth();

        $opts = ['headers' => ['Authorization' => "Bearer {$this->accessToken}"]];

        try {
            $response = $this->http->get($path, $opts);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 401) {
                $this->refreshAccessToken();
                $opts['headers']['Authorization'] = "Bearer {$this->accessToken}";
                $response = $this->http->get($path, $opts);
            } else {
                throw $e;
            }
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    // ── Meetings ─────────────────────────────────────────────────────────

    /**
     * List meetings in reverse chronological order.
     *
     * @param  int         $limit            Max meetings to return (default 20)
     * @param  int|null    $startTimeGte     Filter: start_time_ms >= this value (epoch ms)
     * @param  string|null $cursor           Pagination cursor
     * @param  array       $expand           Fields to expand (summary, action_items, metrics, transcript, etc.)
     * @return array       {object, url, has_more, data: [...]}
     */
    public function listMeetings(
        int     $limit = 20,
        ?int    $startTimeGte = null,
        ?string $cursor = null,
        array   $expand = [],
    ): array {
        $queryParts = ["limit={$limit}"];

        if ($startTimeGte !== null) {
            $queryParts[] = "start_time_ms.gte={$startTimeGte}";
        }
        if ($cursor !== null) {
            $queryParts[] = "cursor=" . urlencode($cursor);
        }
        foreach ($expand as $field) {
            $queryParts[] = "expand[]=" . urlencode($field);
        }

        $queryString = implode('&', $queryParts);

        return $this->get("/v1/meetings?{$queryString}");
    }

    /**
     * List ALL meetings, paginating automatically.
     *
     * @param  int      $limit        Per-page limit
     * @param  int|null $startTimeGte Filter by start time
     * @param  array    $expand       Fields to expand
     * @param  int      $maxPages     Safety limit on pagination
     * @return array    Flat array of meeting objects
     */
    public function listAllMeetings(
        int     $limit = 50,
        ?int    $startTimeGte = null,
        array   $expand = [],
        int     $maxPages = 20,
    ): array {
        $all    = [];
        $cursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->listMeetings($limit, $startTimeGte, $cursor, $expand);
            $all    = array_merge($all, $result['data'] ?? []);

            if (empty($result['has_more'])) {
                break;
            }

            // Extract cursor from the last item's ID
            $data = $result['data'] ?? [];
            $lastItem = end($data);
            $cursor = $lastItem['id'] ?? null;

            if ($cursor === null) {
                break;
            }
        }

        return $all;
    }

    /**
     * Get a single meeting by ID with optional expanded fields.
     *
     * @param  string $meetingId  Meeting ID
     * @param  array  $expand    Fields to expand: summary, action_items, metrics,
     *                           chapter_summaries, key_questions, transcript, recording_download
     * @return array  Meeting object
     */
    public function getMeeting(string $meetingId, array $expand = []): array
    {
        $expandQuery = '';
        if (!empty($expand)) {
            $parts = array_map(fn($f) => 'expand[]=' . urlencode($f), $expand);
            $expandQuery = '?' . implode('&', $parts);
        }

        return $this->get("/v1/meetings/{$meetingId}{$expandQuery}");
    }

    /**
     * Get a meeting with all available expanded data.
     *
     * @param  string $meetingId
     * @return array  Meeting with summary, action_items, metrics, chapter_summaries, key_questions
     */
    public function getMeetingFull(string $meetingId): array
    {
        return $this->getMeeting($meetingId, [
            'summary',
            'action_items',
            'metrics',
            'chapter_summaries',
            'key_questions',
        ]);
    }

    /**
     * Get live meeting data (transcript, chapter summaries) for an active meeting.
     *
     * @param  string   $meetingId
     * @param  array    $expand       Fields: transcript, chapter_summaries
     * @param  int|null $startTimeGte Filter transcript from this time (epoch ms)
     * @return array
     */
    public function getLiveMeeting(string $meetingId, array $expand = ['transcript'], ?int $startTimeGte = null): array
    {
        $parts = array_map(fn($f) => 'expand[]=' . urlencode($f), $expand);
        if ($startTimeGte !== null) {
            $parts[] = "start_time_ms.gte={$startTimeGte}";
        }
        $queryString = !empty($parts) ? '?' . implode('&', $parts) : '';

        return $this->get("/v1/meetings/{$meetingId}/live{$queryString}");
    }
}
