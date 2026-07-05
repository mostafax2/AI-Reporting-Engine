<div align="center">

# 🤖 AI Reporting Engine

**Turn natural-language prompts into report queries — securely, cheaply, and deterministically.**

Natural language → normalized prompt → cache → regex → OpenAI → **unified intent** → validated **DSL** → execution.
The AI never touches your database; it only proposes an *intent*.

📖 **[Documentation](https://mostafax2.github.io/AI-Reporting-Engine/)** · [العربية](https://mostafax2.github.io/AI-Reporting-Engine/ar.html)

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10--13-ff2d20)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

</div>

---

## Table of Contents

- [Overview](#-overview)
- [The Hybrid Intent-to-DSL Pattern](#-the-hybrid-intent-to-dsl-pattern)
- [Feature Summary](#-feature-summary)
- [Requirements](#-requirements)
- [Quick Install](#-quick-install)
- [Detailed Setup](#-detailed-setup)
- [The Pipeline](#-the-pipeline)
- [Usage](#-usage)
- [The Unified Intent Object](#-the-unified-intent-object)
- [Parsers](#-parsers)
- [Prompt Normalization](#-prompt-normalization)
- [Cache Storage](#-cache-storage)
- [DSL Builder](#-dsl-builder)
- [Configuration](#-configuration)
- [Extending (SOLID)](#-extending-solid)
- [Data Stores (MongoDB)](#-data-stores-mongodb)
- [Logging](#-logging)
- [Error Handling](#-error-handling)
- [Testing](#-testing)
- [Architecture Reference](#-architecture-reference)
- [License](#-license)

---

## 🌟 Overview

**AI Reporting Engine** converts a natural-language question (Arabic or English) into an executable **reporting DSL** through a layered, cache-first pipeline. Unlike naive "prompt → SQL" approaches, the AI is **only ever allowed to produce a structured intent** — never a query, never SQL, never DSL. A dedicated, centralized `DslBuilder` is the single component that turns that validated intent into an executable DSL.

The engine produces a **DSL only**. Execution is delegated to
[`mostafax/dynamic-hybrid-reporting-engine`](https://packagist.org/packages/mostafax/dynamic-hybrid-reporting-engine),
fed by data replicated through
[`mostafax/dual-layer-reporting-engine`](https://packagist.org/packages/mostafax/dual-layer-reporting-engine).
Both are hard requirements and are pulled in automatically.

> **Why not just prompt → SQL?** Because it burns tokens on every request, reprocesses identical questions, lets the model hallucinate invalid or unsafe queries, and couples business rules to the AI. This package fixes all five.

---

## 🧠 The Hybrid Intent-to-DSL Pattern

This package is a concrete implementation of the **Hybrid Intent-to-DSL Reporting Pattern** by Mostafa Elbayyar.

**Core principles**

1. **AI never generates executable queries** — it produces a validated *intent* only.
2. **Intent before execution** — parsing and execution are fully separated.
3. **Cache first** — identical questions never re-hit the AI.
4. **Rules before AI** — a deterministic regex engine runs before any token is spent.
5. **Validation before execution** — no invalid intent or DSL ever reaches the database.

**Data flow**

```
Prompt → Normalized Prompt → Intent → Validated Intent → DSL → Validated DSL → Execution
```

---

## 🎯 Feature Summary

| Feature | Description |
|---|---|
| 🧹 **Prompt Normalization** | Trim, lowercase, Arabic letter unification, tashkeel & punctuation removal — then a stable `sha256` fingerprint |
| 🔑 **Fingerprint Cache** | Equivalent prompts share one fingerprint; a repeated question skips parsing entirely and costs **zero tokens** |
| ⚙️ **Regex Parser** | Deterministic, token-free. Matches editable MongoDB mappings & column concepts → a unified intent |
| 🧠 **OpenAI Parser** | Runs **only** when regex misses. Returns strict JSON — never DSL, never SQL. Tracks latency + token usage |
| 🎯 **Unified Intent Object** | Both parsers emit the identical `IntermediateQuery`; the rest of the system is source-agnostic |
| 🏗️ **Central DSL Builder** | The **single** place that builds an executable DSL. No parser ever builds DSL directly |
| 🔀 **Method Switch** | `auto` (regex → OpenAI), `regex` (no tokens), or `openai` — runtime-configurable |
| 🍃 **MongoDB Stores** | Editable regex mappings, column concepts, and the prompt/DSL cache — all runtime-editable |
| 🧩 **Pluggable Resolvers** | Resolve entity names ("class 1-A") to ids for filtering, via a swappable `EntityResolverInterface` |
| 🔌 **SOLID / DI** | Every collaborator sits behind an interface — swap any piece via a container binding |
| 🌐 **Arabic-aware** | First-class Arabic normalization so "عدد الطلاب" and "عدد الطُلّاب" hit the same cache entry |
| 📊 **Execution Logging** | Parser used, cache hit, execution ms, OpenAI latency, token usage, and the generated DSL |

---

## 📋 Requirements

### Required

| Dependency | Version |
|---|---|
| PHP | `8.1+` |
| Laravel | `10 / 11 / 12 / 13` |
| `mostafax/dynamic-hybrid-reporting-engine` | `dev-main` (executes the DSL) |
| `mostafax/dual-layer-reporting-engine` | `dev-main` (replicates data for reporting) |

### Suggested

| Package | Purpose |
|---|---|
| `mongodb/laravel-mongodb` | MongoDB-backed mapping & prompt-cache stores (`^4.0 \| ^5.0`) |

---

## ⚡ Quick Install

```bash
# 1. Install the package (both reporting engines are pulled in automatically)
composer require mostafax/ai-reporting-engine

# 2. Publish the config
php artisan vendor:publish --tag=ai-reporting-config
```

**Done.** Inject `ReportQueryPipeline`, call `process($prompt)`, and hand the returned DSL to the reporting engine.

---

## 🔧 Detailed Setup

### Step 1 — Install

```bash
composer require mostafax/ai-reporting-engine
```

The service provider is auto-discovered (`AiReportingEngineServiceProvider`) and binds every interface to a sensible default.

### Step 2 — Publish config

```bash
php artisan vendor:publish --tag=ai-reporting-config
# → config/ai-reporting.php
```

### Step 3 — Environment variables

```dotenv
AI_REPORTING_ENABLED=true
AI_REPORTING_METHOD=auto          # auto | regex | openai

OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-4o-mini
OPENAI_TIMEOUT=30

AI_REPORTING_MONGO_CONNECTION=mongodb
```

### Step 4 — Seed the regex mappings (host-defined)

The regex parser reads section mappings and column concepts from MongoDB
(`ai_report_mappings`, `ai_column_concepts`). Seed them once from your domain
schema, then edit them at runtime without a deploy.

### Step 5 — (Optional) Bind host implementations

To enable entity lookups (e.g. class-name → `class_id`) or a DB-backed AI config,
bind your own implementations — see [Extending](#-extending-solid).

---

## 🔀 The Pipeline

Every prompt passes through the same 7-step pipeline before a single DB call:

| # | Step | Component | Responsibility |
|---|------|-----------|----------------|
| 01 | Normalize | `PromptNormalizer` | Canonicalize text (Arabic, case, punctuation) → `fingerprint` |
| 02 | Cache Lookup | `MongoPromptCache` | Hit → return stored DSL, **skip** regex + OpenAI |
| 03 | Regex | `RegexParser` | Token-free match → `IntermediateQuery` |
| 04 | OpenAI | `OpenAiParser` | On regex miss → strict JSON → `IntermediateQuery` |
| 05 | Build DSL | `DslBuilder` | Validate + map + build the executable DSL |
| 06 | Cache Store | `MongoPromptCache` | Persist intent + DSL (never rows) |
| 07 | Execute | `ReportEngine` | Run the DSL — the **unchanged** reporting engine |

`ReportQueryPipeline` orchestrates steps 1–6 and returns the DSL; step 7 is your call.

---

## 🚀 Usage

```php
use Mostafax\AiReportingEngine\ReportQueryPipeline;
use Mostafax\ReportingEngine\Core\Engine\ReportEngine;

public function search(Request $request, ReportQueryPipeline $pipeline, ReportEngine $engine)
{
    $result = $pipeline->process($request->string('question'));
    // [
    //   'source'     => 'regex' | 'openai' | 'cache',
    //   'origin'     => the engine that first produced it,
    //   'collection' => 'students',
    //   'query'      => [...unified intent...],
    //   'dsl'        => [...executable DSL...],
    //   'cached'     => bool,
    // ]

    $rows = $engine->run($result['dsl']);   // execute via the reporting engine

    return response()->json([
        'data'   => $rows->data,
        'source' => $result['source'],
    ]);
}
```

Unrecognized prompts throw `ReportParsingException` — catch it and return a helpful message.

---

## 🎯 The Unified Intent Object

Both parsers return the identical `IntermediateQuery`. The DSL builder and the rest
of the app never know whether the source was regex or AI — only the parser sets `_source`.

```json
{
  "module":     "students",
  "operation":  "list",
  "metrics":    [],
  "dimensions": [],
  "filters":    [ { "field": "class_id", "operator": "=", "value": 1 } ],
  "groupBy":    [],
  "sort":       [],
  "limit":      20,
  "dateRange":  null,
  "projection": ["first_name_ar", "status"],
  "_source":    "regex"
}
```

---

## ⚙️ Parsers

### RegexParser (deterministic, token-free)

- Matches section patterns and column concepts stored in MongoDB.
- Prefers the **primary entity** (students/teachers/parents) over a container mentioned only as a filter — so *"students in class 1-A"* resolves to `students` filtered by `class_id`, not the classes report.
- Extracts status filters (accepted, unpaid…), relative dates (today, this month…), free-text titles ("book *X*"), and explicit column requests ("names only").
- Resolves entity names to ids via a pluggable `EntityResolverInterface`.

### OpenAiParser (fallback only)

- Runs **only** when regex cannot match — keeping token cost near zero.
- Returns strict JSON matching the intent shape; never DSL, never SQL.
- Exposes `lastLatencyMs()` and `lastTokens()` for the execution log.
- The system/schema prompt is host-provided via config, so the package stays domain-agnostic.

### Parse method

| Method | Behavior |
|--------|----------|
| `auto` | Regex first, OpenAI on miss — **recommended** |
| `regex` | Local regex only — no tokens, no network |
| `openai` | OpenAI only |

---

## 🧹 Prompt Normalization

`PromptNormalizer` canonicalizes prompts so equivalent phrasings share one cache entry:

| Step | Effect |
|------|--------|
| Trim + collapse spaces | `"  عدد   الطلاب "` → `"عدد الطلاب"` |
| Arabic letter unify | `أ إ آ → ا`, `ى → ي`, `ة → ه`, `ؤ → و`, `ئ → ي` |
| Strip tashkeel & tatweel | `"الطُّلَّاب"` → `"الطلاب"` |
| Lowercase | Latin case-folding |
| Strip punctuation | keeps letters, digits, spaces |
| Fingerprint | `sha256(normalized)` — the cache key |

---

## 💾 Cache Storage

Each cache entry keeps the parsed **intent only** — never result rows (data changes over time; rows are always fetched fresh).

| Field | Description |
|-------|-------------|
| `question` | Original prompt |
| `normalized_prompt` | Canonicalized prompt |
| `question_hash` | `sha256` fingerprint (the key) |
| `collection` | Resolved module |
| `source` | `regex` \| `openai` \| `cache` |
| `query` | The unified intent |
| `dsl` | The generated DSL |
| `created_at` | First seen |
| `last_used_at` | Last hit |
| `usage_count` | Hit counter |

Default TTL is 24 hours (configurable).

---

## 🏗️ DSL Builder

The single, centralized place that turns a validated `IntermediateQuery` into a
ReportingEngine DSL. **No parser builds DSL directly.**

- Rejects an invalid intent with `ReportParsingException`.
- Keeps only flat (non-relation) columns for MongoDB documents.
- Wraps filters in a `FilterGroup` (`{operator, conditions}`) so the DSL is
  **directly executable** by the reporting engine.
- Wraps `like` values in `%…%`.

```php
[
  'source'     => 'mongodb',
  'connection' => 'mongodb',
  'table'      => 'invoices',
  'pagination' => ['page' => 1, 'per_page' => 20],
  'fields'     => ['invoice_number', 'status'],
  'filters'    => ['operator' => 'AND', 'conditions' => [
      ['column' => 'status', 'operator' => '!=', 'value' => 'paid'],
  ]],
]
```

---

## 🔧 Configuration

`config/ai-reporting.php`:

```php
return [
    'enabled'      => env('AI_REPORTING_ENABLED', true),
    'parse_method' => env('AI_REPORTING_METHOD', 'auto'), // auto | regex | openai

    'openai' => [
        'api_key'       => env('OPENAI_API_KEY', ''),
        'model'         => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout'       => (int) env('OPENAI_TIMEOUT', 30),
        'schema_prompt' => '…your collections + columns…',
        'endpoint'      => 'https://api.openai.com/v1/chat/completions',
    ],

    'storage' => [
        'connection'          => env('AI_REPORTING_MONGO_CONNECTION', 'mongodb'),
        'cache_collection'    => 'ai_queries',
        'mappings_collection' => 'ai_report_mappings',
        'concepts_collection' => 'ai_column_concepts',
        'cache_ttl_hours'     => 24,
    ],

    'dsl' => [
        'source'     => 'mongodb',
        'connection' => 'mongodb',
    ],
];
```

---

## 🔌 Extending (SOLID)

Every collaborator is an interface — bind your own in a service provider:

| Interface | Default | Bind to override |
|-----------|---------|------------------|
| `PromptNormalizerInterface` | `PromptNormalizer` | custom normalization |
| `AiConfigInterface` | `ConfigAiConfig` (config) | DB-backed settings |
| `PromptCacheInterface` | `MongoPromptCache` | Redis / SQL cache |
| `MappingRepositoryInterface` | `MongoMappingRepository` | your mapping store |
| `EntityResolverInterface` | *(none)* | resolve `class` name → id, etc. |
| `PromptParserInterface` | `RegexParser`, `OpenAiParser` | add a custom parser |

```php
// Enable class-name → class_id lookups
$this->app->bind(EntityResolverInterface::class, MyClassResolver::class);

// Read AI settings from your own store
$this->app->singleton(AiConfigInterface::class, SystemSettingAiConfig::class);
```

---

## 🍃 Data Stores (MongoDB)

Three collections back the engine (all runtime-editable):

| Collection | Purpose |
|------------|---------|
| `ai_report_mappings` | Section patterns + default projection + text-search column per module |
| `ai_column_concepts` | Keyword → columns-per-module (e.g. "name" → `first_name_ar`, `family_name_ar`) |
| `ai_queries` | Prompt/DSL cache (intent only) |

**Section mapping**

```json
{
  "collection": "students",
  "patterns": "طالب|طلاب|student|students",
  "projection": ["student_code", "first_name_ar", "status"],
  "text_column": "first_name_ar",
  "priority": 150,
  "is_active": true
}
```

**Column concept**

```json
{
  "label": "name",
  "patterns": "اسم|أسماء|name",
  "columns_per_collection": {
    "students": ["first_name_ar", "family_name_ar"],
    "teachers": ["name_ar"]
  },
  "is_active": true
}
```

---

## 📊 Logging

Every generated query is logged with:

```
parser · cache_hit · execution_ms · openai_ms · openai_tokens · dsl
```

Use it to monitor token spend, cache-hit ratio, and slow prompts.

---

## 🛡️ Error Handling

- **No match** (regex miss + AI unavailable/disabled) → `ReportParsingException`.
- **Invalid intent / DSL** → `ReportParsingException` from `DslBuilder`.
- **OpenAI failure / invalid JSON** → logged, returns `null`, pipeline falls back per method.
- **Cache/store failure** → logged, never breaks generation.

An invalid report is **never** executed.

---

## 🧪 Testing

```bash
composer install
vendor/bin/phpunit
```

Unit tests cover the DSL builder (filter groups, `like` wrapping, validation) and
can be extended with your own parser and normalization cases.

---

## 📐 Architecture Reference

```
                User Prompt
                     │
                     ▼
          Prompt Normalization           (PromptNormalizer)
                     │
                     ▼
          Intent Cache Lookup            (MongoPromptCache)
        ┌────────────┴────────────┐
     Cache Hit                 Cache Miss
        │                         │
        ▼                         ▼
 Return Stored DSL          Regex Rule Engine   (RegexParser)
                                  │
                     ┌────────────┴────────────┐
                 Match Found              No Match
                     │                         │
                     ▼                         ▼
             Intent Object          OpenAI Intent Parser (OpenAiParser)
                     └────────────┬────────────┘
                                  ▼
                          DSL Builder            (DslBuilder — validates)
                                  ▼
                          Cache Storage          (MongoPromptCache)
                                  ▼
                        Report Execution          (ReportEngine — unchanged)
```

---

## 📄 License

MIT © Mostafa Elbayyar
