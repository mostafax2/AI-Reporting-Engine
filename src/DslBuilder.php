<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine;

use Mostafax\AiReportingEngine\Exceptions\ReportParsingException;
use Mostafax\AiReportingEngine\Support\IntermediateQuery;

/**
 * The single, centralized place that turns a unified IntermediateQuery into a
 * ReportingEngine DSL array. No parser builds DSL directly.
 *
 * The produced DSL is consumed as-is by mostafax/dynamic-hybrid-reporting-engine
 * (the execution engine).
 */
final class DslBuilder
{
    private const MAX_LIMIT = 2000;

    public function __construct(
        private readonly string $source = 'mongodb',
        private readonly string $connection = 'mongodb',
    ) {}

    public function build(IntermediateQuery $query): array
    {
        if (!$query->isValid()) {
            throw ReportParsingException::unrecognized();
        }

        $perPage = min($query->limit ?? 20, self::MAX_LIMIT);

        $dsl = [
            'source'     => $this->source,
            'connection' => $this->connection,
            'table'      => $query->module,
            'pagination' => ['page' => 1, 'per_page' => $perPage],
        ];

        $fields = $this->mapFields($query->projection);
        if ($fields !== []) {
            $dsl['fields'] = $fields;
        }

        $conditions = $this->mapFilters($query->filters);
        if ($conditions !== []) {
            // ReportingEngine expects a FilterGroup ({operator, conditions}),
            // not a flat list — so the DSL is directly executable by the engine.
            $dsl['filters'] = ['operator' => 'AND', 'conditions' => $conditions];
        }

        if ($query->sort !== []) {
            $dsl['order_by'] = $query->sort;
        }

        return $dsl;
    }

    /** Keep only flat (non-relation) column keys, as documents are flat. */
    private function mapFields(array $projection): array
    {
        return array_values(array_filter(
            $projection,
            fn($c) => is_string($c) && $c !== '' && !str_contains($c, '.'),
        ));
    }

    private function mapFilters(array $filters): array
    {
        $mapped = [];
        foreach ($filters as $f) {
            $field = $f['field'] ?? $f['column'] ?? null;
            if ($field === null) {
                continue;
            }
            $op  = $f['operator'] ?? '=';
            $val = $f['value'] ?? null;

            $mapped[] = $op === 'like'
                ? ['column' => $field, 'operator' => 'like', 'value' => '%' . $val . '%']
                : ['column' => $field, 'operator' => $op, 'value' => $val];
        }
        return $mapped;
    }
}
