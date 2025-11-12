<?php
session_start();
require __DIR__ . '/includes/haproxy_admin.php';

$ctx         = buildDashboardContext();
$messages    = consumeFlashes();
$webServers  = loadWebServers($ctx);
$dbServers   = loadDatabaseServers($ctx);

$webFormMode = 'create';
$webFormData = [
    'name'         => '',
    'host'         => '',
    'port'         => '80',
    'cookie'       => '',
    'health_check' => true,
];

$dbFormMode = 'create';
$dbFormData = [
    'name'         => '',
    'host'         => '',
    'port'         => '3306',
    'role'         => '',
    'gtid'         => true,
];

$editWebName = isset($_GET['edit_web']) ? trim($_GET['edit_web']) : '';
if ($editWebName !== '') {
    $current = findWebServer($webServers, $editWebName);
    if ($current) {
        $webFormMode            = 'update';
        $webFormData['name']    = $current['name'];
        $webFormData['host']    = $current['ip'];
        $webFormData['port']    = $current['port'] ?: '80';
        $webFormData['cookie']  = $current['cookie_raw'] ?? '';
        $webFormData['health_check'] = $current['health_check'];
    } else {
        $messages[] = ['type' => 'warning', 'text' => "Serveur {$editWebName} introuvable dans la configuration."];
    }
}

