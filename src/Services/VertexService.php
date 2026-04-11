<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

/**
 * VertexService - thin wrapper around Google Vertex AI for text (Gemini)
 * and image (Imagen / Gemini image output) generation.
 *
 * Auth is via a GCP service account key file. The bearer token is fetched
 * once on construction and reused for the lifetime of the instance.
 *
 * Endpoint patterns:
 *   - :generateContent - Gemini-family text and multimodal
 *   - :predict         - Imagen image generation
 *
 * Model IDs are passed as-is to the endpoint; callers choose which model
 * (e.g. "gemini-2.0-flash-001", "imagen-3.0-generate-002") and which
 * method fits that model's API surface.
 *
 * Ported from /var/www/tkgen/slideshow.php + generate_pages.php so the
 * logic lives in one library that any TurnkeyAgentic app can reuse.
 */
class VertexService
{
    private string $projectId;
    private string $location;
    private string $accessToken;
    private Client $http;

    private const DEFAULT_LOCATION = 'us-central1';
    private const SCOPE             = 'https://www.googleapis.com/auth/cloud-platform';

    /**
     * @param string  $keyFile     Path to the service-account JSON
     * @param string  $projectId   GCP project ID
     * @param ?string $location    Region (default: us-central1)
     * @param int     $timeoutSec  HTTP timeout in seconds
     */
    public function __construct(
        string $keyFile,
        string $projectId,
        ?string $location = null,
        int $timeoutSec = 180
    ) {
        if (!is_file($keyFile)) {
            throw new RuntimeException("Vertex service account key not found: {$keyFile}");
        }

        $this->projectId = $projectId;
        $this->location  = $location ?? self::DEFAULT_LOCATION;

        $creds = new ServiceAccountCredentials([self::SCOPE], $keyFile);
        $authToken = $creds->fetchAuthToken(function ($request) {
            return (new Client(['verify' => false]))->send($request);
        });

        if (empty($authToken['access_token'])) {
            throw new RuntimeException('Vertex auth failed: no access token returned');
        }
        $this->accessToken = $authToken['access_token'];

        $this->http = new Client([
            'base_uri' => "https://{$this->location}-aiplatform.googleapis.com",
            'verify'   => false,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => $timeoutSec,
        ]);
    }

    /**
     * Call Imagen-style :predict endpoint. Returns decoded image bytes
     * (one entry per sample). Caller decides the file extension.
     *
     * @param string $model       e.g. "imagen-3.0-generate-002"
     * @param string $prompt      Text prompt
     * @param array  $parameters  Merged into the "parameters" payload.
     *                            Common keys: sampleCount, aspectRatio,
     *                            outputOptions.mimeType, negativePrompt,
     *                            seed, personGeneration
     *
     * @return string[] Binary image bytes, one per returned prediction
     */
    public function generateImages(string $model, string $prompt, array $parameters = []): array
    {
        $parameters = array_merge([
            'sampleCount'   => 1,
            'aspectRatio'   => '1:1',
            'outputOptions' => ['mimeType' => 'image/png'],
        ], $parameters);

        $endpoint = sprintf(
            '/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
            $this->projectId,
            $this->location,
            $model
        );

        $response = $this->http->post($endpoint, [
            'json' => [
                'instances'  => [['prompt' => $prompt]],
                'parameters' => $parameters,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $images = [];
        foreach ($body['predictions'] ?? [] as $pred) {
            if (!empty($pred['bytesBase64Encoded'])) {
                $images[] = base64_decode($pred['bytesBase64Encoded']);
            }
        }
        return $images;
    }

    /**
     * Call Gemini-style :generateContent endpoint. Returns the raw API
     * response array. Caller pulls text or inline image parts from
     * candidates[0].content.parts[].
     *
     * @param string $model              e.g. "gemini-2.0-flash-001"
     * @param string $prompt             Text prompt (single user turn)
     * @param array  $generationConfig   Merged into "generationConfig"
     */
    public function generateContent(string $model, string $prompt, array $generationConfig = []): array
    {
        $endpoint = sprintf(
            '/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->projectId,
            $this->location,
            $model
        );

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
        ];
        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        $response = $this->http->post($endpoint, ['json' => $payload]);
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Extract inline image parts from a :generateContent response. Useful
     * for Gemini image-output models ("Nano Banana" family) that return
     * images as inline_data parts rather than via the Imagen :predict path.
     *
     * @return string[] Binary image bytes, one per inline image part
     */
    public function extractInlineImages(array $generateContentResponse): array
    {
        $images = [];
        $parts = $generateContentResponse['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $b64 = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if ($b64) {
                $images[] = base64_decode($b64);
            }
        }
        return $images;
    }

    /**
     * Extract text from a :generateContent response.
     */
    public function extractText(array $generateContentResponse): string
    {
        $parts = $generateContentResponse['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) $text .= $part['text'];
        }
        return $text;
    }

    public function getProjectId(): string { return $this->projectId; }
    public function getLocation(): string  { return $this->location; }
}
