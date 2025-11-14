<?php
const WEB_BACKEND = 'web_back';
const DB_BACKEND  = 'mysql_back';

function buildDashboardContext(): array
{
    $projectRoot = dirname(__DIR__, 2);
    error_log("dirname: " . dirname(__DIR__ ));
    error_log("Project root: " . $projectRoot);
    return [
        'project_root'    => $projectRoot,
        'web_cfg'         => resolveFirstExisting([
            '/data/haproxy-web/haproxy.cfg',
            $projectRoot . '/haproxy-web/haproxy.cfg'
        ]),
        'db_cfg'          => resolveFirstExisting([
            '/data/haproxy-db/haproxy.cfg',
            $projectRoot . '/haproxy-db/haproxy.cfg'
        ]),
        'web_runtime_socket' => 'tcp://haproxy-web:9999',
        'db_runtime_socket'  => 'tcp://haproxy-db:10000',
        'web_reload_flag' => '/haproxy-runtime/reload.flag',
        'db_reload_flag'  => '/haproxy-db-runtime/reload.flag',
    ];
}

function resolveFirstExisting(array $candidates): ?string
{
    foreach ($candidates as $path) {
        if ($path && file_exists($path)) {
            return $path;
        }
    }
    return null;
}

function addFlash(string $type, string $text): void
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
}

function consumeFlashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    $_SESSION['flash'] = [];
    return $messages;
}

function loadWebServers(array $ctx): array
{
    return parseWebServers($ctx['web_cfg'] ?? null);
}

function loadDatabaseServers(array $ctx): array
{
    return parseDatabaseServers($ctx['db_cfg'] ?? null);
}

function getSessionMode(array $ctx): string
{
    $configPath = $ctx['web_cfg'] ?? null;
    if (!$configPath || !file_exists($configPath)) {
        return 'haproxy';
    }

    $lines = file($configPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return 'haproxy';
    }

    $insideBackend  = false;
    $backendPattern = '/^backend\s+' . preg_quote(WEB_BACKEND, '/') . '\b/i';
    $sectionPattern = '/^(frontend|backend|listen|global|defaults)\b/i';

    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (!$insideBackend && preg_match($backendPattern, $trim)) {
            $insideBackend = true;
            continue;
        }
        if ($insideBackend) {
            if (preg_match($sectionPattern, $trim)) {
                break;
            }
            if (preg_match('/^cookie\s+SRV\b/i', $trim)) {
                return 'haproxy';
            }
        }
    }

    return 'database';
}

function handleSessionMode(array $ctx, array $data): void
{
    $mode = $data['mode'] ?? '';
    if (!in_array($mode, ['haproxy', 'database'], true)) {
        addFlash('error', 'Mode de session invalide.');
        return;
    }

    $configPath = $ctx['web_cfg'] ?? null;
    if (!$configPath || !file_exists($configPath)) {
        addFlash('error', 'Configuration HAProxy Web introuvable.');
        return;
    }

    if (!setSessionModeInConfig($configPath, $mode)) {
        addFlash('error', "Impossible d'appliquer le mode de session {$mode}.");
        return;
    }

    requestHaProxyReload($ctx['web_reload_flag'] ?? null, 'HAProxy Web');
    addFlash('success', $mode === 'haproxy'
        ? 'Gestion de session par HAProxy (sticky cookie) activée.'
        : 'Gestion de session via la base de données activée (sticky désactivé).');
}

function handleDashboardRefresh(array $ctx): void
{
    $snapshot = captureRuntimeSnapshot($ctx);
    if ($snapshot) {
        $_SESSION['runtime_snapshot'] = $snapshot;
        addFlash('success', 'Statuts mis à jour depuis HAProxy runtime.');
    } else {
        unset($_SESSION['runtime_snapshot']);
        addFlash('warning', "Impossible d'interroger les sockets runtime HAProxy.");
    }
}

