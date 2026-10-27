<?php
/**
 * AI API設定ファイル
 *
 * Renderではこのファイルは不要です。OPENAI_API_KEY を環境変数に入れてください。
 * Geminiは使いません。
 */

return [
    'api_priority' => ['openai'],

    'groq' => [
        'enabled' => false,
        'api_key' => '',
        'model' => 'llama-3.1-8b-instant',
        'base_url' => 'https://api.groq.com/openai/v1',
    ],

    'openai' => [
        'enabled' => true,
        'api_key' => '',
        'model' => 'gpt-4o-mini',
        'base_url' => 'https://api.openai.com/v1',
    ],

    'gemini' => [
        'enabled' => false,
        'api_key' => '',
        'model' => 'gemini-1.5-flash',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],

    'ollama' => [
        'enabled' => false,
        'production_url' => '',
        'production_model' => 'llama3',
        'api_key' => '',
    ],

    'huggingface' => [
        'enabled' => false,
    ],

    'timeout' => 120,
    'connect_timeout' => 15,
    'local_url' => 'http://localhost:11434',
];
