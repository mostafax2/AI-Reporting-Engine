<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Parsers;

use Mostafax\AiReportingEngine\Contracts\EntityResolverInterface;
use Mostafax\AiReportingEngine\Contracts\MappingRepositoryInterface;
use Mostafax\AiReportingEngine\Contracts\PromptParserInterface;
use Mostafax\AiReportingEngine\Support\IntermediateQuery;

/**
 * Deterministic, token-free parser. Matches the (normalized) prompt against the
 * regex section mappings and column concepts, and returns a unified
 * IntermediateQuery — NEVER a DSL.
 *
 * All domain knowledge (patterns, columns, entity ids) is injected, so this
 * class is fully generic and reusable across projects.
 */
final class RegexParser implements PromptParserInterface
{
    /** @var array<string,string> logical entity => regex; primary entities win over containers used as filters. */
    private array $primaryEntities = [
        'students' => 'طالب|طلاب|student|students',
        'teachers' => 'مدرس|مدرسون|معلم|معلمين|teacher|teachers',
        'parents'  => 'ولي أمر|أولياء|parent|parents',
    ];

    /** @var string[] collections that carry a class_id and support class filtering. */
    private array $classFilterable = ['students', 'attendance', 'incidents', 'merits'];

    public function __construct(
        private readonly MappingRepositoryInterface $mappings,
        private readonly ?EntityResolverInterface $entityResolver = null,
    ) {}