function handleAddWeb(array $ctx, array $data): void
{
    $configPath = $ctx['web_cfg'] ?? null;
    if (!$configPath) {
        addFlash('error', 'Fichier de configuration HAProxy (web) introuvable.');
        return;
    }

    $operation    = $data['operation'] ?? 'create';
    $originalName = trim($data['original_name'] ?? '');
    $name         = trim($data['name'] ?? '');
    $host         = trim($data['host'] ?? '');
    $port         = filter_var($data['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $cookie       = trim($data['cookie'] ?? '');
    $healthCheck  = !empty($data['health_check']);

    $errors = [];
    if (!validateIdentifier($name)) {
        $errors[] = 'Nom du serveur invalide (lettres, chiffres, . _ -).';
    }
    if (!validateHost($host)) {
        $errors[] = "Adresse IP / hôte invalide (ex: 'web3' ou '192.168.1.12').";
    }
    if ($port === false) {
        $errors[] = 'Port HTTP invalide.';
    }
    if ($cookie !== '' && !validateIdentifier($cookie)) {
        $errors[] = 'Cookie invalide (lettres, chiffres, . _ -).';
    }
    if ($operation === 'update' && $originalName === '') {
        $errors[] = 'Serveur d’origine manquant pour la mise à jour.';
    }

    $existing = parseWebServers($configPath);
    foreach ($existing as $server) {
        if (strcasecmp($server['name'], $name) === 0) {
            $isSame = strcasecmp($server['name'], $originalName) === 0;
            if ($operation === 'create' || ($operation === 'update' && !$isSame)) {
                $errors[] = 'Un serveur portant ce nom existe déjà.';
                break;
            }
        }
    }

    if ($errors) {
        addFlash('error', implode(' ', $errors));
        return;
    }

    $payload = [
        'name'          => $name,
        'host'          => $host,
        'port'          => (int) $port,
        'cookie'        => $cookie,
        'health_check'  => $healthCheck,
    ];

    if ($operation === 'update') {
        $updated = updateWebServerEntry($configPath, $payload, $originalName);
        if ($updated) {
            addFlash('success', "Serveur web {$originalName} mis à jour.");
            requestHaProxyReload($ctx['web_reload_flag'] ?? null, 'HAProxy Web');
        } else {
            addFlash('error', "Impossible de mettre à jour {$originalName} (introuvable).");
        }
        return;
    }

    $lineParts = ['server', $name, sprintf('%s:%d', $host, $port)];
    if ($cookie !== '') {
        $lineParts[] = 'cookie';
        $lineParts[] = $cookie;
    }
    if ($healthCheck) {
        $lineParts[] = 'check';
    }

    $line = implode(' ', $lineParts);
    if (insertServerLine($configPath, WEB_BACKEND, $line)) {
        addFlash('success', "Serveur web {$name} ajouté dans la configuration.");
        requestHaProxyReload($ctx['web_reload_flag'] ?? null, 'HAProxy Web');
    } else {
        addFlash('error', "Impossible d'ajouter {$name} (backend introuvable ?).");
    }
}

function handleWebAction(array $ctx, array $data): void
{
    $configPath = $ctx['web_cfg'] ?? null;
    if (!$configPath) {
        addFlash('error', 'Fichier de configuration HAProxy (web) introuvable.');
        return;
    }

    $action = $data['action'] ?? '';
    $server = trim($data['server'] ?? '');

    if (!validateIdentifier($server)) {
        addFlash('error', 'Serveur cible invalide.');
        return;
    }

    switch ($action) {
        case 'delete':
            if (removeServerEntry($configPath, WEB_BACKEND, $server)) {
                requestHaProxyReload($ctx['web_reload_flag'] ?? null, 'HAProxy Web');
                runtimeCommand($ctx['web_runtime_socket'] ?? null, "disable server " . WEB_BACKEND . "/{$server}");
                addFlash('success', "Serveur {$server} retiré du backend.");
            } else {
                addFlash('error', "Impossible de retirer {$server} (introuvable).");
            }
            break;
        case 'refresh':
            if (runtimeCommand($ctx['web_runtime_socket'] ?? null, "show servers state " . WEB_BACKEND)) {
                addFlash('success', "Statut de {$server} rafraîchi (via socket runtime).");
            } else {
                addFlash('warning', "Impossible d'interroger HAProxy runtime. Pensez à recharger manuellement.");
            }
            break;
        case 'restart':
            $disabled = runtimeCommand($ctx['web_runtime_socket'] ?? null, "disable server " . WEB_BACKEND . "/{$server}");
            $enabled  = runtimeCommand($ctx['web_runtime_socket'] ?? null, "enable server " . WEB_BACKEND . "/{$server}");
            if ($disabled && $enabled) {
                addFlash('success', "Requête de redémarrage envoyée pour {$server}.");
            } else {
                addFlash('warning', "Impossible de redémarrer {$server} via la socket runtime.");
            }
            break;
        case 'toggle':
            $target   = $data['target_state'] ?? 'disable';
            $disable  = $target === 'disable';
            $updated  = setServerDisabled($configPath, $server, $disable);
            if ($updated) {
                $runtimeOk = runtimeCommand($ctx['web_runtime_socket'] ?? null, ($disable ? 'disable' : 'enable') . " server " . WEB_BACKEND . "/{$server}");
                if (!$runtimeOk) {
                    addFlash('warning', "Configuration mise à jour mais impossible de contacter HAProxy runtime. Relance requise.");
                } else {
                    addFlash('success', $disable ? "{$server} désactivé." : "{$server} réactivé.");
                }
            } else {
                addFlash('error', "Impossible de changer l'état de {$server} (introuvable).");
            }
            break;
        default:
            addFlash('error', 'Action inconnue.');
    }
}

function handleDatabaseForm(array $ctx, array $data): void
{
    $configPath = $ctx['db_cfg'] ?? null;
    if (!$configPath) {
        addFlash('error', 'Fichier de configuration HAProxy (DB) introuvable.');
        return;
    }

    $operation    = $data['operation'] ?? 'create';
    $originalName = trim($data['original_name'] ?? '');
    $name         = trim($data['name'] ?? '');
    $host         = trim($data['host'] ?? '');
    $port         = filter_var($data['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $gtid         = !empty($data['gtid']);

    $errors = [];
    if (!validateIdentifier($name)) {
        $errors[] = 'Nom du serveur DB invalide (lettres, chiffres, . _ -).';
    }
    if (!validateHost($host)) {
        $errors[] = "Adresse IP / hôte DB invalide (ex: 'mysql3' ou '172.18.0.12').";
    }
    if ($port === false) {
        $errors[] = 'Port MySQL invalide.';
    }
    if ($operation === 'update' && $originalName === '') {
        $errors[] = 'Instance d’origine manquante pour la mise à jour.';
    }

    $existing = parseDatabaseServers($configPath);
    foreach ($existing as $server) {
        if (strcasecmp($server['name'], $name) === 0) {
            $isSame = strcasecmp($server['name'], $originalName) === 0;
            if ($operation === 'create' || ($operation === 'update' && !$isSame)) {
                $errors[] = 'Une instance de base de données porte déjà ce nom.';
                break;
            }
        }
    }

    if ($errors) {
        addFlash('error', implode(' ', $errors));
        return;
    }

    $payload = [
        'name'          => $name,
        'host'          => $host,
        'port'          => (int) $port,
        'role'          => 'Master-Master',
        'gtid'          => $gtid,
        'health_check'  => true,
        'backup'        => $operation === 'create' ? true : null,
    ];

    if ($operation === 'update') {
        $updated = updateDatabaseServerEntry($configPath, $payload, $originalName);
        if ($updated) {
            addFlash('success', "Instance {$originalName} mise à jour.");
            requestHaProxyReload($ctx['db_reload_flag'] ?? null, 'HAProxy DB');
        } else {
            addFlash('error', "Impossible de modifier {$originalName} (introuvable).");
        }
        return;
    }

    $line = buildDatabaseServerLine($payload);

    if (insertServerLine($configPath, DB_BACKEND, $line)) {
        addFlash('success', "Base de données {$name} ajoutée dans HAProxy.");
        requestHaProxyReload($ctx['db_reload_flag'] ?? null, 'HAProxy DB');
    } else {
        addFlash('error', "Impossible d'ajouter {$name} (backend DB introuvable ?).");
    }
}

function handleDatabaseAction(array $ctx, array $data): void
{
    $configPath = $ctx['db_cfg'] ?? null;
    if (!$configPath) {
        addFlash('error', 'Fichier de configuration HAProxy (DB) introuvable.');
        return;
    }

    $action = $data['action'] ?? '';
    $server = trim($data['server'] ?? '');

    if (!validateIdentifier($server)) {
        addFlash('error', 'Instance cible invalide.');
        return;
    }

    switch ($action) {
        case 'delete':
            if (removeServerEntry($configPath, DB_BACKEND, $server)) {
                requestHaProxyReload($ctx['db_reload_flag'] ?? null, 'HAProxy DB');
                runtimeCommand($ctx['db_runtime_socket'] ?? null, "disable server " . DB_BACKEND . "/{$server}");
                addFlash('success', "Instance {$server} supprimée.");
            } else {
                addFlash('error', "Impossible de supprimer {$server} (introuvable).");
            }
            break;
        case 'refresh':
            if (runtimeCommand($ctx['db_runtime_socket'] ?? null, "show servers state " . DB_BACKEND)) {
                addFlash('success', "Statut de {$server} rafraîchi.");
            } else {
                addFlash('warning', "Impossible de contacter HAProxy DB (socket runtime).");
            }
            break;
        case 'restart':
            $disabled = runtimeCommand($ctx['db_runtime_socket'] ?? null, "disable server " . DB_BACKEND . "/{$server}");
            $enabled  = runtimeCommand($ctx['db_runtime_socket'] ?? null, "enable server " . DB_BACKEND . "/{$server}");
            if ($disabled && $enabled) {
                addFlash('success', "Redémarrage logique demandé pour {$server}.");
            } else {
                addFlash('warning', "Impossible de redémarrer {$server} via la socket runtime.");
            }
            break;
        case 'toggle':
            $target  = $data['target_state'] ?? 'disable';
            $disable = $target === 'disable';
            $updated = setDatabaseServerDisabled($configPath, $server, $disable);
            if ($updated) {
                $runtimeOk = runtimeCommand($ctx['db_runtime_socket'] ?? null, ($disable ? 'disable' : 'enable') . " server " . DB_BACKEND . "/{$server}");
                if (!$runtimeOk) {
                    addFlash('warning', "Configuration DB mise à jour mais impossible de contacter HAProxy (socket).");
                } else {
                    addFlash('success', $disable ? "{$server} désactivé." : "{$server} réactivé.");
                }
            } else {
                addFlash('error', "Impossible de changer l'état de {$server}.");
            }
            break;
        default:
            addFlash('error', 'Action DB inconnue.');
    }
}

function requestHaProxyReload(?string $flag, string $label): void
{
    if (!$flag) {
        addFlash('warning', "Impossible de signaler le rechargement {$label} (chemin manquant).");
        return;
    }

    $targets = [$flag];
    if (str_ends_with($flag, 'reload.flag')) {
        $targets[] = substr($flag, 0, -strlen('reload.flag')) . 'restart.flag';
    }

    $success = false;
    foreach ($targets as $target) {
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }
        if (@file_put_contents($target, "reload\n") === false) {
            continue;
        }
        $success = true;
    }

    // if (!$success) {
    //     addFlash('warning', "Impossible de signaler automatiquement le rechargement {$label} (droits ?).");
    // }
}

function normalizeRuntimeEndpoint(?string $target): ?string
{
    if (!$target) {
        return null;
    }
    if (str_starts_with($target, 'tcp://') || str_starts_with($target, 'unix://')) {
        return $target;
    }
    if (!file_exists($target)) {
        return null;
    }
    return 'unix://' . $target;
}

function runtimeCommand(?string $socket, string $command): bool
{
    $endpoint = normalizeRuntimeEndpoint($socket);
    if (!$endpoint) {
        return false;
    }

    $conn = @stream_socket_client($endpoint, $errno, $errStr, 1);
    if (!$conn) {
        return false;
    }

    fwrite($conn, $command . "\n");
    stream_set_timeout($conn, 1);
    while (!feof($conn)) {
        $chunk = fgets($conn);
        if ($chunk === false) {
            break;
        }
    }
    fclose($conn);
    return true;
}

function runtimeCommandLines(?string $socket, string $command): ?array
{
    $endpoint = normalizeRuntimeEndpoint($socket);
    if (!$endpoint) {
        return null;
    }

    $conn = @stream_socket_client($endpoint, $errno, $errStr, 1);
    if (!$conn) {
        return null;
    }

    fwrite($conn, $command . "\n");
    stream_set_timeout($conn, 1);
    $lines = [];
    while (!feof($conn)) {
        $chunk = fgets($conn);
        if ($chunk === false) {
            break;
        }
        $lines[] = rtrim($chunk, "\r\n");
    }
    fclose($conn);

    return $lines ?: null;
}

function captureRuntimeSnapshot(array $ctx): ?array
{
    $webStats = fetchBackendRuntimeStats($ctx['web_runtime_socket'] ?? null, WEB_BACKEND);
    $dbStats  = fetchBackendRuntimeStats($ctx['db_runtime_socket'] ?? null, DB_BACKEND);

    if (empty($webStats) && empty($dbStats)) {
        return null;
    }

    return [
        'web'         => $webStats,
        'db'          => $dbStats,
        'generated_at'=> time(),
    ];
}

function fetchBackendRuntimeStats(?string $socket, string $backend): array
{
    $lines = runtimeCommandLines($socket, 'show stat');
    if (!$lines) {
        return [];
    }

    $header = null;
    $stats  = [];
    foreach ($lines as $line) {
        if ($line === '' || $line === '#') {
            continue;
        }
        if ($line[0] === '#') {
            $csv = str_getcsv(ltrim(substr($line, 1)));
            if ($csv) {
                $header = $csv;
            }
            continue;
        }
        if (!$header) {
            continue;
        }
        $row = str_getcsv($line);
        if (count($row) !== count($header)) {
            continue;
        }
        $assoc = array_combine($header, $row);
        if (!$assoc) {
            continue;
        }
        if (($assoc['pxname'] ?? '') !== $backend) {
            continue;
        }
        $serverName = $assoc['svname'] ?? '';
        if ($serverName === '' || strtoupper($serverName) === 'BACKEND') {
            continue;
        }
        $stats[$serverName] = [
            'status'       => $assoc['status'] ?? '',
            'check_status' => $assoc['check_status'] ?? ($assoc['check_code'] ?? ''),
            'lastchk'      => $assoc['last_chk'] ?? '',
            'lastchg'      => isset($assoc['lastchg']) ? (int) $assoc['lastchg'] : null,
        ];
    }

    return $stats;
}

function decorateWithRuntimeStats(array $servers, array $runtimeStats): array
{
    foreach ($servers as &$server) {
        $name = $server['name'] ?? null;
        if (!$name || !isset($runtimeStats[$name])) {
            continue;
        }
        $stat = $runtimeStats[$name];
        if (!empty($stat['status'])) {
            $server['status'] = strtoupper($stat['status']);
        }
        $details = [];
        if (!empty($stat['check_status'])) {
            $details[] = $stat['check_status'];
        }
        if (!empty($stat['lastchk'])) {
            $details[] = $stat['lastchk'];
        }
        if (!empty($stat['lastchg'])) {
            $details[] = 'Δ ' . formatDuration((int) $stat['lastchg']);
        }
        if ($details) {
            $server['last_check'] = implode(' · ', $details);
        }
    }

    return $servers;
}

function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $seconds = $seconds % 60;
    if ($minutes < 60) {
        return $minutes . 'm' . ($seconds ? ' ' . $seconds . 's' : '');
    }
    $hours   = intdiv($minutes, 60);
    $minutes = $minutes % 60;
    if ($hours < 24) {
        return $hours . 'h' . ($minutes ? ' ' . $minutes . 'm' : '');
    }
    $days = intdiv($hours, 24);
    $hours = $hours % 24;
    $parts = [$days . 'j'];
    if ($hours) {
        $parts[] = $hours . 'h';
    }
    if ($minutes) {
        $parts[] = $minutes . 'm';
    }
    return implode(' ', $parts);
}

function validateIdentifier(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
}

function validateHost(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value) || filter_var($value, FILTER_VALIDATE_IP);
}

function insertServerLine(string $path, string $backend, string $line): bool
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $updated = injectLineIntoBackend($lines, $backend, '    ' . $line);
    if ($updated === null) {
        return false;
    }

    return writeConfig($path, $updated);
}

