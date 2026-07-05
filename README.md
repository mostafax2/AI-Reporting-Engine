# AI Reporting Engine

Turn natural-language prompts into report queries via a unified **Regex + OpenAI** pipeline, with prompt normalization, fingerprint caching, and a pluggable DSL builder.

The engine produces a **DSL only** — execution is delegated to
[`mostafax/dynamic-hybrid-reporting-engine`](https://packagist.org/packages/mostafax/dynamic-hybrid-reporting-engine),
fed by data replicated through
[`mostafax/dual-layer-reporting-engine`](https://packagist.org/packages/mostafax/dual-layer-reporting-engine).

## Pipeline

```
prompt
  → PromptNormalizer          (trim, lowercase, Arabic, punctuation) + fingerprint
  → PromptCache lookup        (hit → return stored DSL, skip parsing)
  → RegexParser               (token-free) ─┐
  → OpenAiParser              (on regex miss)┘→ IntermediateQuery (unified)
  → DslBuilder                (the only place that builds DSL)
  → PromptCache store         (intent + DSL, never rows)
  → ReportEngine.run(dsl)     (unchanged executor — dynamic-hybrid-reporting-engine)
```

Every parser returns the same `IntermediateQuery`; the rest of the system never
knows whether the source was regex or AI.

## Install

```bash
composer require mostafax/ai-reporting-engine
php artisan vendor:publish --tag=ai-reporting-config
```

Both reporting packages are hard requirements and are pulled in automatically.

## Usage

```php
use Mostafax\AiReportingEngine\ReportQueryPipeline;
use Mostafax\ReportingEngine\Core\Engine\ReportEngine;

public function search(Request $request, ReportQueryPipeline $pipeline, ReportEngine $engine)
{
    $result = $pipeline->process($request->string('question'));
    // $result: ['source'=>'regex|openai|cache', 'collection'=>..., 'query'=>..., 'dsl'=>..., 'cached'=>bool]

    $rows = $engine->run($result['dsl']);   // execute via the reporting engine
    return response()->json(['data' => $rows->data, 'source' => $result['source']]);
}
```

## Extending (SOLID)

Every collaborator is an interface — bind your own in a service provider:

| Interface | Default | Bind to override |
|-----------|---------|------------------|
| `PromptNormalizerInterface` | `PromptNormalizer` | custom normalization |
| `AiConfigInterface` | `ConfigAiConfig` (config) | DB-backed settings |
| `PromptCacheInterface` | `MongoPromptCache` | Redis/SQL cache |
| `MappingRepositoryInterface` | `MongoMappingRepository` | your mapping store |
| `EntityResolverInterface` | *(none)* | resolve `class` name → id, etc. |

```php
$this->app->bind(EntityResolverInterface::class, MyClassResolver::class);
```

## Parse method

Configure via `ai-reporting.parse_method`: `auto` (regex → OpenAI), `regex`
(no tokens), or `openai`.

## Cache storage

Each entry keeps: original prompt, normalized prompt, fingerprint, DSL, parser
source, `created_at`, `last_used_at`, `usage_count` — never result rows.

## License

MIT © Mostafa Elbayyar
