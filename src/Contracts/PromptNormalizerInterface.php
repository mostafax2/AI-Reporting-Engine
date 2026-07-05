<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

interface PromptNormalizerInterface
{
    /** Return the canonical form of a prompt (trim, case, Arabic, punctuation). */
    public function normalize(string $prompt): string;

    /** Stable fingerprint of a normalized prompt, used as the cache key. */
    public function fingerprint(string $normalizedPrompt): string;
}
