<?php

declare(strict_types=1);

function requireLocalDevelopmentServer(): void
{
    if (PHP_SAPI !== 'cli-server'
        || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || !in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1:8000', 'localhost:8000', '[::1]:8000'], true)) {
        http_response_code(403);
        exit('Nur für die lokale Entwicklung auf Port 8000.');
    }
}
