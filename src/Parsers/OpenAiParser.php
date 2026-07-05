<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Parsers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mostafax\AiReportingEngine\Contracts\AiConfigInterface;
use Mostafax\AiReportingEngine\Contracts\PromptParserInterface;
use Mostafax\AiReportingEngine\Support\IntermediateQuery;

/**
 * OpenAI intent parser. Runs ONLY when the regex parser cannot match the prompt.
 *
 * The model returns structured JSON that maps to a unified IntermediateQuery —
 * it never produces DSL or database queries. Latency and token usage from the
 * last call are exposed for the pipeline's execution log.
 *
 * The system/schema prompt is provided by the host via config so the package
 * stays domain-agnostic.
 */
final class OpenAiParser implements PromptParserInterface
{
    private float $lastLatencyMs = 0.0;
    private int $lastTokens = 0;

    public function __construct(
        private readonly AiConfigInterface $config,
        private readonly string $schemaPrompt,
        private readonly string $endpoint = 'https://api.openai.com/v1/chat/completions',
    ) {}

    public function source(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->config->enabled() && $this->config->apiKey() !== null;
    }

    public function lastLatencyMs(): float
    {
        return $this->lastLatencyMs;
    }

    public function lastTokens(): int
    {
        return $this->lastTokens;
    }

    public function parse(string $normalized, string $original): ?IntermediateQuery
    {
        $this->lastLatencyMs = 0.0;
        $this->lastTokens = 0;

        $apiKey = $this->config->apiKey();
        if ($apiKey === null) {
            return null;
        }

        try {
            $startedAt = microtime(true);

            $response = Http::timeout($this->config->timeout())
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                ])
                ->post($this->endpoint, [
                    'model' => $this->config->model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $this->schemaPrompt],
                        ['role' => 'user', 'content' => $this->userPrompt($original)],
                    ],
                    'temperature' => 0.3,
                    'max_tokens'  => 500,
                ]);

            $this->lastLatencyMs = round((microtime(true) - $startedAt) * 1000, 1);

            if (!$response->successful()) {
                Log::warning('OpenAI API response failed', ['status' => $response->status()]);
                return null;
            }

            $result = $response->json();
            $this->lastTokens = (int) ($result['usage']['total_tokens'] ?? 0);
            $content = (string) ($result['choices'][0]['message']['content'] ?? '');

            return $this->decode($content);
        } catch (\Throwable $e) {
            Log::warning('OpenAI parsing failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function decode(string $content): ?IntermediateQuery
    {
        if (!preg_match('/\{[\s\S]*\}/', $content, $m)) {
            return null;
        }

        $parsed = json_decode($m[0], true);
        if (!is_array($parsed) || !isset($parsed['collection'])) {
            Log::debug('OpenAI JSON parse failed', ['content' => $content]);
            return null;
        }

        return new IntermediateQuery(
            module:     (string) $parsed['collection'],
            operation:  (string) ($parsed['operation'] ?? 'list'),
            metrics:    (array) ($parsed['metrics'] ?? []),
            dimensions: (array) ($parsed['dimensions'] ?? []),
            filters:    (array) ($parsed['filters'] ?? []),
            groupBy:    (array) ($parsed['groupBy'] ?? []),
            sort:       (array) ($parsed['sort'] ?? []),
            limit:      isset($parsed['limit']) ? (int) $parsed['limit'] : 20,
            dateRange:  $parsed['dateRange'] ?? null,
            projection: (array) ($parsed['projection'] ?? []),
            source:     'openai',
        );
    }

    private function userPrompt(string $question): string
    {
        $now = now();

        return "Convert this question into a report query JSON:\n\n"
            . "Question: \"{$question}\"\n\n"
            . "Context:\n"
            . "- today: {$now->toDateString()}\n"
            . "- month start: {$now->copy()->startOfMonth()->toDateString()}\n"
            . "- month end: {$now->copy()->endOfMonth()->toDateString()}\n"
            . "- week start: {$now->copy()->startOfWeek()->toDateString()}\n"
            . "- week end: {$now->copy()->endOfWeek()->toDateString()}\n\n"
            . "Return JSON only, no comments.";
    }
}
