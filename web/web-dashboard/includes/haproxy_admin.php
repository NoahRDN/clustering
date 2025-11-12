<?php
const WEB_BACKEND = 'web_back';
const DB_BACKEND  = 'mysql_back';

function buildDashboardContext(): array
{
    $projectRoot = dirname(__DIR__, 2);
    return [
        'project_root'    => $projectRoot,
        'web_cfg'         => resolveFirstExisting([
            '/data/shared-config/haproxy-web.cfg',
            $projectRoot . '/shared-config/haproxy-web.cfg'
        ]),
        'db_cfg'          => resolveFirstExisting([
            '/data/shared-config/haproxy-db.cfg',
            $projectRoot . '/shared-config/haproxy-db.cfg'
        ]),
        'web_runtime_socket' => resolveFirstExisting([
            '/haproxy-runtime/admin.sock',
            '/var/run/haproxy/admin.sock',
            $projectRoot . '/haproxy-web/runtime/admin.sock'
        ]),
        'db_runtime_socket' => resolveFirstExisting([
            '/haproxy-db-runtime/admin.sock',
            '/var/run/haproxy/admin.sock',
            $projectRoot . '/haproxy-db/runtime/admin.sock'
        ]),
        'web_reload_flag'   => '/haproxy-runtime/reload.flag',
        'db_reload_flag'    => '/haproxy-db-runtime/reload.flag',
        'web_runtime_api'   => getenv('WEB_RUNTIME_API_URL') ?: null,
        'web_runtime_token' => getenv('WEB_RUNTIME_API_TOKEN') ?: null,
        'db_runtime_api'    => getenv('DB_RUNTIME_API_URL') ?: null,
        'db_runtime_token'  => getenv('DB_RUNTIME_API_TOKEN') ?: null,
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
            requestHaProxyReload(
                $ctx['web_reload_flag'] ?? null,
                'HAProxy Web',
                $ctx['web_runtime_api'] ?? null,
                $ctx['web_runtime_token'] ?? null
            );
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
        requestHaProxyReload(
            $ctx['web_reload_flag'] ?? null,
            'HAProxy Web',
            $ctx['web_runtime_api'] ?? null,
            $ctx['web_runtime_token'] ?? null
        );
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
                requestHaProxyReload(
                    $ctx['web_reload_flag'] ?? null,
                    'HAProxy Web',
                    $ctx['web_runtime_api'] ?? null,
                    $ctx['web_runtime_token'] ?? null
                );
                sendRuntimeCommand(
                    $ctx['web_runtime_api'] ?? null,
                    $ctx['web_runtime_token'] ?? null,
                    $ctx['web_runtime_socket'] ?? null,
                    "disable server " . WEB_BACKEND . "/{$server}"
                );
                addFlash('success', "Serveur {$server} retiré du backend.");
            } else {
                addFlash('error', "Impossible de retirer {$server} (introuvable).");
            }
            break;
        case 'refresh':
            if (sendRuntimeCommand(
                $ctx['web_runtime_api'] ?? null,
                $ctx['web_runtime_token'] ?? null,
                $ctx['web_runtime_socket'] ?? null,
                "show servers state " . WEB_BACKEND
            )) {
                addFlash('success', "Statut de {$server} rafraîchi (via socket runtime).");
            } else {
                addFlash('warning', "Impossible d'interroger HAProxy runtime. Pensez à recharger manuellement.");
            }
            break;
        case 'restart':
            $disabled = sendRuntimeCommand(
                $ctx['web_runtime_api'] ?? null,
                $ctx['web_runtime_token'] ?? null,
                $ctx['web_runtime_socket'] ?? null,
                "disable server " . WEB_BACKEND . "/{$server}"
            );
            $enabled = sendRuntimeCommand(
                $ctx['web_runtime_api'] ?? null,
                $ctx['web_runtime_token'] ?? null,
                $ctx['web_runtime_socket'] ?? null,
                "enable server " . WEB_BACKEND . "/{$server}"
            );
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
                $runtimeOk = sendRuntimeCommand(
                    $ctx['web_runtime_api'] ?? null,
                    $ctx['web_runtime_token'] ?? null,
                    $ctx['web_runtime_socket'] ?? null,
                    ($disable ? 'disable' : 'enable') . " server " . WEB_BACKEND . "/{$server}"
                );
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
    $role         = trim($data['role'] ?? '');
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
    if (!validateDatabaseRole($role)) {
        $errors[] = 'Rôle invalide (Master, Replica ou Master-Master).';
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
        'role'          => $role,
        'gtid'          => $gtid,
        'health_check'  => true,
    ];

    if ($operation === 'update') {
        $updated = updateDatabaseServerEntry($configPath, $payload, $originalName);
        if ($updated) {
            addFlash('success', "Instance {$originalName} mise à jour.");
            requestHaProxyReload(
                $ctx['db_reload_flag'] ?? null,
                'HAProxy DB',
                $ctx['db_runtime_api'] ?? null,
                $ctx['db_runtime_token'] ?? null
            );
        } else {
            addFlash('error', "Impossible de modifier {$originalName} (introuvable).");
        }
        return;
    }

    $line = buildDatabaseServerLine($payload);

    if (insertServerLine($configPath, DB_BACKEND, $line)) {
        addFlash('success', "Base de données {$name} ajoutée dans HAProxy.");
        requestHaProxyReload(
            $ctx['db_reload_flag'] ?? null,
            'HAProxy DB',
            $ctx['db_runtime_api'] ?? null,
            $ctx['db_runtime_token'] ?? null
        );
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
                requestHaProxyReload(
                    $ctx['db_reload_flag'] ?? null,
                    'HAProxy DB',
                    $ctx['db_runtime_api'] ?? null,
                    $ctx['db_runtime_token'] ?? null
                );
                sendRuntimeCommand(
                    $ctx['db_runtime_api'] ?? null,
                    $ctx['db_runtime_token'] ?? null,
                    $ctx['db_runtime_socket'] ?? null,
                    "disable server " . DB_BACKEND . "/{$server}"
                );
                addFlash('success', "Instance {$server} supprimée.");
            } else {
                addFlash('error', "Impossible de supprimer {$server} (introuvable).");
            }
            break;
        case 'refresh':
            if (sendRuntimeCommand(
                $ctx['db_runtime_api'] ?? null,
                $ctx['db_runtime_token'] ?? null,
                $ctx['db_runtime_socket'] ?? null,
                "show servers state " . DB_BACKEND
            )) {
                addFlash('success', "Statut de {$server} rafraîchi.");
            } else {
                addFlash('warning', "Impossible de contacter HAProxy DB (socket runtime).");
            }
            break;
        case 'restart':
            $disabled = sendRuntimeCommand(
                $ctx['db_runtime_api'] ?? null,
                $ctx['db_runtime_token'] ?? null,
                $ctx['db_runtime_socket'] ?? null,
                "disable server " . DB_BACKEND . "/{$server}"
            );
            $enabled = sendRuntimeCommand(
                $ctx['db_runtime_api'] ?? null,
                $ctx['db_runtime_token'] ?? null,
                $ctx['db_runtime_socket'] ?? null,
                "enable server " . DB_BACKEND . "/{$server}"
            );
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
                $runtimeOk = sendRuntimeCommand(
                    $ctx['db_runtime_api'] ?? null,
                    $ctx['db_runtime_token'] ?? null,
                    $ctx['db_runtime_socket'] ?? null,
                    ($disable ? 'disable' : 'enable') . " server " . DB_BACKEND . "/{$server}"
                );
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

function requestHaProxyReload(?string $flag, string $label, ?string $apiBase = null, ?string $apiToken = null): void
{
    if (runtimeApiReload($apiBase, $apiToken)) {
        return;
    }
    if (!$flag) {
        addFlash('warning', "Impossible de signaler le rechargement {$label} (chemin manquant).");
        return;
    }

    $dir = dirname($flag);
    if (!is_dir($dir) || !is_writable($dir)) {
        addFlash('warning', "Impossible de signaler le rechargement {$label} (répertoire inaccessible).");
        return;
    }

    if (@file_put_contents($flag, "reload\n") === false) {
        addFlash('warning', "Rechargement {$label} non déclenché (écriture impossible).");
    }
}

function runtimeCommand(?string $socket, string $command): bool
{
    if (!$socket || !file_exists($socket)) {
        return false;
    }

    $conn = @stream_socket_client('unix://' . $socket, $errno, $errStr, 1);
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

function runtimeApiCommand(?string $baseUrl, ?string $token, string $command): bool
{
    if (!$baseUrl) {
        return false;
    }
    $endpoint = rtrim($baseUrl, '/') . '/execute';
    return runtimeApiPost($endpoint, ['command' => $command], $token);
}

function runtimeApiReload(?string $baseUrl, ?string $token): bool
{
    if (!$baseUrl) {
        return false;
    }
    $endpoint = rtrim($baseUrl, '/') . '/reload';
    return runtimeApiPost($endpoint, [], $token);
}

function runtimeApiPost(string $url, array $body, ?string $token): bool
{
    if ($token !== null && $token !== '') {
        $body['token'] = $token;
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 3,
        ],
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return false;
    }
    $decoded = json_decode($response, true);
    if (is_array($decoded) && array_key_exists('success', $decoded)) {
        return (bool) $decoded['success'];
    }
    return true;
}

function sendRuntimeCommand(?string $apiBase, ?string $apiToken, ?string $socket, string $command): bool
{
    if (runtimeApiCommand($apiBase, $apiToken, $command)) {
        return true;
    }
    return runtimeCommand($socket, $command);
}

function validateIdentifier(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
}

function validateHost(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value) || filter_var($value, FILTER_VALIDATE_IP);
}

