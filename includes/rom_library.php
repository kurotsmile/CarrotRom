<?php

require_once __DIR__ . '/../config/rom_library.php';

function carrot_rom_library_path(): string
{
    return rtrim((string) CARROT_ROM_LIBRARY_PATH, "\\/");
}

function carrot_rom_site_base_url(string $path = ''): string
{
    $base = rtrim((string) CARROT_ROM_SITE_BASE_URL, '/');
    if ($base === '') {
        return '/' . ltrim($path, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function carrot_rom_library_available(): bool
{
    $path = carrot_rom_library_path();
    return $path !== '' && is_dir($path) && is_readable($path);
}

function carrot_rom_path_starts_with(string $path, string $prefix): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $prefix = strtolower($prefix);
    }
    return strpos($path, $prefix) === 0;
}

function carrot_rom_safe_child_path(string $romId, string $fileName = ''): ?string
{
    $root = realpath(carrot_rom_library_path());
    if ($root === false) {
        return null;
    }

    $romId = basename(str_replace('\\', '/', $romId));
    if ($romId === '' || $romId === '.' || $romId === '..') {
        return null;
    }

    $romPath = realpath($root . DIRECTORY_SEPARATOR . $romId);
    if ($romPath === false || !is_dir($romPath) || !carrot_rom_path_starts_with($romPath, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    if ($fileName === '') {
        return $romPath;
    }

    $fileName = basename(str_replace('\\', '/', $fileName));
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    $filePath = realpath($romPath . DIRECTORY_SEPARATOR . $fileName);
    if ($filePath === false || !is_file($filePath) || !carrot_rom_path_starts_with($filePath, $romPath . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $filePath;
}

function carrot_rom_public_file_url(string $romId, string $fileName, bool $download = false): string
{
    $query = http_build_query([
        'rom' => $romId,
        'file' => $fileName,
    ]);
    if ($download) {
        $query .= '&download=1';
    }
    return carrot_rom_site_base_url('file.php?' . $query);
}

function carrot_rom_allowed_file_extensions(): array
{
    return ['zip', '7z', 'rar', 'iso', 'bin', 'cue', 'pbp', 'cso', 'chd', 'pkg', 'rom', 'nes', 'sfc', 'gba', 'gbc', 'apk'];
}

function carrot_rom_allowed_avatar_extensions(): array
{
    return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif'];
}

function carrot_rom_is_avatar_file(string $fileName): bool
{
    $name = strtolower(pathinfo($fileName, PATHINFO_FILENAME));
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $name === 'avatar' && in_array($extension, carrot_rom_allowed_avatar_extensions(), true);
}

function carrot_rom_format_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return ($index === 0 ? number_format($value, 0) : number_format($value, 1)) . ' ' . $units[$index];
}

function carrot_rom_find_first_rom_file(string $romId): array
{
    $romPath = carrot_rom_safe_child_path($romId);
    if ($romPath === null) {
        return ['url' => '', 'file_size' => '', 'file_name' => ''];
    }

    $allowed = array_flip(carrot_rom_allowed_file_extensions());
    $entries = scandir($romPath) ?: [];
    natcasesort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || carrot_rom_is_avatar_file($entry)) {
            continue;
        }
        $path = $romPath . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) {
            continue;
        }
        return [
            'console_id' => '',
            'console_name' => '',
            'url' => carrot_rom_public_file_url($romId, $entry, true),
            'file_size' => carrot_rom_format_file_size((int) filesize($path)),
            'file_name' => $entry,
        ];
    }

    return ['url' => '', 'file_size' => '', 'file_name' => ''];
}

function carrot_rom_avatar_url(string $romId): string
{
    $romPath = carrot_rom_safe_child_path($romId);
    if ($romPath === null) {
        return '';
    }

    $entries = scandir($romPath) ?: [];
    natcasesort($entries);
    foreach ($entries as $entry) {
        if (!carrot_rom_is_avatar_file($entry)) {
            continue;
        }
        if (carrot_rom_safe_child_path($romId, $entry) !== null) {
            return carrot_rom_public_file_url($romId, $entry);
        }
    }

    return '';
}

function carrot_rom_scan_library(): array
{
    if (!carrot_rom_library_available()) {
        return [];
    }

    $root = carrot_rom_library_path();
    $entries = scandir($root) ?: [];
    natcasesort($entries);
    $roms = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $roms[] = [
            'id' => $entry,
            'name' => $entry,
            'avatar' => carrot_rom_avatar_url($entry),
            'file' => carrot_rom_find_first_rom_file($entry),
        ];
    }
    return $roms;
}

