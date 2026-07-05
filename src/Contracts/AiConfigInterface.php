<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

/**
 * Runtime AI settings. The default implementation reads from config; hosts can
 * bind their own (e.g. DB-backed settings) without touching the pipeline.
 */
interface AiConfigInterface
{
    public function apiKey(): ?string;

    public function model(): string;

    public function timeout(): int;

    public function enabled(): bool;

    /**
     * @return 'openai'|'regex'|'auto'
     */
    public function parseMethod(): string;
}
