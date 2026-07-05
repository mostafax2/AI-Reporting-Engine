<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mostafax\AiReportingEngine\Contracts\MappingRepositoryInterface;

/**
 * MongoDB-backed source of regex section mappings and column concepts,
 * editable at runtime without a deploy. Rows are cached per request.
 */
final class MongoMappingRepository implements MappingRepositoryInterface
{
    private ?array $sections = null;
    private ?array $concepts = null;

    public function __construct(
        private readonly string $connection = 'mongodb',
        private readonly string $sectionsCollection = 'ai_report_mappings',
        private readonly string $conceptsCollection = 'ai_column_concepts',
    ) {}

    public function sectionMappings(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }
        try {
            $this->sections = DB::connection($this->connection)->table($this->sectionsCollection)
                ->where('is_active', true)
                ->orderBy('priority', 'asc')
                ->get()->map(fn($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            Log::warning('AI report mappings unavailable', ['error' => $e->getMessage()]);
            $this->sections = [];
        }
        return $this->sections;
    }

    public function columnConcepts(): array
    {
        if ($this->concepts !== null) {
            return $this->concepts;
        }
        try {
            $this->concepts = DB::connection($this->connection)->table($this->conceptsCollection)
                ->where('is_active', true)
                ->get()->map(fn($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            Log::warning('AI column concepts unavailable', ['error' => $e->getMessage()]);
            $this->concepts = [];
        }
        return $this->concepts;
    }
}
