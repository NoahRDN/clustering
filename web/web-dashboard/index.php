<?php
$webServers = [
    ['name' => 'web1', 'ip' => '192.168.1.10', 'status' => 'OK', 'cookie' => 'S1', 'last_check' => 'il y a 5s'],
    ['name' => 'web2', 'ip' => '192.168.1.11', 'status' => 'DOWN', 'cookie' => 'S2', 'last_check' => 'il y a 2s'],
];

$dbServers = [
    ['name' => 'mysql1', 'ip' => '192.168.1.10', 'status' => 'OK', 'role' => 'GTID Sync', 'last_check' => 'il y a 3s'],
    ['name' => 'mysql2', 'ip' => '192.168.1.11', 'status' => 'OK', 'role' => 'GTID Sync', 'last_check' => 'il y a 3s'],
];
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
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .dashboard {
            width: min(1040px, 100%);
            background: #ffffff;
            border: 2px solid rgba(17, 66, 255, 0.15);
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 25px 70px rgba(24, 62, 125, 0.15);
        }
        h1 {
            margin: 0 0 16px;
            font-size: 28px;
            display: flex;
            gap: 12px;
            align-items: center;
            color: #0c4ec9;
        }
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .actions-left,
        .actions-right {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        button {
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            color: #042240;
            box-shadow: 0 4px 12px rgba(4, 34, 64, 0.08);
        }
        button.secondary {
            background: #eff4ff;
            color: #1b3c6a;
            border: 1px solid rgba(9, 41, 87, 0.18);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 24px;
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
        td.actions-cell {
            padding: 10px 0;
        }
        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .row-actions button {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 30px;
            border: 1px solid rgba(3, 44, 122, 0.2);
            background: #fff;
            color: #10305f;
        }
        .status {
            font-weight: 600;
        }
        .status.ok {
            color: #089f64;
        }
        .status.down {
            color: #d73952;
        }
        section h2 {
            margin: 0;
            font-size: 18px;
            color: #0e1b3c;
        }
        section {
            margin-bottom: 24px;
            padding: 16px;
            border-radius: 14px;
            background: rgba(33, 95, 225, 0.04);
            border: 1px solid rgba(18, 59, 135, 0.08);
        }
        .terminal {
            font-family: "Fira Code", Consolas, monospace;
            white-space: pre;
            background: #f4f7ff;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid rgba(28, 65, 140, 0.12);
            margin-top: 8px;
            color: #37537a;
        }
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(4, 18, 52, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 100;
        }
        .modal.visible {
            display: flex;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 28px;
            width: min(520px, 100%);
            box-shadow: 0 30px 80px rgba(12, 34, 79, 0.25);
        }
        .modal-card h3 {
            margin: 0 0 18px;
            color: #0c2960;
        }
        .modal-card label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
            color: #13396e;
            margin-bottom: 12px;
        }
        .modal-card input,
        .modal-card select {
            border-radius: 10px;
            border: 1px solid rgba(15, 45, 115, 0.2);
            padding: 10px 12px;
            font-size: 14px;
        }
        .modal-card .checkbox {
            flex-direction: row;
            align-items: center;
            gap: 8px;
        }
        .modal-card .helper {
            color: #5f749c;
            font-size: 12px;
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 16px;
        }
        .modal-actions button {
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 600;
        }
        .modal-actions .primary {
            background: #2563eb;
            color: #fff;
        }
        .modal-actions .ghost {
            background: transparent;
            border: 1px solid rgba(14, 30, 68, 0.3);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <h1>🧭 HAProxy Cluster Dashboard</h1>
        <div class="actions">
            <div class="actions-left">
                <button data-open-modal="webModal" style="background:#4dd0e1;">+ Ajouter un serveur Web</button>
                <button data-open-modal="dbModal" style="background:#ffb74d;">+ Ajouter une base MySQL</button>
            </div>
            <div class="actions-right">
                <button class="secondary">🔄 Rafraîchir</button>
            </div>
        </div>

        <section>
            <h2>🌐 Serveurs Web</h2>
            <table>
                <thead>
                    <tr>
                        <th>Serveur</th>
                        <th>IP</th>
                        <th>Statut</th>
                        <th>Cookie</th>
                        <th>Dernier check</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($webServers as $server): ?>
                    <tr>
                        <td><?= htmlspecialchars($server['name']) ?></td>
                        <td><?= htmlspecialchars($server['ip']) ?></td>
                        <td class="status <?= strtolower($server['status']) === 'ok' ? 'ok' : 'down' ?>">
                            <?= htmlspecialchars($server['status']) ?>
                        </td>
                        <td><?= htmlspecialchars($server['cookie']) ?></td>
                        <td><?= htmlspecialchars($server['last_check']) ?></td>
                        <td class="actions-cell">
                            <div class="row-actions">
                                <button type="button">Modifier</button>
                                <button type="button">Supprimer</button>
                                <button type="button">Rafraîchir état</button>
                                <button type="button">Redémarrer</button>
                                <button type="button">Désactiver</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section>
            <h2>🗄️ Bases de Données</h2>
            <table>
                <thead>
                    <tr>
                        <th>Instance</th>
                        <th>IP</th>
                        <th>Statut</th>
                        <th>Rôle</th>
                        <th>Dernier check</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dbServers as $db): ?>
                    <tr>
                        <td><?= htmlspecialchars($db['name']) ?></td>
                        <td><?= htmlspecialchars($db['ip']) ?></td>
                        <td class="status ok"><?= htmlspecialchars($db['status']) ?></td>
                        <td><?= htmlspecialchars($db['role']) ?></td>
                        <td><?= htmlspecialchars($db['last_check']) ?></td>
                        <td class="actions-cell">
                            <div class="row-actions">
                                <button type="button">Modifier</button>
                                <button type="button">Supprimer</button>
                                <button type="button">Rafraîchir état</button>
                                <button type="button">Redémarrer</button>
                                <button type="button">Désactiver</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <div class="modal" id="webModal" aria-hidden="true">
        <div class="modal-card">
            <h3>Ajouter un serveur Web</h3>
            <form id="webForm">
                <label>
                    Nom du serveur
                    <span class="helper">Nom logique unique (ex: web3)</span>
                    <input type="text" name="name" placeholder="web3" required>
                </label>
                <label>
                    Adresse IP / Nom d’hôte
                    <span class="helper">Adresse Docker ou IP (ex: 172.18.0.10)</span>
                    <input type="text" name="host" placeholder="172.18.0.10" required>
                </label>
                <label>
                    Port
                    <span class="helper">Port HTTP (ex: 80)</span>
                    <input type="number" name="port" placeholder="80" required>
                </label>
                <label>
                    Cookie (optionnel)
                    <span class="helper">Identifiant sticky session (ex: S3)</span>
                    <input type="text" name="cookie" placeholder="S3">
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="health_check" checked>
                    Activer le health-check ?
                </label>
                <div class="modal-actions">
                    <button type="button" class="ghost" data-close-modal>Annuler</button>
                    <button type="submit" class="primary">Ajouter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="dbModal" aria-hidden="true">
        <div class="modal-card">
            <h3>Ajouter une base de données</h3>
            <form id="dbForm">
                <label>
                    Nom du serveur
                    <span class="helper">Identifiant interne (ex: mysql3)</span>
                    <input type="text" name="name" placeholder="mysql3" required>
                </label>
                <label>
                    Adresse IP / Nom d’hôte
                    <span class="helper">Host Docker ou IP (ex: 172.18.0.11)</span>
                    <input type="text" name="host" placeholder="172.18.0.11" required>
                </label>
                <label>
                    Port
                    <span class="helper">Port MySQL (ex: 3306)</span>
                    <input type="number" name="port" placeholder="3306" required>
                </label>
                <label>
                    Rôle du serveur
                    <span class="helper">Master, Replica ou Master-Master</span>
                    <select name="role" required>
                        <option value="" disabled selected>Choisir un rôle</option>
                        <option value="Master">Master</option>
                        <option value="Replica">Replica</option>
                        <option value="Master-Master">Master-Master</option>
                    </select>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="gtid" checked>
                    GTID activé ?
                </label>
                <div class="modal-actions">
                    <button type="button" class="ghost" data-close-modal>Annuler</button>
                    <button type="submit" class="primary">Ajouter</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const openButtons = document.querySelectorAll('[data-open-modal]');
        const closeButtons = document.querySelectorAll('[data-close-modal]');

        function toggleModal(id, show) {
            const modal = document.getElementById(id);
            if (!modal) return;
            if (show) {
                modal.classList.add('visible');
                modal.setAttribute('aria-hidden', 'false');
            } else {
                modal.classList.remove('visible');
                modal.setAttribute('aria-hidden', 'true');
            }
        }

        openButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-open-modal');
                toggleModal(target, true);
            });
        });

        closeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                toggleModal(btn.closest('.modal').id, false);
            });
        });

        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', e => {
                if (e.target === modal) {
                    toggleModal(modal.id, false);
                }
            });
        });

        window.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.visible').forEach(modal => toggleModal(modal.id, false));
            }
        });

        function fakeSubmit(formName, data) {
            alert(`[Simulation] ${formName} créé avec:\n` + JSON.stringify(data, null, 2));
        }

        document.getElementById('webForm').addEventListener('submit', e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(e.target).entries());
            data.health_check = e.target.health_check.checked;
            fakeSubmit('Serveur web', data);
            toggleModal('webModal', false);
            e.target.reset();
        });

        document.getElementById('dbForm').addEventListener('submit', e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(e.target).entries());
            data.gtid = e.target.gtid.checked;
            fakeSubmit('Base de données', data);
            toggleModal('dbModal', false);
            e.target.reset();
        });
    </script>
</body>
</html>
