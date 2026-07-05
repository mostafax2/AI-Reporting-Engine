<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

/**
 * Stores and retrieves generated report intents (query + DSL) keyed by the
 * prompt fingerprint. Only the parsed intent is cached — never result rows.
 */
interface PromptCacheInterface
{
    /**
     * @return array{query:array,dsl:array,source:string,collection:?string}|null
     */
    public function find(string $fingerprint): ?array;

    public function store(
        string $fingerprint,
        string $originalPrompt,
        string $normalizedPrompt,
        array $query,
        array $dsl,
        string $source,
    ): void;
}
