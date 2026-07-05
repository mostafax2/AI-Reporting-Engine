<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mostafax\AiReportingEngine\Contracts\PromptCacheInterface;

/**
 * MongoDB-backed prompt cache. Stores only the parsed intent + DSL, never rows.
 * Connection, collection, and TTL are configurable.
 */
final class MongoPromptCache implements PromptCacheInterface
{
    public function __construct(
        private readonly string $connection = 'mongodb',
        private readonly string $collection = 'ai_queries',
        private readonly int $ttlHours = 24,
    ) {}

    public function find(string $fingerprint): ?array
    {
        try {
            $doc = DB::connection($this->connection)->table($this->collection)
                ->where('question_hash', $fingerprint)
                ->first();

            if (!$doc) {
                return null;
            }

            $doc = (array) $doc;

            $fresh = now()->diffInHours($doc['cached_at'] ?? now()) < $this->ttlHours;
            if (!$fresh || empty($doc['query'])) {
                return null;
            }

            $this->touch($fingerprint);

            return [
                'query'      => (array) $doc['query'],
                'dsl'        => (array) ($doc['dsl'] ?? []),
                'source'     => (string) ($doc['source'] ?? ($doc['query']['_source'] ?? 'regex')),
                'collection' => $doc['collection'] ?? ($doc['query']['collection'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('Prompt cache lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function store(
        string $fingerprint,
        string $originalPrompt,
        string $normalizedPrompt,
        array $query,
        array $dsl,
        string $source,
    ): void {
        try {
            $existing = DB::connection($this->connection)->table($this->collection)
                ->where('question_hash', $fingerprint)
                ->first();

            $usageCount = $existing ? (int) (((array) $existing)['usage_count'] ?? 0) : 0;

            DB::connection($this->connection)->table($this->collection)->updateOrInsert(
                ['question_hash' => $fingerprint],
                [
                    'question'          => $originalPrompt,
                    'normalized_prompt' => $normalizedPrompt,
                    'question_hash'     => $fingerprint,
                    'collection'        => $query['collection'] ?? ($query['module'] ?? null),
                    'source'            => $source,
                    'query'             => $query,
                    'dsl'               => $dsl,
                    'cached_at'         => now(),
                    'created_at'        => $existing ? (((array) $existing)['created_at'] ?? now()) : now(),
                    'last_used_at'      => now(),
                    'usage_count'       => $usageCount + 1,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Prompt cache store failed', ['error' => $e->getMessage()]);
        }
    }

    private function touch(string $fingerprint): void
    {
        try {
            DB::connection($this->connection)->table($this->collection)
                ->where('question_hash', $fingerprint)
                ->increment('usage_count', 1, ['last_used_at' => now()]);
        } catch (\Throwable) {
            // Non-critical.
        }
    }
}
