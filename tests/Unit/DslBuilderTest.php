<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine\Tests\Unit;

use Mostafax\AiReportingEngine\DslBuilder;
use Mostafax\AiReportingEngine\Exceptions\ReportParsingException;
use Mostafax\AiReportingEngine\Support\IntermediateQuery;
use PHPUnit\Framework\TestCase;

final class DslBuilderTest extends TestCase
{
    private DslBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DslBuilder();
    }

    public function test_it_builds_a_mongodb_dsl(): void
    {
        $dsl = $this->builder->build(new IntermediateQuery(
            module: 'students',
            projection: ['first_name_ar', 'status'],
            limit: 20,
        ));

        $this->assertSame('mongodb', $dsl['source']);
        $this->assertSame('students', $dsl['table']);
        $this->assertSame(['first_name_ar', 'status'], $dsl['fields']);
    }

    public function test_it_wraps_filters_in_a_filter_group(): void
    {
        $dsl = $this->builder->build(new IntermediateQuery(
            module: 'invoices',
            filters: [['field' => 'status', 'operator' => '!=', 'value' => 'paid']],
        ));

        $this->assertSame('AND', $dsl['filters']['operator']);
        $this->assertSame('status', $dsl['filters']['conditions'][0]['column']);
    }

    public function test_it_wraps_like_values(): void
    {
        $dsl = $this->builder->build(new IntermediateQuery(
            module: 'books',
            filters: [['field' => 'title_ar', 'operator' => 'like', 'value' => 'مقدمة']],
        ));

        $this->assertSame('%مقدمة%', $dsl['filters']['conditions'][0]['value']);
    }

    public function test_it_rejects_an_invalid_query(): void
    {
        $this->expectException(ReportParsingException::class);
        $this->builder->build(new IntermediateQuery(module: ''));
    }
}