function validateDatabaseRole(string $role): bool
{
    return in_array($role, ['Master', 'Replica', 'Master-Master'], true);
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

function alterServerLine(string $path, string $backend, string $targetName, callable $callback): bool
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
                $definition = parseServerDefinition($rawLine);
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
        $line = buildDatabaseServerLine($definition);
        return $indent . $line;
    });
}

function setDatabaseServerDisabled(string $path, string $name, bool $disable): bool
{
    return alterServerLine($path, DB_BACKEND, $name, function (array $definition, string $indent) use ($disable) {
        $definition['disabled'] = $disable;
        $line = buildDatabaseServerLine($definition);
        return $indent . $line;
    });
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
    $meta      = parseMetaComment($comment);
    $roleRaw   = $meta['role'] ?? 'GTID Sync';
    $roleValue = in_array($roleRaw, ['Master', 'Replica', 'Master-Master'], true) ? $roleRaw : 'Master';
    $gtid      = strtolower($meta['gtid'] ?? 'on');

    return [
        'name'          => $parts[1],
        'host'          => $host,
        'port'          => $port,
        'role'          => $roleValue,
        'role_label'    => $roleRaw,
        'gtid'          => $gtid === 'on',
        'gtid_label'    => $gtid,
        'health_check'  => hasFlag($parts, 'check'),
        'disabled'      => hasFlag($parts, 'disabled'),
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
        ['name' => 'mysql1', 'ip' => '192.168.1.10', 'port' => '3306', 'status' => 'OK', 'role' => 'GTID Sync', 'role_raw' => 'Master', 'gtid' => 'ON', 'gtid_bool' => true, 'last_check' => '—', 'disabled' => false, 'health_check' => true],
        ['name' => 'mysql2', 'ip' => '192.168.1.11', 'port' => '3306', 'status' => 'OK', 'role' => 'GTID Sync', 'role_raw' => 'Replica', 'gtid' => 'ON', 'gtid_bool' => true, 'last_check' => '—', 'disabled' => false, 'health_check' => true],
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