function setSessionModeInConfig(string $path, string $mode): bool
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $backendPattern = '/^backend\s+' . preg_quote(WEB_BACKEND, '/') . '\b/i';
    $sectionPattern = '/^(frontend|backend|listen|global|defaults)\b/i';
    $insideBackend  = false;
    $inserted       = false;
    $result         = [];

    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (!$insideBackend && preg_match($backendPattern, $trim)) {
            $insideBackend = true;
            $result[] = $line;
            continue;
        }
        if ($insideBackend) {
            if (preg_match($sectionPattern, $trim)) {
                if ($mode === 'haproxy' && !$inserted) {
                    $result[] = '    cookie SRV insert indirect nocache';
                    $inserted = true;
                }
                $result[] = $line;
                $insideBackend = false;
                continue;
            }
            if (preg_match('/^cookie\s+SRV\b/i', $trim)) {
                continue;
            }
            if ($mode === 'haproxy' && !$inserted && preg_match('/^option\b/i', $trim)) {
                $result[] = '    cookie SRV insert indirect nocache';
                $inserted = true;
            }
        }
        $result[] = $line;
    }

    if ($insideBackend && $mode === 'haproxy' && !$inserted) {
        $result[] = '    cookie SRV insert indirect nocache';
    }

    return writeConfig($path, $result);
}

