<?php

declare(strict_types=1);

const MAX_JSON_BODY_BYTES = 1048576;

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function json_response(array $data, int $status = 200): void
{
    send_security_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text($value, int $maxLength = 255): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function get_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function basic_rate_limit(string $bucket = 'default', int $limit = 20, int $windowSeconds = 60): void
{
    $dir = sys_get_temp_dir() . '/petlio-rate-limit';

    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $key = hash('sha256', $bucket . '|' . get_client_ip());
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];

    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);

        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }

    if (($now - (int) $state['start']) >= $windowSeconds) {
        $state = ['start' => $now, 'count' => 0];
    }

    $state['count'] = (int) $state['count'] + 1;
    file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['count'] > $limit) {
        json_response(['message' => 'Слишком много запросов. Попробуйте позже.'], 429);
    }
}

function require_json_request(): array
{
    send_security_headers();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['message' => 'Method not allowed'], 405);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (!str_contains(strtolower($contentType), 'application/json')) {
        json_response(['message' => 'Ожидается JSON-запрос.'], 415);
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > MAX_JSON_BODY_BYTES) {
        json_response(['message' => 'Слишком большой запрос.'], 413);
    }

    $rawBody = file_get_contents('php://input') ?: '';

    if (strlen($rawBody) > MAX_JSON_BODY_BYTES) {
        json_response(['message' => 'Слишком большой запрос.'], 413);
    }

    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        json_response(['message' => 'Некорректный JSON.'], 400);
    }

    return $data;
}

function handle_endpoint_error(Throwable $error): void
{
    error_log($error->getMessage());
    json_response(['message' => 'Внутренняя ошибка сервера. Попробуйте позже.'], 500);
}
