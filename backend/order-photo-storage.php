<?php

declare(strict_types=1);

function order_photo_storage_directory(): string
{
    static $directory = null;

    if (is_string($directory)) {
        return $directory;
    }

    $config = require __DIR__ . '/config.php';
    $configuredPath = trim((string) ($config['photo_storage_path'] ?? ''));

    if ($configuredPath === '') {
        throw new RuntimeException('PET_PHOTO_STORAGE_PATH is not configured.');
    }

    $directory = rtrim($configuredPath, '/\\');

    return $directory;
}

function save_order_photo(string $orderUid, array $photo): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $orderUid) !== 1) {
        throw new InvalidArgumentException('Invalid order photo identifier.');
    }

    $extension = (string) ($photo['extension'] ?? '');

    if (!in_array($extension, ['jpg', 'png'], true) || !is_string($photo['binary'] ?? null)) {
        throw new InvalidArgumentException('Invalid order photo data.');
    }

    $directory = order_photo_storage_directory();

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create private order photo directory.');
    }

    $fileName = $orderUid . '.' . $extension;
    $absolutePath = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($absolutePath, $photo['binary'], LOCK_EX) === false) {
        throw new RuntimeException('Failed to save order photo.');
    }

    @chmod($absolutePath, 0640);

    return 'private:' . $fileName;
}

function resolve_order_photo_absolute_path(mixed $storedPath): ?string
{
    $path = trim((string) $storedPath);

    if (preg_match('/^private:([a-f0-9]{32}\.(?:jpg|png))$/', $path, $matches) === 1) {
        $candidate = order_photo_storage_directory() . DIRECTORY_SEPARATOR . $matches[1];

        return is_file($candidate) ? $candidate : null;
    }

    // Legacy orders created before private photo storage was introduced.
    if (preg_match('/^uploads\/order-photos\/[a-f0-9]{32}\.(?:jpg|png)$/', $path) === 1) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return is_file($candidate) ? $candidate : null;
    }

    return null;
}

function delete_order_photo(?string $storedPath): void
{
    $absolutePath = resolve_order_photo_absolute_path($storedPath);

    if ($absolutePath !== null && !unlink($absolutePath)) {
        error_log('Failed to remove unused order photo.');
    }
}
