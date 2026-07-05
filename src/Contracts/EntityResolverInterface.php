<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

/**
 * Resolves a referenced entity name to a foreign-key id for filtering, e.g.
 * a class label "الصف 1 - A" → class_id 1. Hosts bind their own implementation;
 * a null binding disables this feature (RegexParser degrades gracefully).
 */
interface EntityResolverInterface
{
    /**
     * @param  string $type  logical entity, e.g. "class"
     * @param  string $name  the human name/label extracted from the prompt
     * @return int|string|null the resolved id, or null when nothing matches
     */
    public function resolveId(string $type, string $name): int|string|null;
}
