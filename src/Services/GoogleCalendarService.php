<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;

/**
 * GoogleCalendarService — Core adapter for Google Calendar API.
 *
 * Mirrors the OAuth boot pattern established in GoogleTasksService.
 * Auth and token failures throw \RuntimeException with descriptive messages
 * so callers can react safely rather than guessing.
 *
 * Calendar ID defaults to 'primary' when not specified.
 */
class GoogleCalendarService
{
    private Client $client;
    private Calendar $calendar;
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $this->tokenPath = $tokenPath
            ?? env('GOOGLE_APPLICATION_CREDENTIALS')
            ?? '';

        if ($this->tokenPath === '') {
            throw new \RuntimeException('GoogleCalendarService: GOOGLE_APPLICATION_CREDENTIALS is not set');
        }

        if (!is_file($this->tokenPath)) {
            throw new \RuntimeException("GoogleCalendarService: token file not found: {$this->tokenPath}");
        }

        if (!is_readable($this->tokenPath)) {
            throw new \RuntimeException("GoogleCalendarService: token file not readable: {$this->tokenPath}");
        }

        $this->client = new Client();
        $this->client->setApplicationName('TurnkeyAgentic Google Calendar');
        $this->client->setScopes([Calendar::CALENDAR]);

        $this->bootAuth();