$editDbName = isset($_GET['edit_db']) ? trim($_GET['edit_db']) : '';
if ($editDbName !== '') {
    $currentDb = findDatabaseServer($dbServers, $editDbName);
    if ($currentDb) {
        $dbFormMode             = 'update';
        $dbFormData['name']     = $currentDb['name'];
        $dbFormData['host']     = $currentDb['ip'];
        $dbFormData['port']     = $currentDb['port'] ?: '3306';
        $dbFormData['role']     = $currentDb['role_raw'] ?? $currentDb['role'];
        $dbFormData['gtid']     = $currentDb['gtid_bool'] ?? true;
    } else {
        $messages[] = ['type' => 'warning', 'text' => "Instance {$editDbName} introuvable dans la configuration."];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>HAProxy Cluster Dashboard</title>
    <style>
        :root {
            color-scheme: light;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
            background: linear-gradient(180deg, #f7fbff, #e3ebf7);
            color: #1f2a37;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 24px;
        }
        .dashboard {
            width: min(920px, 100%);
            background: #ffffff;
            border: 2px solid rgba(17, 66, 255, 0.15);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 25px 70px rgba(24, 62, 125, 0.15);
        }
        .header {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
            display: flex;
            gap: 12px;
            align-items: center;
            color: #0c4ec9;
        }
        .stats-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .stats-button {
            border-radius: 999px;
            padding: 9px 16px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            color: #0b2352;
            background: #eaf2ff;
            border: 1px solid rgba(9, 33, 77, 0.15);
        }
        .flash-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 16px;
        }
        .flash {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            border: 1px solid transparent;
        }
        .flash.success { background: #e8f7ef; color: #0b5131; border-color: #9bd3b3; }
        .flash.error { background: #ffe9ec; color: #6e1c2b; border-color: #f6b7c1; }
        .flash.warning { background: #fff6e5; color: #7a4c00; border-color: #ffce73; }
        section {
            margin-bottom: 28px;
            padding: 18px;
            border-radius: 16px;
            background: rgba(33, 95, 225, 0.04);
            border: 1px solid rgba(18, 59, 135, 0.08);
        }
        section h2 {
            margin: 0 0 12px;
            font-size: 20px;
            color: #0e1b3c;
        }
        form.inline {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .form-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 160px;
        }
        .form-field label {
            font-size: 13px;
            font-weight: 600;
            color: #1b3158;
        }
        .form-field input,
        .form-field select {
            border-radius: 10px;
            border: 1px solid rgba(15, 45, 115, 0.2);
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .form-actions button,
        .row-actions button,
        .row-actions a {
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            color: #042240;
            background: #eff4ff;
            border: 1px solid rgba(9, 41, 87, 0.18);
            text-decoration: none;
        }
        .form-actions .primary {
            background: #2563eb;
            color: #fff;
            border-color: #1b4fd7;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th {
            text-align: left;
            font-size: 13px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #4b6fae;
            border-bottom: 1px solid rgba(9, 35, 77, 0.1);
            padding-bottom: 6px;
        }
        td {
            padding: 10px 0;
            border-bottom: 1px solid rgba(12, 53, 105, 0.08);
        }
        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .row-actions form {
            margin: 0;
        }
        .row-actions button,
        .row-actions a {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 30px;
        }
        .status { font-weight: 600; }
        .status.ok { color: #089f64; }
        .status.down { color: #d73952; }
        .reference {
            font-family: "Fira Code", Consolas, monospace;
            white-space: pre;
            background: #f4f7ff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid rgba(28, 65, 140, 0.12);
            margin-top: 8px;
            color: #37537a;
        }
    </style>
</head>
<body>
<?php
$webStatsUrl = $ctx['web_stats_url'] ?? null;
$dbStatsUrl  = $ctx['db_stats_url'] ?? null;
?>
<div class="dashboard">
    <div class="header flex">
        <h1>🧭 HAProxy Cluster Dashboard</h1>
        <div class="stats-actions">
            <?php if ($webStatsUrl): ?>
                <a class="stats-button" href="<?= htmlspecialchars($webStatsUrl) ?>" target="_blank" rel="noopener">🌐 Ouvrir stats Web</a>
            <?php endif; ?>
            <?php if ($dbStatsUrl): ?>
                <a class="stats-button" href="<?= htmlspecialchars($dbStatsUrl) ?>" target="_blank" rel="noopener">🗄️ Ouvrir stats DB</a>
            <?php endif; ?>
        </div>
    </div>

        <?php if (!empty($messages)): ?>
            <div class="flash-container">
                <?php foreach ($messages as $flash): ?>
                    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
                        <?= htmlspecialchars($flash['text']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section id="web-form">
            <h2><?= $webFormMode === 'update' ? 'Modifier un serveur Web' : 'Ajouter un serveur Web' ?></h2>
            <form class="inline" method="post" action="actions.php#web-form">
                <input type="hidden" name="form_type" value="add_web">
                <input type="hidden" name="operation" value="<?= $webFormMode ?>">
                <input type="hidden" name="original_name" value="<?= htmlspecialchars($webFormMode === 'update' ? $webFormData['name'] : '') ?>">

                <div class="form-field">
                    <label>Nom du serveur</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($webFormData['name']) ?>" placeholder="web3" required>
                </div>
                <div class="form-field">
                    <label>Adresse IP / Nom d’hôte</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($webFormData['host']) ?>" placeholder="172.18.0.10" required>
                </div>
                <div class="form-field">
                    <label>Port</label>
                    <input type="number" name="port" value="<?= htmlspecialchars($webFormData['port']) ?>" min="1" max="65535" required>
                </div>
                <div class="form-field">
                    <label>Cookie (optionnel)</label>
                    <input type="text" name="cookie" value="<?= htmlspecialchars($webFormData['cookie']) ?>" placeholder="S3">
                </div>
                <div class="form-field">
                    <label>&nbsp;</label>
                    <label style="font-size:13px; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="health_check" value="1" <?= $webFormData['health_check'] ? 'checked' : '' ?>>
                        Activer le health-check
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary"><?= $webFormMode === 'update' ? 'Mettre à jour' : 'Ajouter' ?></button>
                    <?php if ($webFormMode === 'update'): ?>
                        <a href="index.php#web-form">Annuler la modification</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section>
            <h2>🌐 Serveurs Web</h2>
            <table>
                <thead>
                    <tr>
                        <th>Serveur</th>
                        <th>IP / Hôte</th>
                        <th>Statut</th>
                        <th>Cookie</th>
                        <th>Dernier check</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($webServers as $server): ?>
                    <?php
                        $endpoint = htmlspecialchars($server['ip']);
                        if (!empty($server['port'])) {
                            $endpoint .= ':' . htmlspecialchars($server['port']);
                        }
                        $statusClass = strtolower($server['status']) === 'ok' ? 'ok' : 'down';
                        $toggleState = $server['disabled'] ? 'enable' : 'disable';
                        $toggleLabel = $server['disabled'] ? 'Réactiver' : 'Désactiver';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($server['name']) ?></td>
                        <td><?= $endpoint ?></td>
                        <td class="status <?= $statusClass ?>"><?= htmlspecialchars($server['status']) ?></td>
                        <td><?= htmlspecialchars($server['cookie']) ?></td>
                        <td><?= htmlspecialchars($server['last_check']) ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="?edit_web=<?= urlencode($server['name']) ?>#web-form">Modifier</a>
                                <form method="post" action="actions.php">
                                    <input type="hidden" name="form_type" value="web_action">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($server['name']) ?>">
                                    <button type="submit">Supprimer</button>
                                </form>
                                <form method="post" action="actions.php">
                                    <input type="hidden" name="form_type" value="web_action">
                                    <input type="hidden" name="action" value="refresh">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($server['name']) ?>">
                                    <button type="submit">Rafraîchir état</button>
                                </form>
                                <form method="post" action="actions.php">
                                    <input type="hidden" name="form_type" value="web_action">
                                    <input type="hidden" name="action" value="restart">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($server['name']) ?>">
                                    <button type="submit">Redémarrer</button>
                                </form>
                                <form method="post" action="actions.php">
                                    <input type="hidden" name="form_type" value="web_action">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="target_state" value="<?= $toggleState ?>">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($server['name']) ?>">
                                    <button type="submit"><?= $toggleLabel ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- <section id="db-form">
            <h2><?= $dbFormMode === 'update' ? 'Modifier une base de données' : 'Ajouter une base de données' ?></h2>
            <form class="inline" method="post" action="db-actions.php#db-form">
                <input type="hidden" name="form_type" value="add_db">
                <input type="hidden" name="operation" value="<?= $dbFormMode ?>">
                <input type="hidden" name="original_name" value="<?= htmlspecialchars($dbFormMode === 'update' ? $dbFormData['name'] : '') ?>">
                <div class="form-field">
                    <label>Nom du serveur</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($dbFormData['name']) ?>" placeholder="mysql3" required>
                </div>
                <div class="form-field">
                    <label>Adresse IP / Nom d’hôte</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($dbFormData['host']) ?>" placeholder="172.18.0.11" required>
                </div>
                <div class="form-field">
                    <label>Port</label>
                    <input type="number" name="port" value="<?= htmlspecialchars($dbFormData['port']) ?>" min="1" max="65535" required>
                </div>
                <div class="form-field">
                    <label>Rôle</label>
                    <select name="role" required>
                        <option value="" disabled <?= $dbFormMode === 'create' ? 'selected' : '' ?>>Choisir</option>
                        <?php foreach (['Master', 'Replica', 'Master-Master'] as $role): ?>
                            <option value="<?= $role ?>" <?= $dbFormData['role'] === $role ? 'selected' : '' ?>><?= $role ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>&nbsp;</label>
                    <label style="font-size:13px; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="gtid" value="1" <?= $dbFormData['gtid'] ? 'checked' : '' ?>>
                        GTID activé
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="primary"><?= $dbFormMode === 'update' ? 'Mettre à jour' : 'Ajouter' ?></button>
                    <?php if ($dbFormMode === 'update'): ?>
                        <a href="index.php#db-form">Annuler la modification</a>
                    <?php endif; ?>
                </div>
            </form>
        </section> -->

        <section>
            <h2>🗄️ Bases de données</h2>
            <table>
                <thead>
                    <tr>
                        <th>Instance</th>
                        <th>IP / Hôte</th>
                        <th>Statut</th>
                        <th>Rôle</th>
                        <th>Dernier check</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dbServers as $db): ?>
                    <?php
                        $dbEndpoint = htmlspecialchars($db['ip']);
                        if (!empty($db['port'])) {
                            $dbEndpoint .= ':' . htmlspecialchars($db['port']);
                        }
                        $dbStatusClass = strtolower($db['status']) === 'ok' ? 'ok' : 'down';
                        $dbToggleState = $db['disabled'] ? 'enable' : 'disable';
                        $dbToggleLabel = $db['disabled'] ? 'Réactiver' : 'Désactiver';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($db['name']) ?></td>
                        <td><?= $dbEndpoint ?></td>
                        <td class="status <?= $dbStatusClass ?>"><?= htmlspecialchars($db['status']) ?></td>
                        <td><?= htmlspecialchars($db['role']) ?> (GTID <?= htmlspecialchars($db['gtid']) ?>)</td>
                        <td><?= htmlspecialchars($db['last_check']) ?></td>
                        <td>
                            <div class="row-actions">
                                <!-- <a href="?edit_db=<?= urlencode($db['name']) ?>#db-form">Modifier</a> -->
                                <!-- <form method="post" action="db-actions.php">
                                    <input type="hidden" name="form_type" value="db_action">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($db['name']) ?>">
                                    <button type="submit" onclick="return confirm('Supprimer <?= htmlspecialchars($db['name']) ?> ?')">Supprimer</button>
                                </form>
                                <form method="post" action="db-actions.php">
                                    <input type="hidden" name="form_type" value="db_action">
                                    <input type="hidden" name="action" value="refresh">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($db['name']) ?>">
                                    <button type="submit">Rafraîchir état</button>
                                </form> -->
                                <form method="post" action="db-actions.php">
                                    <input type="hidden" name="form_type" value="db_action">
                                    <input type="hidden" name="action" value="restart">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($db['name']) ?>">
                                    <button type="submit">Redémarrer</button>
                                </form>
                                <form method="post" action="db-actions.php">
                                    <input type="hidden" name="form_type" value="db_action">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="target_state" value="<?= $dbToggleState ?>">
                                    <input type="hidden" name="server" value="<?= htmlspecialchars($db['name']) ?>">
                                    <button type="submit"><?= $dbToggleLabel ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
</body>
</html>
