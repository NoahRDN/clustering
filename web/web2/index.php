<?php
session_start();

require_once __DIR__ . '/../shared/session_activity.php';

function formatSessionValue(mixed $value): string
{
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }

    return trim(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$server     = gethostname();
$sessionId  = session_id() ?: '—';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
$userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? 'Inconnu';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $favorite = trim($_POST['color'] ?? '');
    $_SESSION['favorite_color'] = $favorite;

    sessionActivityRecord($server, $remoteAddr, $favorite, [
        'user_agent' => $userAgent,
        'path'       => $_SERVER['REQUEST_URI'] ?? '/',
        'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    ]);

    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
    exit;
}

$visitorActivity = sessionActivityList($server);
$sessionSnapshot = $_SESSION;
$shortSessionId  = strlen($sessionId) > 12 ? substr($sessionId, 0, 12) . '…' : $sessionId;
$currentColor    = $_SESSION['favorite_color'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Sessions locales · <?= htmlspecialchars($server) ?></title>
<style>
    :root { color-scheme: light; font-family: "Segoe UI", Roboto, Arial, sans-serif; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        background: radial-gradient(circle at top, #f4f8ff, #e2e8f5);
        display: flex;
        justify-content: center;
        padding: 32px 16px;
        color: #152033;
    }
    .app-shell {
        width: min(960px, 100%);
        background: #fff;
        border-radius: 22px;
        border: 1px solid rgba(41, 78, 152, 0.15);
        box-shadow: 0 25px 60px rgba(25, 54, 109, 0.15);
        padding: 32px;
    }
    .hero {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    .hero h1 { margin: 0; font-size: 30px; color: #103082; }
    .hero .subtitle { margin: 6px 0 0; color: #4a5d7a; max-width: 520px; line-height: 1.4; }
    .eyebrow { text-transform: uppercase; font-size: 12px; letter-spacing: 0.2em; color: #5b6f92; }
    .chip-stack { display: flex; flex-wrap: wrap; gap: 8px; }
    .chip {
        padding: 8px 14px;
        border-radius: 999px;
        background: #f1f5ff;
        border: 1px solid rgba(34, 75, 160, 0.2);
        font-size: 13px;
        color: #0f3272;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin: 24px 0;
    }
    .stat-card {
        padding: 16px;
        border-radius: 16px;
        background: rgba(45, 108, 223, 0.05);
        border: 1px solid rgba(20, 59, 145, 0.12);
    }
    .stat-card span { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: 0.12em; color: #5d6c8b; margin-bottom: 6px; }
    .stat-card strong { font-size: 18px; color: #0b2351; }
    .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
    .btn {
        border: 1px solid transparent;
        border-radius: 999px;
        background: #1b4fd7;
        color: #fff;
        padding: 10px 18px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }
    .btn-secondary { background: #eff2fb; color: #143463; border-color: rgba(20, 52, 99, 0.2); }
    .panel {
        background: #f8faff;
        border-radius: 18px;
        padding: 20px;
        border: 1px solid rgba(20, 59, 145, 0.08);
        margin-bottom: 24px;
    }
    .panel h2 { margin: 0 0 8px; font-size: 20px; color: #10224d; }
    .panel p.description { margin: 0 0 18px; color: #5a6785; }
    .form-layout { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    label { font-size: 14px; font-weight: 600; color: #19294f; }
    input[type="text"] {
        width: min(320px, 100%);
        border-radius: 12px;
        border: 1px solid rgba(15, 34, 78, 0.2);
        padding: 12px 14px;
        font-size: 15px;
        margin-top: 6px;
    }
    .session-data { display: flex; flex-direction: column; gap: 10px; }
    .session-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 12px;
        border-radius: 14px;
        background: #fff;
        border: 1px solid rgba(16, 39, 90, 0.08);
    }
    .session-key { font-weight: 700; color: #0c2a64; min-width: 120px; }
    .session-value {
        font-family: "Fira Code", Menlo, Consolas, monospace;
        background: #0f172a;
        color: #e2e8f0;
        padding: 6px 8px;
        border-radius: 8px;
        flex: 1;
        min-width: 200px;
        white-space: pre-wrap;
    }
    .timeline { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; }
    .timeline-entry {
        padding: 14px;
        border-radius: 14px;
        background: #fff;
        border: 1px solid rgba(12, 30, 72, 0.08);
    }
    .timeline-head { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
    .ip-tag { font-weight: 600; color: #0d254f; }
    .hits { color: #64748b; font-size: 13px; }
    .history-items { display: flex; flex-direction: column; gap: 8px; }
    .history-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 14px; }
    .history-row time { font-family: "Fira Code", monospace; color: #475569; }
    .history-value { font-weight: 600; color: #0f172a; }
    .empty-state { margin: 0; color: #687898; font-style: italic; }
    @media (max-width: 600px) {
        .app-shell { padding: 24px 18px; }
        .hero h1 { font-size: 24px; }
        input[type="text"] { width: 100%; }
    }
</style>
</head>
<body>
    <div class="app-shell">
        <div class="hero">
            <div>
                <p class="eyebrow">Sessions locales</p>
                <h1>Observatoire des sessions sur <?= htmlspecialchars($server) ?></h1>
                <p class="subtitle">Toutes les valeurs sont stockées sur ce serveur PHP (filesystem). Usez des boutons
                    pour tester la persistance HAProxy.</p>
            </div>
            <div class="chip-stack">
                <span class="chip">Session&nbsp;#<?= htmlspecialchars($shortSessionId) ?></span>
                <span class="chip">Serveur&nbsp;<?= htmlspecialchars($server) ?></span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span>Adresse IP</span>
                <strong><?= htmlspecialchars($remoteAddr) ?></strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;">User-Agent&nbsp;: <?= htmlspecialchars($userAgent) ?></p>
            </div>
            <div class="stat-card">
                <span>Dernière valeur</span>
                <strong><?= htmlspecialchars($currentColor !== '' ? $currentColor : '—') ?></strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;">Champ «&nbsp;Couleur préférée&nbsp;»</p>
            </div>
            <div class="stat-card">
                <span>Mode</span>
                <strong>Session locale</strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;">Cookie PHP + stickiness HAProxy.</p>
            </div>
        </div>

        <div class="actions">
            <form method="get">
                <button type="submit" class="btn">🔄 Rafraîchir</button>
            </form>
            <button type="button" class="btn btn-secondary clear-client-state">Effacer les cookies (test)</button>
        </div>

        <section class="panel">
            <h2>Couleur préférée</h2>
            <p class="description">Modifie la valeur stockée dans la session PHP locale.</p>
            <form method="post" class="form-layout">
                <label>
                    Valeur saisie
                    <input type="text" name="color" value="<?= htmlspecialchars($currentColor) ?>" placeholder="Ex. bleu cobalt">
                </label>
                <button type="submit" class="btn">Enregistrer</button>
            </form>
        </section>

        <section class="panel">
            <h2>Données de session</h2>
            <?php if ($sessionSnapshot): ?>
                <div class="session-data">
                    <?php foreach ($sessionSnapshot as $key => $value): ?>
                        <div class="session-row">
                            <span class="session-key"><?= htmlspecialchars((string) $key) ?></span>
                            <span class="session-value"><?= htmlspecialchars(formatSessionValue($value)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-state">Aucune donnée de session enregistrée pour le moment.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Historique des visiteurs (scope <?= htmlspecialchars($server) ?>)</h2>
            <?php if ($visitorActivity): ?>
                <ul class="timeline">
                    <?php foreach ($visitorActivity as $entry): ?>
                        <?php $historyItems = array_slice($entry['history'] ?? [], 0, 5); ?>
                        <li class="timeline-entry">
                            <div class="timeline-head">
                                <span class="ip-tag"><?= htmlspecialchars($entry['ip'] ?? 'N/A') ?></span>
                                <span class="hits"><?= (int)($entry['hits'] ?? 0) ?> visites</span>
                                <span class="hits">
                                    <?= isset($entry['last_update']) ? date('d/m/Y H:i:s', (int) $entry['last_update']) : '—' ?>
                                </span>
                            </div>
                            <?php if ($historyItems): ?>
                                <div class="history-items">
                                    <?php foreach ($historyItems as $item): ?>
                                        <div class="history-row">
                                            <time><?= isset($item['timestamp']) ? date('H:i:s', (int) $item['timestamp']) : '—' ?></time>
                                            <span class="history-value"><?= htmlspecialchars($item['value'] ?? '—') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="empty-state">Aucune valeur enregistrée pour cette IP.</p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="empty-state">Aucune modification enregistrée pour le moment.</p>
            <?php endif; ?>
        </section>
    </div>
    <script>
    document.querySelectorAll('.clear-client-state').forEach(function(btn) {
        btn.addEventListener('click', function () {
            ['SRV', 'PHPSESSID'].forEach(function (cookieName) {
                document.cookie = cookieName + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
            });
            try { localStorage.clear(); } catch (e) {}
            try { sessionStorage.clear(); } catch (e) {}
            window.location.reload();
        });
    });
    </script>
</body>
</html>