function injectLineIntoBackend(array $lines, string $backend, string $line): ?array
{
    $insideBackend   = false;
    $lastServerIndex = null;
    $backendPattern  = '/^backend\s+' . preg_quote($backend, '/') . '\b/i';
    $sectionPattern  = '/^(frontend|backend|listen|global|defaults)\b/i';

    foreach ($lines as $index => $rawLine) {
        $trimmed = ltrim($rawLine);
        if (!$insideBackend && preg_match($backendPattern, $trimmed)) {
            $insideBackend = true;
            continue;
        }
        if ($insideBackend) {
            if (preg_match($sectionPattern, $trimmed)) {
                $insertAt = $lastServerIndex !== null ? $lastServerIndex + 1 : $index;
                array_splice($lines, $insertAt, 0, $line);
                return $lines;
            }
            if (preg_match('/^server\b/i', $trimmed)) {
                $lastServerIndex = $index;
            }
        }
    }

    if ($insideBackend) {
        $insertAt = $lastServerIndex !== null ? $lastServerIndex + 1 : count($lines);
        array_splice($lines, $insertAt, 0, $line);
        return $lines;
    }

    return null;
}

function updateWebServerEntry(string $path, array $payload, string $originalName): bool
{
    return alterServerLine($path, WEB_BACKEND, $originalName, function (array $definition, string $indent) use ($payload) {
        $definition['name']         = $payload['name'];
        $definition['host']         = $payload['host'];
        $definition['port']         = (string) $payload['port'];
        $definition['cookie']       = $payload['cookie'];
        $definition['health_check'] = $payload['health_check'];
        $definition['disabled']     = !empty($definition['disabled']);
        $line = buildWebServerLine($definition);
        return $indent . $line;
    });
}

