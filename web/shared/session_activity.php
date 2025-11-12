<?php

declare(strict_types=1);

/**
 * Store per-server visitor activity in a simple JSON file inside the temp dir.
 *
 * Each entry is keyed by IP address so that we can easily show the full history
 * of submitted values alongside helper metadata.
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

function sessionActivityPath(string $serverName): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'haproxy_session_activity';
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }

    $safeName = preg_replace('/[^a-z0-9_.-]/i', '_', $serverName);
    return $base . DIRECTORY_SEPARATOR . $safeName . '.json';
}