    public function source(): string
    {
        return 'regex';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function parse(string $normalized, string $original): ?IntermediateQuery
    {
        [$collection, $defaultProjection, $textColumn] = $this->matchSection($normalized);
        if ($collection === null) {
            return null;
        }

        $filters = [];
        $limit   = 20;

        $requested  = $this->detectRequestedColumns($normalized, $collection);
        $projection = $requested !== [] ? $requested : $defaultProjection;

        $this->applyStatusFilters($normalized, $collection, $filters);
        $this->applyDateFilters($normalized, $filters);
        $this->applyClassFilter($original, $collection, $filters);

        if (preg_match('/(\d+)\s*(حد أقصى|records|سجلات|rows)/u', $normalized, $m)) {
            $limit = (int) $m[1];
        }

        $this->applyTextFilter($original, $textColumn, $filters);

        return new IntermediateQuery(
            module:     $collection,
            operation:  $this->detectOperation($normalized),
            filters:    $filters,
            limit:      $limit,
            projection: $projection,
            source:     'regex',
        );
    }

    /** @return array{0:?string,1:array,2:?string} [collection, projection, textColumn] */
    private function matchSection(string $q): array
    {
        $mappings = $this->mappings->sectionMappings();

        $primary = $this->matchPrimaryEntity($q, $mappings);
        if ($primary !== null) {
            return $primary;
        }

        foreach ($mappings as $map) {
            if (!empty($map['patterns']) && preg_match('/(' . $map['patterns'] . ')/u', $q)) {
                return [$map['collection'], $map['projection'] ?? [], $map['text_column'] ?? null];
            }
        }
        return [null, [], null];
    }

    /** @return array{0:string,1:array,2:?string}|null */
    private function matchPrimaryEntity(string $q, array $mappings): ?array
    {
        foreach ($this->primaryEntities as $collection => $pattern) {
            if (preg_match('/(' . $pattern . ')/u', $q)) {
                foreach ($mappings as $map) {
                    if (($map['collection'] ?? null) === $collection) {
                        return [$collection, $map['projection'] ?? [], $map['text_column'] ?? null];
                    }
                }
            }
        }
        return null;
    }

    private function detectOperation(string $q): string
    {
        return preg_match('/عدد|count|كم|إجمالي|عددهم/u', $q) ? 'count' : 'list';
    }

    private function applyStatusFilters(string $q, string $collection, array &$filters): void
    {
        if (preg_match('/مقبول|accepted|approved/u', $q)) {
            $filters[] = ['field' => 'admission_status', 'operator' => '=', 'value' => 1];
        }
        if (preg_match('/رفض|rejected|refused/u', $q)) {
            $filters[] = ['field' => 'admission_status', 'operator' => '=', 'value' => 0];
        }
        if (preg_match('/معلق|pending|قيد|في الانتظار|انتظار/u', $q)) {
            $filters[] = ['field' => 'status', 'operator' => '=', 'value' => 'pending'];
        }

        if ($collection === 'invoices') {
            if (preg_match('/غير مدفوع|غير المدفوع|لم تدفع|لم تُدفع|unpaid|outstanding|متبقي|متبقية/u', $q)) {
                $filters[] = ['field' => 'status', 'operator' => '!=', 'value' => 'paid'];
            } elseif (preg_match('/مدفوع|مسدد|مسددة|paid/u', $q)) {
                $filters[] = ['field' => 'status', 'operator' => '=', 'value' => 'paid'];
            }
        }
    }

    private function applyClassFilter(string $original, string $collection, array &$filters): void
    {
        if ($this->entityResolver === null || !in_array($collection, $this->classFilterable, true)) {
            return;
        }

        $className = $this->extractClassName($original);
        if ($className === null) {
            return;
        }

        $classId = $this->entityResolver->resolveId('class', $className);
        if ($classId !== null) {
            $filters[] = ['field' => 'class_id', 'operator' => '=', 'value' => $classId];
        }
    }

    private function extractClassName(string $question): ?string
    {
        if (preg_match('/(?:في\s+)?(?:ال)?(?:صف|فصل|class)\s+(.+)$/u', $question, $m)) {
            $name = trim($m[1]);
            $name = preg_replace('/\s*[؟?]$/u', '', $name);
            $name = preg_replace('/\s+(فقط|من فضلك|لو سمحت)$/u', '', $name);
            return $name !== '' ? $name : null;
        }
        return null;
    }

    private function applyDateFilters(string $q, array &$filters): void
    {
        if (preg_match('/هذا الشهر|this month|الشهر الحالي/u', $q)) {
            $now = now();
            $filters[] = ['field' => 'created_at', 'operator' => '>=', 'value' => $now->copy()->startOfMonth()];
            $filters[] = ['field' => 'created_at', 'operator' => '<=', 'value' => $now->copy()->endOfMonth()];
        }
        if (preg_match('/أسبوع|week|هذا الأسبوع/u', $q)) {
            $now = now();
            $filters[] = ['field' => 'created_at', 'operator' => '>=', 'value' => $now->copy()->startOfWeek()];
            $filters[] = ['field' => 'created_at', 'operator' => '<=', 'value' => $now->copy()->endOfWeek()];
        }
        if (preg_match('/اليوم|today/u', $q)) {
            $now = now();
            $filters[] = ['field' => 'created_at', 'operator' => '>=', 'value' => $now->copy()->startOfDay()];
            $filters[] = ['field' => 'created_at', 'operator' => '<=', 'value' => $now->copy()->endOfDay()];
        }
    }

    private function applyTextFilter(string $original, ?string $textColumn, array &$filters): void
    {
        if (!$textColumn) {
            return;
        }
        $title = $this->extractTitle($original);
        if ($title !== null && mb_strlen($title) >= 2) {
            $filters[] = ['field' => $textColumn, 'operator' => 'like', 'value' => $title];
        }
    }

    private function extractTitle(string $question): ?string
    {
        if (preg_match('/["«\'](.+?)["»\']/u', $question, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:كتاب|مادة|رحلة|بعنوان|اسمه|اسمها|باسم|بإسم|اسم الطالب|عنوان)\s+(.+)$/u', $question, $m)) {
            $tail = trim($m[1]);
            $tail = preg_replace('/\s*[؟?]$/u', '', $tail);
            $tail = preg_replace('/\s+(فقط|من فضلك|لو سمحت)$/u', '', $tail);
            return $tail !== '' ? $tail : null;
        }
        return null;
    }

    private function detectRequestedColumns(string $q, string $collection): array
    {
        $columns = [];
        foreach ($this->mappings->columnConcepts() as $concept) {
            $pattern   = $concept['patterns'] ?? '';
            $perModule = $concept['columns_per_collection'] ?? [];

            if ($pattern !== '' && preg_match('/(' . $pattern . ')/u', $q) && !empty($perModule[$collection])) {
                foreach ($perModule[$collection] as $col) {
                    if (!in_array($col, $columns, true)) {
                        $columns[] = $col;
                    }
                }
            }
        }
        return $columns;
    }
}