function removeServerEntry(string $path, string $backend, string $name): bool
{
    return alterServerLine($path, $backend, $name, function () {
        return null;
    });
}

function setServerDisabled(string $path, string $name, bool $disable): bool
{
    return alterServerLine($path, WEB_BACKEND, $name, function (array $definition, string $indent) use ($disable) {
        $definition['disabled'] = $disable;
        $line = buildWebServerLine($definition);
        return $indent . $line;
    });
}

function alterServerLine(string $path, string $backend, string $targetName, callable $callback, ?callable $parser = null): bool
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $backendPattern = '/^backend\s+' . preg_quote($backend, '/') . '\b/i';
    $sectionPattern = '/^(frontend|backend|listen|global|defaults)\b/i';
    $insideBackend  = false;

    foreach ($lines as $index => $rawLine) {
        $trimmed = ltrim($rawLine);
        if (!$insideBackend && preg_match($backendPattern, $trimmed)) {
            $insideBackend = true;
            continue;
        }
        if ($insideBackend) {
            if (preg_match($sectionPattern, $trimmed)) {
                break;
            }
            if (preg_match('/^server\s+([^\s]+)/i', $trimmed, $match) && strcasecmp($match[1], $targetName) === 0) {
                $indent = '';
                if (preg_match('/^(\s*)server/i', $rawLine, $indentMatch)) {
                    $indent = $indentMatch[1];
                }
                $parserCallable = $parser ?? 'parseServerDefinition';
                $definition = call_user_func($parserCallable, $rawLine);
                if (!$definition) {
                    return false;
                }
                $replacement = $callback($definition, $indent);
                if ($replacement === null) {
                    array_splice($lines, $index, 1);
                } else {
                    $lines[$index] = $replacement;
                }
                return writeConfig($path, $lines);
            }
        }
    }

    return false;
}

