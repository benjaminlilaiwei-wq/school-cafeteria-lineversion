<?php

function app_env($name) {
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    if (isset($_ENV[$name]) && trim((string) $_ENV[$name]) !== '') {
        return trim((string) $_ENV[$name]);
    }
    if (isset($_SERVER[$name]) && trim((string) $_SERVER[$name]) !== '') {
        return trim((string) $_SERVER[$name]);
    }
    return '';
}

function apply_openai_env($config) {
    if (!is_array($config)) {
        $config = [];
    }
    $key = app_env('OPENAI_API_KEY');
    if ($key === '') {
        return $config;
    }
    if (!isset($config['openai']) || !is_array($config['openai'])) {
        $config['openai'] = [];
    }
    $config['openai']['enabled'] = true;
    $config['openai']['api_key'] = $key;
    if (empty($config['openai']['model'])) {
        $config['openai']['model'] = 'gpt-3.5-turbo';
    }
    if (empty($config['openai']['base_url'])) {
        $config['openai']['base_url'] = 'https://api.openai.com/v1';
    }
    if (!isset($config['gemini']) || !is_array($config['gemini'])) {
        $config['gemini'] = [];
    }
    $config['gemini']['enabled'] = false;
    $config['gemini']['api_key'] = '';
    return $config;
}
