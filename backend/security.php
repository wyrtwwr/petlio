<?php

declare(strict_types=1);

const MAX_JSON_BODY_BYTES = 12582912;

final class ApiRequestException extends RuntimeException
{
    public int $status;
    public array $details;

    public function __construct(string $message, int $status = 400, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
    }
}

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

function rate_limit_allows(string $bucket = 'default', int $limit = 20, int $windowSeconds = 60): bool
{
    $dir = sys_get_temp_dir() . '/petlio-rate-limit';

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create rate limit directory.');
    }

    $key = hash('sha256', $bucket . '|' . get_client_ip());
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];
    $handle = fopen($file, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Failed to open rate limit state.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Failed to lock rate limit state.');
        }

        $contents = stream_get_contents($handle);
        $decoded = json_decode(is_string($contents) ? $contents : '', true);

        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }

        if (($now - (int) $state['start']) >= $windowSeconds) {
            $state = ['start' => $now, 'count' => 0];
        }

        $state['count'] = (int) $state['count'] + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return $state['count'] <= $limit;
}

function basic_rate_limit(string $bucket = 'default', int $limit = 20, int $windowSeconds = 60): void
{
    if (rate_limit_allows($bucket, $limit, $windowSeconds)) {
        return;
    }

    json_response(['message' => 'Слишком много запросов. Попробуйте позже.'], 429);
}

function no_store_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
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
    if ($error instanceof ApiRequestException) {
        json_response(
            array_merge(['message' => $error->getMessage()], $error->details),
            $error->status
        );
    }

    error_log($error->getMessage());
    json_response(['message' => 'Внутренняя ошибка сервера. Попробуйте позже.'], 500);
}
