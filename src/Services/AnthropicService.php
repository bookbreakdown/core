<?php

namespace TurnkeyAgentic\Core\Services;

use CodeIgniter\CLI\CLI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;

class AnthropicService
{
    private Client $client;
    private string $apiKey;

    private const DEFAULT_MODEL      = 'claude-sonnet-4-5-20250929';
    private const DEFAULT_MAX_TOKENS = 8000;
    private const RETRY_WAIT_SECONDS = 90;
    private const MAX_RETRIES        = 3;

    public function __construct()
    {
        $this->apiKey = getenv('ANTHROPIC_API_KEY') ?: '';

        if (empty($this->apiKey)) {
            throw new \Exception('ANTHROPIC_API_KEY environment variable is not set');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers'  => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'timeout' => 10000,
        ]);
    }

    public function complete(
        string $systemPrompt,
        string $userMessage,
        ?string $model = null,
        ?int $maxTokens = null,
        ?string $context = null
    ): array {
        $resolvedModel = $model ?? self::DEFAULT_MODEL;

        $payload = [
            'model'      => $resolvedModel,
            'max_tokens' => $maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->client->post('messages', ['json' => $payload]);
                $data = json_decode($response->getBody()->getContents(), true);

                $usage = $data['usage'] ?? [];
                if (!empty($usage) && class_exists(\App\Services\AICostLogger::class)) {
                    \App\Services\AICostLogger::log(
                        'anthropic',
                        $resolvedModel,
                        $context ?? 'book_workflow',
                        (int) ($usage['input_tokens'] ?? 0),
                        (int) ($usage['output_tokens'] ?? 0)
                    );
                }

                return [
                    'success' => true,
                    'text'    => $data['content'][0]['text'] ?? '',
                    'usage'   => $usage,
                    'error'   => '',
                ];
            } catch (ServerException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 529 && $attempt < self::MAX_RETRIES) {
                    if (is_cli()) {
                        CLI::write("Anthropic overloaded (529). Waiting " . self::RETRY_WAIT_SECONDS . "s before retry {$attempt}/" . self::MAX_RETRIES . "...", 'yellow');
                    }
                    sleep(self::RETRY_WAIT_SECONDS);
                    continue;
                }

                return [
                    'success' => false,
                    'text'    => '',
                    'usage'   => [],
                    'error'   => "API error (HTTP {$statusCode}, attempt {$attempt}/" . self::MAX_RETRIES . "): " . $e->getMessage(),
                ];
            } catch (RequestException $e) {
                return [
                    'success' => false,
                    'text'    => '',
                    'usage'   => [],
                    'error'   => 'API request failed: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => false,
            'text'    => '',
            'usage'   => [],
            'error'   => 'All retry attempts exhausted.',
        ];
    }

    public function chat(string $message): string
    {
        $result = $this->complete('', $message, 'claude-3-haiku-20240307', 1000);
        return $result['text'];
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->chat('Hello');
            return !empty($response);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function translateChunk(string $sourceText, string $systemPrompt, array $knowledgeFiles = []): array
    {
        $userContent = '';
        foreach ($knowledgeFiles as $slug => $content) {
            $userContent .= "# Knowledge File: {$slug}\n\n{$content}\n\n---\n\n";
        }
        $userContent .= "# Source Text to Translate\n\n{$sourceText}";

        $result = $this->complete($systemPrompt, $userContent);

        if (!$result['success']) {
            return [
                'success'      => false,
                'error'        => $result['error'],
                'translation'  => '',
                'continuation' => '',
                'usage'        => [],
            ];
        }

        $parts = explode('---CONTINUATION---', $result['text']);
        return [
            'success'      => true,
            'translation'  => trim($parts[0] ?? ''),
            'continuation' => trim($parts[1] ?? ''),
            'usage'        => $result['usage'],
        ];
    }
}
