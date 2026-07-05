<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Support;

use Mostafax\AiReportingEngine\Contracts\AiConfigInterface;

/**
 * Default AiConfig implementation backed by Laravel config (with env fallbacks).
 * Hosts that store settings elsewhere (e.g. a DB) can bind their own
 * AiConfigInterface implementation instead.
 */
final class ConfigAiConfig implements AiConfigInterface
{
    public function apiKey(): ?string
    {
        $key = (string) config('ai-reporting.openai.api_key', '');

        return ($key === '' || $key === 'sk-proj-YOUR_KEY_HERE') ? null : $key;
    }

    public function model(): string
    {
        return (string) config('ai-reporting.openai.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai-reporting.openai.timeout', 30);
    }

    public function enabled(): bool
    {
        return (bool) config('ai-reporting.enabled', true);
    }

    public function parseMethod(): string
    {
        $val = (string) config('ai-reporting.parse_method', 'auto');

        return in_array($val, ['openai', 'regex', 'auto'], true) ? $val : 'auto';
    }
}
