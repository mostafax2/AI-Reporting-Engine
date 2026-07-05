<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine;

use Illuminate\Support\Facades\Log;
use Mostafax\AiReportingEngine\Contracts\AiConfigInterface;
use Mostafax\AiReportingEngine\Contracts\PromptCacheInterface;
use Mostafax\AiReportingEngine\Contracts\PromptNormalizerInterface;
use Mostafax\AiReportingEngine\Exceptions\ReportParsingException;
use Mostafax\AiReportingEngine\Parsers\OpenAiParser;
use Mostafax\AiReportingEngine\Parsers\RegexParser;
use Mostafax\AiReportingEngine\Support\IntermediateQuery;

/**
 * Orchestrates the unified query-generation pipeline:
 *
 *   normalize → cache lookup → regex → openai → build DSL → cache → (return)
 *
 * It generates the DSL only; execution stays with the reporting engine.
 * The chosen source is one of: cache | regex | openai.
 */
final class ReportQueryPipeline
{
    public function __construct(
        private readonly PromptNormalizerInterface $normalizer,
        private readonly PromptCacheInterface $cache,
        private readonly RegexParser $regex,
        private readonly OpenAiParser $openai,
        private readonly DslBuilder $dslBuilder,
        private readonly AiConfigInterface $config,
    ) {}

    /**
     * @return array{source:string,origin:string,collection:?string,query:array,dsl:array,cached:bool}
     * @throws ReportParsingException when no parser can understand the prompt
     */
    public function process(string $prompt): array
    {
        $startedAt   = microtime(true);
        $normalized  = $this->normalizer->normalize($prompt);
        $fingerprint = $this->normalizer->fingerprint($normalized);

        // 1) Cache lookup — skips regex + OpenAI entirely on a hit.
        $hit = $this->cache->find($fingerprint);
        if ($hit !== null) {
            $this->log($prompt, 'cache', true, $startedAt, $hit['dsl'], 0.0, 0);
            return [
                'source'     => 'cache',
                'origin'     => $hit['source'],
                'collection' => $hit['collection'],
                'query'      => $hit['query'],
                'dsl'        => $hit['dsl'],
                'cached'     => true,
            ];
        }

        // 2) Parse into a unified IntermediateQuery (regex first, then OpenAI).
        $query = $this->parse($normalized, $prompt);
        if ($query === null) {
            throw ReportParsingException::unrecognized();
        }

        // 3) Build DSL centrally — no parser builds DSL directly.
        $dsl = $this->dslBuilder->build($query);

        // 4) Persist the parsed intent + DSL for future prompts.
        $this->cache->store($fingerprint, $prompt, $normalized, $query->toArray(), $dsl, $query->source);

        $this->log(
            $prompt,
            $query->source,
            false,
            $startedAt,
            $dsl,
            $query->source === 'openai' ? $this->openai->lastLatencyMs() : 0.0,
            $query->source === 'openai' ? $this->openai->lastTokens() : 0,
        );

        return [
            'source'     => $query->source,
            'origin'     => $query->source,
            'collection' => $query->module,
            'query'      => $query->toArray(),
            'dsl'        => $dsl,
            'cached'     => false,
        ];
    }

    private function parse(string $normalized, string $original): ?IntermediateQuery
    {
        $method = $this->config->parseMethod();

        if ($method !== 'openai') {
            $regexResult = $this->regex->parse($normalized, $original);
            if ($regexResult !== null) {
                return $regexResult;
            }
            if ($method === 'regex') {
                return null;
            }
        }

        if ($this->openai->isAvailable()) {
            return $this->openai->parse($normalized, $original);
        }

        return null;
    }

    private function log(
        string $prompt,
        string $source,
        bool $cacheHit,
        float $startedAt,
        array $dsl,
        float $openAiLatency,
        int $tokens,
    ): void {
        Log::info('AI report query generated', [
            'prompt'        => $prompt,
            'parser'        => $source,
            'cache_hit'     => $cacheHit,
            'execution_ms'  => round((microtime(true) - $startedAt) * 1000, 1),
            'openai_ms'     => $openAiLatency,
            'openai_tokens' => $tokens,
            'dsl'           => $dsl,
        ]);
    }
}
