<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

use Mostafax\AiReportingEngine\Support\IntermediateQuery;

/**
 * A prompt parser turns a natural-language prompt into a unified IntermediateQuery.
 * Implementations (regex, OpenAI, or any custom engine) must never build DSL.
 */
interface PromptParserInterface
{
    /**
     * @param string $normalized normalized prompt (for matching)
     * @param string $original   raw prompt (for order/format-sensitive extraction)
     */
    public function parse(string $normalized, string $original): ?IntermediateQuery;

    /** Whether this parser can run in the current environment (e.g. AI key present). */
    public function isAvailable(): bool;

    /** Stable identifier stored as the parser source, e.g. "regex" | "openai". */
    public function source(): string;
}
