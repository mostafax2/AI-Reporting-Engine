<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Contracts;

/**
 * Supplies the regex section mappings and column concepts that drive the
 * RegexParser. Backing store is pluggable (MongoDB by default, but any source
 * that returns the same array shape works).
 */
interface MappingRepositoryInterface
{
    /**
     * Active section mappings ordered by priority (ascending = matched first).
     * Each row: ['collection','patterns','projection','text_column','priority','is_active'].
     *
     * @return array<int,array<string,mixed>>
     */
    public function sectionMappings(): array;

    /**
     * Active column concepts.
     * Each row: ['label','patterns','columns_per_collection','is_active'].
     *
     * @return array<int,array<string,mixed>>
     */
    public function columnConcepts(): array;
}
