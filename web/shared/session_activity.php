<?php

declare(strict_types=1);

/**
 * Stocke l’activité (par scope de session) dans un fichier JSON commun aux
 * différents serveurs web. Chaque entrée est indexée par adresse IP pour
 * conserver un petit historique côté UI.
 */

function sessionActivityRecord(string $serverName, string $ipAddress, ?string $newValue, array $meta = []): void
{
    $path = sessionActivityPath($serverName);
    $resource = fopen($path, 'c+');
    if (!$resource) {
        return;
    }

    try {
        if (!flock($resource, LOCK_EX)) {
            fclose($resource);
            return;
        }

        $existing = [];
        $contents = stream_get_contents($resource);
        if ($contents !== false && trim($contents) !== '') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $entry = $existing[$ipAddress] ?? [
            'ip'     => $ipAddress,
            'server' => $serverName,
            'hits'   => 0,
        ];

        $now = time();
        $entry['last_value']  = $newValue;
        $entry['last_update'] = $now;
        $entry['hits']        = ($entry['hits'] ?? 0) + 1;
        if ($meta) {
            $entry['meta'] = array_merge($entry['meta'] ?? [], $meta);
        }

        $historyItem = [
            'value'     => $newValue,
            'timestamp' => $now,
            'meta'      => $meta,
        ];
        $history = $entry['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, $historyItem);
        $entry['history'] = array_slice($history, 0, 20);

        $existing[$ipAddress] = $entry;

        $encoded = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded !== false) {
            ftruncate($resource, 0);
            rewind($resource);
            fwrite($resource, $encoded);
            fflush($resource);
        }

        flock($resource, LOCK_UN);
    } finally {
        fclose($resource);
    }
}

function sessionActivityList(string $serverName): array
{
    $path = sessionActivityPath($serverName);
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    $entries = array_values($decoded);
    usort($entries, static fn(array $a, array $b): int => (int)($b['last_update'] ?? 0) <=> (int)($a['last_update'] ?? 0));
    return $entries;
}

function sessionActivityBaseDir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $sharedStore = __DIR__ . DIRECTORY_SEPARATOR . 'session_activity_store';
    if (ensureDirectory($sharedStore)) {
        return $resolved = $sharedStore;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'haproxy_session_activity';
    ensureDirectory($fallback);
    return $resolved = $fallback;
}

function ensureDirectory(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }

    return @mkdir($path, 0777, true);
}

function sessionActivityPath(string $serverName): string
{
    $safeName = preg_replace('/[^a-z0-9_.-]/i', '_', $serverName);
    return sessionActivityBaseDir() . DIRECTORY_SEPARATOR . $safeName . '.json';
}
