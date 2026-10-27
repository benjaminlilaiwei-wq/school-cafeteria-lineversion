<?php
/**
 * AI API設定ファイル
 *
 * このファイルを api/ollama-config.php にコピーし、使うAPIのキーだけを入れてください。
 * 本物のキーはGitHubに置かないでください。
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
        'model' => 'gpt-3.5-turbo',
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