        $this->calendar = new Calendar($this->client);
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
            throw new \RuntimeException("GoogleCalendarService: cannot read token file: {$this->tokenPath}");
        }

        $json = json_decode($contents, true);

        if (!is_array($json)) {
            throw new \RuntimeException("GoogleCalendarService: cannot parse token file: {$this->tokenPath}");
        }

        if (isset($json['type']) && $json['type'] === 'service_account') {
            // Service account path — calendar scope must be granted
            $this->client->setAuthConfig($this->tokenPath);
            return;
        }

        if (!isset($json['refresh_token'])) {
            throw new \RuntimeException(
                "GoogleCalendarService: token file is neither a service account nor an OAuth token " .
                "(no refresh_token): {$this->tokenPath}"
            );
        }

        $oauthClientPath = env('GOOGLE_OAUTH_CLIENT_PATH', '/home/ladieu/.config/google/oauth_client.json');

        if (!is_file($oauthClientPath)) {
            throw new \RuntimeException("GoogleCalendarService: OAuth client file not found: {$oauthClientPath}");
        }

        $oauthContents = file_get_contents($oauthClientPath);

        if ($oauthContents === false) {
            throw new \RuntimeException("GoogleCalendarService: cannot read OAuth client file: {$oauthClientPath}");
        }

        $clientCreds = json_decode($oauthContents, true);

        if (!is_array($clientCreds)) {
            throw new \RuntimeException("GoogleCalendarService: cannot parse OAuth client file: {$oauthClientPath}");
        }

        $installed = $clientCreds['installed'] ?? $clientCreds['web'] ?? null;

        if ($installed === null) {
            throw new \RuntimeException(
                "GoogleCalendarService: OAuth client file has no 'installed' or 'web' key: {$oauthClientPath}"
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
                    "GoogleCalendarService: OAuth token refresh failed: " .
                    ($refreshed['error_description'] ?? $refreshed['error'])
                );
            }

            $merged = array_merge($json, $refreshed);
            file_put_contents($this->tokenPath, json_encode($merged, JSON_PRETTY_PRINT));
        }
    }

    // ── Calendar ID resolution ────────────────────────────────────────────────

    /**
     * Resolve a calendar ID: return the provided ID or fall back to 'primary'.
     */
    private function resolveCalendarId(?string $calendarId): string
    {
        return $calendarId ?? 'primary';
    }

    // ── Calendar operations ───────────────────────────────────────────────────

    /**
     * List calendars in the authenticated user's calendar list.
     *
     * @return array<array{id: string, summary: string, primary: bool}> Array of calendar entries
     * @throws \RuntimeException on API or auth failure
     */
    public function listCalendars(): array
    {
        try {
            $result = $this->calendar->calendarList->listCalendarList();
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleCalendarService: listCalendars failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $items = $result->getItems() ?? [];

        return array_map(function ($cal) {
            return [
                'id'      => (string) $cal->getId(),
                'summary' => (string) $cal->getSummary(),
                'primary' => (bool) $cal->getPrimary(),
            ];
        }, $items);
    }

    /**
     * List events from a calendar.
     *
     * @param  string|null $calendarId  null = primary calendar
     * @param  string|null $timeMin     RFC-3339 lower bound; null = now
     * @param  string|null $timeMax     RFC-3339 upper bound; null = 7 days from now
     * @param  int         $maxResults  Maximum number of events to return
     * @return array[]  Array of normalized event arrays
     * @throws \RuntimeException on API or auth failure
     */
    public function listEvents(
        ?string $calendarId = null,
        ?string $timeMin = null,
        ?string $timeMax = null,
        int $maxResults = 25
    ): array {
        $calId = $this->resolveCalendarId($calendarId);

        $now        = new \DateTime('now', new \DateTimeZone('UTC'));
        $weekLater  = (clone $now)->modify('+7 days');

        $params = [
            'maxResults'   => $maxResults,
            'orderBy'      => 'startTime',
            'singleEvents' => true,
            'timeMin'      => $timeMin ?? $now->format(\DateTime::RFC3339),
            'timeMax'      => $timeMax ?? $weekLater->format(\DateTime::RFC3339),
        ];

        try {
            $result = $this->calendar->events->listEvents($calId, $params);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleCalendarService: listEvents failed for calendar {$calId}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $items = $result->getItems() ?? [];

        return array_map([$this, 'normalizeEvent'], $items);
    }

    /**
     * Fetch a single event by ID.
     *
     * @param  string      $eventId     Google Calendar event ID
     * @param  string|null $calendarId  null = primary calendar
     * @return array  Normalized event array
     * @throws \RuntimeException if the event is not found or API call fails
     */
    public function getEvent(string $eventId, ?string $calendarId = null): array
    {
        $calId = $this->resolveCalendarId($calendarId);

        try {
            $event = $this->calendar->events->get($calId, $eventId);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleCalendarService: getEvent failed for event {$eventId}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this->normalizeEvent($event);
    }

    /**
     * Create a calendar event and return its Google Calendar event ID.
     *
     * @param  string      $summary     Event title
     * @param  string      $start       RFC-3339 start datetime string
     * @param  string      $end         RFC-3339 end datetime string
     * @param  string|null $description Optional event description
     * @param  string|null $calendarId  null = primary calendar
     * @return string  Google Calendar event ID
     * @throws \RuntimeException on API or auth failure
     */
    public function createEvent(
        string $summary,
        string $start,
        string $end,
        ?string $description = null,
        ?string $calendarId = null
    ): string {
        $calId = $this->resolveCalendarId($calendarId);

        $startDt = new EventDateTime();
        $startDt->setDateTime($start);
        $startDt->setTimeZone('UTC');

        $endDt = new EventDateTime();
        $endDt->setDateTime($end);
        $endDt->setTimeZone('UTC');

        $event = new Event();
        $event->setSummary($summary);
        $event->setStart($startDt);
        $event->setEnd($endDt);

        if ($description !== null) {
            $event->setDescription($description);
        }

        try {
            $created = $this->calendar->events->insert($calId, $event);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleCalendarService: createEvent failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $created->getId();
    }

    /**
     * Delete a calendar event.
     *
     * @param  string      $eventId     Google Calendar event ID
     * @param  string|null $calendarId  null = primary calendar
     * @throws \RuntimeException on API or auth failure
     */
    public function deleteEvent(string $eventId, ?string $calendarId = null): void
    {
        $calId = $this->resolveCalendarId($calendarId);

        try {
            $this->calendar->events->delete($calId, $eventId);
        } catch (\Google\Service\Exception $e) {
            throw new \RuntimeException(
                "GoogleCalendarService: deleteEvent failed for event {$eventId}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalize an Event object to a plain array.
     *
     * @return array{id: string, summary: string, start: string, end: string, description?: string, status: string}
     */
    private function normalizeEvent(Event $event): array
    {
        $startObj = $event->getStart();
        $endObj   = $event->getEnd();

        $out = [
            'id'      => (string) $event->getId(),
            'summary' => (string) $event->getSummary(),
            'start'   => $startObj ? ($startObj->getDateTime() ?? $startObj->getDate() ?? '') : '',
            'end'     => $endObj   ? ($endObj->getDateTime()   ?? $endObj->getDate()   ?? '') : '',
            'status'  => (string) $event->getStatus(),
        ];

        if ($event->getDescription() !== null) {
            $out['description'] = $event->getDescription();
        }

        return $out;
    }
}
