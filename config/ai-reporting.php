<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    */
    'enabled' => env('AI_REPORTING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Parsing engine
    |--------------------------------------------------------------------------
    | 'auto'   → regex first, OpenAI on miss (token-efficient, recommended)
    | 'regex'  → local regex only (no tokens, no network)
    | 'openai' → OpenAI only
    */
    'parse_method' => env('AI_REPORTING_METHOD', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        // The system prompt describing the available collections/columns.
        // Override in the host app to teach the model your schema.
        'schema_prompt' => env('AI_REPORTING_SCHEMA_PROMPT', 'Return a report query as JSON: {"collection":"","filters":[{"field":"","operator":"","value":""}],"projection":[],"limit":20}. Operators: =, like, >, <, >=, <=, !=.'),
        'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage (MongoDB)
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'connection' => env('AI_REPORTING_MONGO_CONNECTION', 'mongodb'),
        'cache_collection'    => 'ai_queries',
        'mappings_collection' => 'ai_report_mappings',
        'concepts_collection' => 'ai_column_concepts',
        'cache_ttl_hours'     => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | DSL output target
    |--------------------------------------------------------------------------
    | The produced DSL is executed by mostafax/dynamic-hybrid-reporting-engine.
    */
    'dsl' => [
        'source'     => 'mongodb',
        'connection' => 'mongodb',
    ],

];