function buildWebServerLine(array $definition): string
{
    $parts = [
        'server',
        $definition['name'],
        sprintf('%s:%s', $definition['host'], $definition['port'])
    ];
    if ($definition['cookie'] !== '') {
        $parts[] = 'cookie';
        $parts[] = $definition['cookie'];
    }
    if (!empty($definition['health_check'])) {
        $parts[] = 'check';
    }
    if (!empty($definition['disabled'])) {
        $parts[] = 'disabled';
    }
    return implode(' ', $parts);
}

function updateDatabaseServerEntry(string $path, array $payload, string $originalName): bool
{
    return alterServerLine($path, DB_BACKEND, $originalName, function (array $definition, string $indent) use ($payload) {
        $definition['name']   = $payload['name'];
        $definition['host']   = $payload['host'];
        $definition['port']   = (string) $payload['port'];
        $definition['role']   = $payload['role'];
        $definition['gtid']   = $payload['gtid'];
        $definition['disabled'] = !empty($definition['disabled']);
        $definition['health_check'] = true;
        if (array_key_exists('backup', $payload) && $payload['backup'] !== null) {
            $definition['backup'] = (bool) $payload['backup'];
        } else {
            $definition['backup'] = !empty($definition['backup']);
        }
        $line = buildDatabaseServerLine($definition);
        return $indent . $line;
    }, 'parseDatabaseServerDefinition');
}

