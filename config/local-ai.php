<?php

return [
    // Deliberately not configurable from a request or database setting. The
    // model endpoint is loopback-only so document content cannot be exfiltrated.
    'ollama_url' => 'http://127.0.0.1:11434',
    'default_model' => env('LOCAL_AI_MODEL', 'qwen3:8b-q4_K_M'),
    'fallback_model' => env('LOCAL_AI_FALLBACK_MODEL', 'qwen3:4b-instruct-2507-q4_K_M'),
    'context_size' => (int) env('LOCAL_AI_CONTEXT_SIZE', 8192),
    'max_pages' => (int) env('LOCAL_AI_MAX_PAGES', 300),
    'max_file_bytes' => 25 * 1024 * 1024,
    'instruction_version' => 'requirements-v1',
    'chunk_min_chars' => 12000,
    'chunk_max_chars' => 18000,
    'chunk_overlap_chars' => 1000,
    'keep_alive' => '5m',
    'commands' => [
        'pdfinfo' => env('PDFINFO_BINARY', 'pdfinfo'),
        'pdftotext' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'tesseract' => env('TESSERACT_BINARY', 'tesseract'),
    ],
];