function carrot_rom_library_ids(): array
{
    return array_map(static fn(array $rom): string => (string) $rom['id'], carrot_rom_scan_library());
}

function carrot_rom_library_signature(): string
{
    $items = [];
    foreach (carrot_rom_scan_library() as $rom) {
        $romId = (string) ($rom['id'] ?? '');
        $romPath = carrot_rom_safe_child_path($romId);
        $items[] = $romId . ':' . ($romPath && is_dir($romPath) ? (string) filemtime($romPath) : '0');
    }
    return sha1(implode('|', $items));
}

function carrot_rom_filter_rows_to_library(array $rows): array
{
    $ids = array_flip(carrot_rom_library_ids());
    if (!$ids) {
        return [];
    }

    return array_values(array_filter($rows, static function (array $row) use ($ids): bool {
        return isset($ids[(string) ($row['id'] ?? '')]);
    }));
}

function carrot_rom_bind_library_filter(array $ids, string $column, string $prefix, array &$params): string
{
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
        $key = ':' . $prefix . '_' . $index;
        $placeholders[] = $key;
        $params[$key] = (string) $id;
    }
    return $column . ' IN (' . implode(', ', $placeholders) . ')';
}

function carrot_rom_cache_dir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function carrot_rom_cache_key(string $prefix, array $parts): string
{
    $cleanParts = [];
    foreach ($parts as $key => $value) {
        $cleanKey = preg_replace('/[^a-z0-9_]+/i', '_', (string) $key) ?: 'part';
        $cleanValue = preg_replace('/[^a-z0-9_=-]+/i', '_', mb_substr((string) $value, 0, 80)) ?: '0';
        $cleanParts[] = $cleanKey . '-' . $cleanValue;
    }
    return preg_replace('/[^a-z0-9_=-]+/i', '_', $prefix . '_' . implode('_', $cleanParts) . '_' . substr(sha1(json_encode($parts)), 0, 12)) ?: $prefix;
}

function carrot_rom_cache_path(string $key): string
{
    return carrot_rom_cache_dir() . '/' . $key . '.json';
}

function carrot_rom_cache_get(string $key, int $ttlSeconds): ?array
{
    $path = carrot_rom_cache_path($key);
    if (!is_file($path) || (time() - (int) filemtime($path)) > $ttlSeconds) {
        return null;
    }
    $payload = json_decode((string) file_get_contents($path), true);
    return is_array($payload) ? $payload : null;
}

function carrot_rom_cache_set(string $key, array $payload): void
{
    $dir = carrot_rom_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    file_put_contents(carrot_rom_cache_path($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function carrot_rom_cache_clear(string $prefix = ''): int
{
    $dir = carrot_rom_cache_dir();
    if (!is_dir($dir)) {
        return 0;
    }
    $deleted = 0;
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if ($prefix !== '' && strpos(basename($path), $prefix) !== 0) {
            continue;
        }
        if (is_file($path) && @unlink($path)) {
            $deleted++;
        }
    }
    return $deleted;
}

function carrot_rom_sync_library_to_database(PDO $pdo): int
{
    $folders = carrot_rom_scan_library();
    if (!$folders) {
        return 0;
    }

    $now = gmdate('c');
    $stmt = $pdo->prepare('
        INSERT INTO rom (id, name, platform, emulator, category, region, lang, price, is_free, status, photos, file_rom, file_size, sort_order, description, published_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name)
    ');
    $count = 0;
    foreach ($folders as $folder) {
        $file = $folder['file'] ?? [];
        $fileRom = !empty($file['url']) ? json_encode([$file], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $stmt->execute([
            (string) $folder['id'],
            (string) $folder['name'],
            'ps1',
            '',
            null,
            null,
            'en',
            0,
            1,
            'public',
            '',
            $fileRom,
            (string) ($file['file_size'] ?? ''),
            0,
            '',
            date('Y-m-d'),
            $now,
            $now,
        ]);
        $count++;
    }
    return $count;
}

function carrot_rom_enrich_rows_from_library(array $rows): array
{
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        $romId = (string) ($row['id'] ?? '');
        $avatar = carrot_rom_avatar_url($romId);
        $row['_library_rom'] = carrot_rom_safe_child_path($romId) !== null;
        if ($avatar !== '') {
            $row['avatar'] = $avatar;
        }
        $diskFile = carrot_rom_find_first_rom_file($romId);
        if (!empty($diskFile['url'])) {
            $row['file_rom'] = json_encode([$diskFile], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $row['file_size'] = $diskFile['file_size'] ?? ($row['file_size'] ?? '');
        }
    }
    unset($row);
    return $rows;
}
