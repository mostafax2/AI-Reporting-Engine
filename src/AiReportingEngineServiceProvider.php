<?php

declare(strict_types=1);

namespace Mostafax\AiReportingEngine;

use Illuminate\Support\ServiceProvider;
use Mostafax\AiReportingEngine\Contracts\AiConfigInterface;
use Mostafax\AiReportingEngine\Contracts\EntityResolverInterface;
use Mostafax\AiReportingEngine\Contracts\MappingRepositoryInterface;
use Mostafax\AiReportingEngine\Contracts\PromptCacheInterface;
use Mostafax\AiReportingEngine\Contracts\PromptNormalizerInterface;
use Mostafax\AiReportingEngine\Parsers\OpenAiParser;
use Mostafax\AiReportingEngine\Parsers\RegexParser;
use Mostafax\AiReportingEngine\Storage\MongoMappingRepository;
use Mostafax\AiReportingEngine\Storage\MongoPromptCache;
use Mostafax\AiReportingEngine\Support\ConfigAiConfig;
use Mostafax\AiReportingEngine\Support\PromptNormalizer;

final class AiReportingEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-reporting.php', 'ai-reporting');

        $this->registerContracts();
        $this->registerStorage();
        $this->registerParsers();
        $this->registerPipeline();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ai-reporting.php' => config_path('ai-reporting.php'),
            ], 'ai-reporting-config');
        }
    }

    private function registerContracts(): void
    {
        // Bind default implementations; hosts may override any of these bindings.
        $this->app->singleton(PromptNormalizerInterface::class, PromptNormalizer::class);
        $this->app->singleton(AiConfigInterface::class, ConfigAiConfig::class);

        // No entity resolver by default — the host binds one to enable class_id
        // (or similar) lookups. Absence degrades RegexParser gracefully.
        $this->app->bindIf(EntityResolverInterface::class, fn() => null);
    }

    private function registerStorage(): void
    {
        $this->app->singleton(PromptCacheInterface::class, function ($app) {
            $c = $app['config']->get('ai-reporting.storage');
            return new MongoPromptCache(
                connection: $c['connection'] ?? 'mongodb',
                collection: $c['cache_collection'] ?? 'ai_queries',
                ttlHours:   (int) ($c['cache_ttl_hours'] ?? 24),
            );
        });

        $this->app->singleton(MappingRepositoryInterface::class, function ($app) {
            $c = $app['config']->get('ai-reporting.storage');
            return new MongoMappingRepository(
                connection:         $c['connection'] ?? 'mongodb',
                sectionsCollection: $c['mappings_collection'] ?? 'ai_report_mappings',
                conceptsCollection: $c['concepts_collection'] ?? 'ai_column_concepts',
            );
        });
    }

    private function registerParsers(): void
    {
        $this->app->singleton(RegexParser::class, function ($app) {
            return new RegexParser(
                $app->make(MappingRepositoryInterface::class),
                $app->make(EntityResolverInterface::class),
            );
        });

        $this->app->singleton(OpenAiParser::class, function ($app) {
            $openai = $app['config']->get('ai-reporting.openai');
            return new OpenAiParser(
                $app->make(AiConfigInterface::class),
                (string) ($openai['schema_prompt'] ?? ''),
                (string) ($openai['endpoint'] ?? 'https://api.openai.com/v1/chat/completions'),
            );
        });
    }

    private function registerPipeline(): void
    {
        $this->app->singleton(DslBuilder::class, function ($app) {
            $dsl = $app['config']->get('ai-reporting.dsl');
            return new DslBuilder(
                source:     $dsl['source'] ?? 'mongodb',
                connection: $dsl['connection'] ?? 'mongodb',
            );
        });

        $this->app->singleton(ReportQueryPipeline::class, function ($app) {
            return new ReportQueryPipeline(
                $app->make(PromptNormalizerInterface::class),
                $app->make(PromptCacheInterface::class),
                $app->make(RegexParser::class),
                $app->make(OpenAiParser::class),
                $app->make(DslBuilder::class),
                $app->make(AiConfigInterface::class),
            );
        });
    }
}