function setDatabaseServerDisabled(string $path, string $name, bool $disable): bool
{
    return alterServerLine($path, DB_BACKEND, $name, function (array $definition, string $indent) use ($disable) {
        $definition['disabled'] = $disable;
        $definition['backup'] = !empty($definition['backup']);
        $line = buildDatabaseServerLine($definition);
        return $indent . $line;
    }, 'parseDatabaseServerDefinition');
}

function buildDatabaseServerLine(array $definition): string
{
    $parts = [
        'server',
        $definition['name'],
        sprintf('%s:%s', $definition['host'], $definition['port'])
    ];
    if (!empty($definition['health_check'])) {
        $parts[] = 'check';
    }
    if (!empty($definition['backup'])) {
        $parts[] = 'backup';
    }
    if (!empty($definition['disabled'])) {
        $parts[] = 'disabled';
    }
    $meta = [];
    if (!empty($definition['role'])) {
        $meta[] = 'role=' . $definition['role'];
    }
    if (isset($definition['gtid'])) {
        $meta[] = 'gtid=' . ($definition['gtid'] ? 'on' : 'off');
    }
    $line = implode(' ', $parts);
    if ($meta) {
        $line .= ' # ' . implode(' ', $meta);
    }
    return $line;
}

function writeConfig(string $path, array $lines): bool
{
    $content = implode(PHP_EOL, $lines) . PHP_EOL;
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

function parseServerDefinition(string $line): ?array
{
    [$content] = splitComment($line);
    $content = trim($content);
    if ($content === '' || stripos($content, 'server') !== 0) {
        return null;
    }
    $parts = preg_split('/\s+/', $content);
    if (count($parts) < 3) {
        return null;
    }
    [$host, $port] = explodeAddress($parts[2]);
    return [
        'name'         => $parts[1],
        'host'         => $host,
        'port'         => $port,
        'cookie'       => findTokenValue($parts, 'cookie') ?? '',
        'health_check' => hasFlag($parts, 'check'),
        'disabled'     => hasFlag($parts, 'disabled'),
    ];
}

function parseDatabaseServerDefinition(string $line): ?array
{
    [$content, $comment] = splitComment($line);
    $content = trim($content);
    if ($content === '' || stripos($content, 'server') !== 0) {
        return null;
    }
    $parts = preg_split('/\s+/', $content);
    if (count($parts) < 3) {
        return null;
    }
    [$host, $port] = explodeAddress($parts[2]);
    $meta           = parseMetaComment($comment);
    $gtid           = strtolower($meta['gtid'] ?? 'on');

    return [
        'name'          => $parts[1],
        'host'          => $host,
        'port'          => $port,
        'role'          => 'Master-Master',
        'role_label'    => 'Master-Master',
        'gtid'          => $gtid === 'on',
        'gtid_label'    => $gtid,
        'health_check'  => hasFlag($parts, 'check'),
        'disabled'      => hasFlag($parts, 'disabled'),
        'backup'        => hasFlag($parts, 'backup'),
    ];
}

function parseWebServers(?string $path): array
{
    $lines = extractBackendServers($path, WEB_BACKEND);
    if (empty($lines)) {
        return defaultWebServers();
    }

    $servers = [];
    foreach ($lines as $line) {
        $definition = parseServerDefinition($line);
        if (!$definition) {
            continue;
        }
        $servers[] = [
            'name'          => $definition['name'],
            'ip'            => $definition['host'],
            'port'          => $definition['port'],
            'status'        => $definition['disabled'] ? 'DISABLED' : 'OK',
            'cookie'        => $definition['cookie'] !== '' ? $definition['cookie'] : '—',
            'cookie_raw'    => $definition['cookie'],
            'health_check'  => $definition['health_check'],
            'disabled'      => $definition['disabled'],
            'last_check'    => '—',
        ];
    }

    return $servers ?: defaultWebServers();
}

function parseDatabaseServers(?string $path): array
{
    $lines = extractBackendServers($path, DB_BACKEND);
    if (empty($lines)) {
        return defaultDatabaseServers();
    }

    $servers = [];
    foreach ($lines as $line) {
        $definition = parseDatabaseServerDefinition($line);
        if (!$definition) {
            continue;
        }
        $servers[] = [
            'name'        => $definition['name'],
            'ip'          => $definition['host'],
            'port'        => $definition['port'],
            'status'      => $definition['disabled'] ? 'DISABLED' : 'OK',
            'role'        => $definition['role_label'],
            'role_raw'    => $definition['role'],
            'gtid'        => strtoupper($definition['gtid_label']),
            'gtid_bool'   => $definition['gtid'],
            'disabled'    => $definition['disabled'],
            'health_check'=> $definition['health_check'],
            'backup'      => $definition['backup'],
            'last_check'  => '—',
        ];
    }

    return $servers ?: defaultDatabaseServers();
}

function extractBackendServers(?string $path, string $backend): array
{
    if (!$path || !file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $collected      = [];
    $insideBackend  = false;
    $backendPattern = '/^backend\s+' . preg_quote($backend, '/') . '\b/i';
    $sectionPattern = '/^(frontend|backend|listen|global|defaults)\b/i';

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (!$insideBackend && preg_match($backendPattern, $trimmed)) {
            $insideBackend = true;
            continue;
        }
        if ($insideBackend) {
            if (preg_match($sectionPattern, $trimmed)) {
                break;
            }
            if (preg_match('/^server\b/i', $trimmed)) {
                $collected[] = rtrim($line);
            }
        }
    }

    return $collected;
}

function splitComment(string $line): array
{
    $pos = strpos($line, '#');
    if ($pos === false) {
        return [$line, ''];
    }
    return [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
}

function parseMetaComment(string $comment): array
{
    $meta = [];
    if ($comment === '') {
        return $meta;
    }
    $chunks = preg_split('/\s+/', $comment);
    foreach ($chunks as $chunk) {
        if (strpos($chunk, '=') !== false) {
            [$key, $value] = explode('=', $chunk, 2);
            $meta[strtolower($key)] = $value;
        }
    }
    return $meta;
}

function findTokenValue(array $parts, string $token): ?string
{
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        if (strcasecmp($parts[$i], $token) === 0 && isset($parts[$i + 1])) {
            return $parts[$i + 1];
        }
    }
    return null;
}

function hasFlag(array $parts, string $token): bool
{
    foreach ($parts as $part) {
        if (strcasecmp($part, $token) === 0) {
            return true;
        }
    }
    return false;
}

function explodeAddress(string $address): array
{
    if (strpos($address, ':') !== false) {
        [$host, $port] = explode(':', $address, 2);
        return [$host, $port];
    }
    return [$address, ''];
}

function defaultWebServers(): array
{
    return [
        [
            'name'         => 'web1',
            'ip'           => '192.168.1.10',
            'port'         => '80',
            'status'       => 'OK',
            'cookie'       => 'S1',
            'cookie_raw'   => 'S1',
            'health_check' => true,
            'disabled'     => false,
            'last_check'   => '—',
        ],
        [
            'name'         => 'web2',
            'ip'           => '192.168.1.11',
            'port'         => '80',
            'status'       => 'DOWN',
            'cookie'       => 'S2',
            'cookie_raw'   => 'S2',
            'health_check' => true,
            'disabled'     => false,
            'last_check'   => '—',
        ],
    ];
}

function defaultDatabaseServers(): array
{
    return [
        ['name' => 'mysql1', 'ip' => '192.168.1.10', 'port' => '3306', 'status' => 'OK', 'role' => 'Master-Master', 'role_raw' => 'Master-Master', 'gtid' => 'ON', 'gtid_bool' => true, 'last_check' => '—', 'disabled' => false, 'health_check' => true, 'backup' => false],
        ['name' => 'mysql2', 'ip' => '192.168.1.11', 'port' => '3306', 'status' => 'OK', 'role' => 'Master-Master', 'role_raw' => 'Master-Master', 'gtid' => 'ON', 'gtid_bool' => true, 'last_check' => '—', 'disabled' => false, 'health_check' => true, 'backup' => true],
    ];
}

function findWebServer(array $servers, string $name): ?array
{
    foreach ($servers as $server) {
        if (strcasecmp($server['name'], $name) === 0) {
            return $server;
        }
    }
    return null;
}

function findDatabaseServer(array $servers, string $name): ?array
{
    foreach ($servers as $server) {
        if (strcasecmp($server['name'], $name) === 0) {
            return $server;
        }
    }
    return null;
}
