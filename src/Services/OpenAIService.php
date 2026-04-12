<?php

namespace TurnkeyAgentic\Core\Services;

use CodeIgniter\CLI\CLI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;

class OpenAIService
{
    private Client $client;
    private string $apiKey;
    private string $qaPrompt;
    private array $knowledgeFiles;

    private const RETRY_WAIT_SECONDS = 90;
    private const MAX_RETRIES        = 3;

    private array $lastRawResponse = [];

    public function getLastRawResponse(): array
    {
        return $this->lastRawResponse;
    }

    public function __construct(?string $systemPrompt = null, array $knowledgeFiles = [])
    {
        $this->apiKey = getenv('OPENAI_API_KEY') ?: '';

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY environment variable is not set');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'timeout' => 300,
        ]);

        $this->knowledgeFiles = $knowledgeFiles;

        if ($systemPrompt !== null) {
            $this->qaPrompt = $systemPrompt;
        } else {
            $this->loadQAPrompt();
        }
    }

    private function loadQAPrompt(): void
    {
        $appPath  = defined('APPPATH') ? APPPATH . '../prompts/qa_review.md' : null;
        $corePath = __DIR__ . '/../../../prompts/qa_review.md';

        if ($appPath !== null && file_exists($appPath)) {
            $this->qaPrompt = file_get_contents($appPath);
        } elseif (file_exists($corePath)) {
            $this->qaPrompt = file_get_contents($corePath);
        } else {
            // No QA prompt file present — default to empty string.
            // This allows non-QA consumers (e.g. classify()) to use the service
            // without needing a qa_review.md file in the project.
            $this->qaPrompt = '';
        }
    }

    public function complete(string $systemPrompt, string $userMessage, string $model = 'gpt-4o', array $extraParams = [], ?string $context = null): array
    {
        $payload = array_merge([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ], $extraParams);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->client->post('chat/completions', ['json' => $payload]);
                $data = json_decode($response->getBody()->getContents(), true);

                $usage = $data['usage'] ?? [];
                if (!empty($usage) && class_exists(\App\Services\AICostLogger::class)) {
                    \App\Services\AICostLogger::log(
                        'openai',
                        $model,
                        $context ?? 'book_workflow',
                        (int) ($usage['prompt_tokens'] ?? 0),
                        (int) ($usage['completion_tokens'] ?? 0)
                    );
                }

                return [
                    'success' => true,
                    'text'    => $data['choices'][0]['message']['content'] ?? '',
                    'usage'   => $usage,
                    'error'   => '',
                ];
            } catch (ServerException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if (in_array($statusCode, [429, 529], true) && $attempt < self::MAX_RETRIES) {
                    if (is_cli()) {
                        CLI::write("OpenAI overloaded ({$statusCode}). Waiting " . self::RETRY_WAIT_SECONDS . "s before retry {$attempt}/" . self::MAX_RETRIES . "...", 'yellow');
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

        return ['success' => false, 'text' => '', 'usage' => [], 'error' => 'All retry attempts exhausted.'];
    }

    /**
     * classify() — single-shot structured-output call for classification/extraction.
     *
     * Differences from complete():
     *   - No internal retries (caller owns retry/fallback logic).
     *   - Requests JSON mode (response_format: json_object) automatically.
     *   - Returns raw JSON string alongside parsed array.
     *   - Returns latency in milliseconds.
     *   - Returns structured error details without throwing.
     *   - Does not log cost (caller logs via ai_usage_log).
     *
     * @param  string      $prompt   Complete prompt; expected to include JSON output schema.
     * @param  string|null $model    Model to use; defaults to gpt-4o.
     * @param  array       $options  Extra API parameters (e.g. max_tokens, temperature).
     * @return array{
     *   success: bool,
     *   rawJson: string,
     *   parsed: array,
     *   inputTokens: int,
     *   outputTokens: int,
     *   latencyMs: int,
     *   error: string,
     * }
     */
    public function classify(string $prompt, ?string $model = null, array $options = []): array
    {
        $resolvedModel = $model ?? 'gpt-4o';

        $payload = array_merge([
            'model'           => $resolvedModel,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => 'You are a structured data extraction engine. Respond only with valid JSON matching the schema in the prompt.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ], array_diff_key($options, array_flip(['response_format'])));

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = $this->client->post('chat/completions', ['json' => $payload]);
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;
            $data = json_decode($response->getBody()->getContents(), true);

            $rawJson = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];

            return [
                'success'      => true,
                'rawJson'      => $rawJson,
                'parsed'       => json_decode($rawJson, true) ?? [],
                'inputTokens'  => (int) ($usage['prompt_tokens'] ?? 0),
                'outputTokens' => (int) ($usage['completion_tokens'] ?? 0),
                'latencyMs'    => $latencyMs,
                'error'        => '',
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;
            $statusCode = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : 0;

            return [
                'success'      => false,
                'rawJson'      => '',
                'parsed'       => [],
                'inputTokens'  => 0,
                'outputTokens' => 0,
                'latencyMs'    => $latencyMs,
                'error'        => $statusCode
                    ? "HTTP {$statusCode}: " . $e->getMessage()
                    : $e->getMessage(),
            ];
        }
    }

    public function reviewTranslation(string $sourceText, string $translatedText, array $canonPatches = []): array
    {
        $userMessage = '';
        foreach ($this->knowledgeFiles as $slug => $content) {
            $userMessage .= "# Knowledge File: {$slug}\n\n{$content}\n\n---\n\n";
        }

        $userMessage .= "# Source Text\n\n{$sourceText}\n\n---\n\n# Translation to Review\n\n{$translatedText}";

        if (!empty($canonPatches)) {
            $userMessage .= "\n\n---\n\n# Previously Applied Patches (Canon - Do Not Revisit)\n\n";
            $userMessage .= "The following patches have already been applied and are locked as final. ";
            $userMessage .= "Do not suggest reverting, undoing, or re-patching any text introduced by these changes.\n\n";
            foreach ($canonPatches as $patch) {
                $userMessage .= "- **{$patch['type']}**: \"{$patch['find']}\" -> \"{$patch['replace']}\"\n";
            }
        }

        $result = $this->complete($this->qaPrompt, $userMessage, 'gpt-4o', [
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (!$result['success']) {
            return [
                'success'   => false,
                'error'     => $result['error'],
                'hard_pass' => false,
                'ship'      => false,
            ];
        }

        $parsed = json_decode($result['text'], true);
        if (!$parsed) {
            return [
                'success'   => false,
                'error'     => 'Invalid JSON response from OpenAI',
                'hard_pass' => false,
                'ship'      => false,
            ];
        }

        $this->lastRawResponse = $parsed;

        return [
            'success'         => true,
            'hard_pass'       => $parsed['hard_pass'] ?? false,
            'blocked_reasons' => $parsed['blocked_reasons'] ?? [],
            'patches'         => $parsed['patches'] ?? [],
            'notes'           => $parsed['notes'] ?? [],
            'ship'            => $parsed['ship'] ?? false,
            'usage'           => $result['usage'],
        ];
    }

    public function test(): bool
    {
        try {
            $result = $this->complete('', 'Say "OK" if you can read this.', 'gpt-4o-mini');
            return $result['success'] && !empty($result['text']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
