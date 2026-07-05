<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Support;

/**
 * The unified intermediate query object produced by every parser (regex or AI).
 *
 * Neither the DSL builder nor the rest of the system needs to know which parser
 * produced it — only the parser sets `source`. This keeps the pipeline uniform.
 */
final class IntermediateQuery
{
    /**
     * @param array<int,array{field:string,operator:string,value:mixed}> $filters
     * @param string[] $metrics
     * @param string[] $dimensions
     * @param string[] $groupBy
     * @param array<int,array{column:string,direction:string}> $sort
     * @param string[] $projection
     */
    public function __construct(
        public readonly string $module,
        public readonly string $operation = 'list',
        public readonly array $metrics = [],
        public readonly array $dimensions = [],
        public readonly array $filters = [],
        public readonly array $groupBy = [],
        public readonly array $sort = [],
        public readonly ?int $limit = null,
        public readonly ?array $dateRange = null,
        public readonly array $projection = [],
        public string $source = 'regex',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            module:     (string) ($data['module'] ?? $data['collection'] ?? ''),
            operation:  (string) ($data['operation'] ?? 'list'),
            metrics:    (array) ($data['metrics'] ?? []),
            dimensions: (array) ($data['dimensions'] ?? []),
            filters:    (array) ($data['filters'] ?? []),
            groupBy:    (array) ($data['groupBy'] ?? $data['group_by'] ?? []),
            sort:       (array) ($data['sort'] ?? []),
            limit:      isset($data['limit']) ? (int) $data['limit'] : null,
            dateRange:  $data['dateRange'] ?? $data['date_range'] ?? null,
            projection: (array) ($data['projection'] ?? []),
            source:     (string) ($data['source'] ?? $data['_source'] ?? 'regex'),
        );
    }

    public function isValid(): bool
    {
        return $this->module !== '';
    }

    /**
     * Flat array shape kept backward-compatible with consumers that read
     * `collection`, `projection`, `filters`, `limit`, and `_source`.
     */
    public function toArray(): array
    {
        return [
            'collection' => $this->module,
            'module'     => $this->module,
            'operation'  => $this->operation,
            'metrics'    => $this->metrics,
            'dimensions' => $this->dimensions,
            'filters'    => $this->filters,
            'groupBy'    => $this->groupBy,
            'sort'       => $this->sort,
            'limit'      => $this->limit ?? 20,
            'dateRange'  => $this->dateRange,
            'projection' => $this->projection,
            '_source'    => $this->source,
        ];
    }
}
