<?php
require_once __DIR__ . '/../shared/session_router.php';
require_once __DIR__ . '/../shared/session_activity.php';

initAppSession(true);

const DATA_SCOPE_SESSION = 'session';
const DATA_SCOPE_GLOBAL  = 'global';
const DATA_SCOPE_COOKIE  = 'SESSION_DATA_SCOPE';
const GLOBAL_PREF_KEY    = 'favorite_color';

function formatSessionValue(mixed $value): string
{
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }

    return trim(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function loadGlobalPreference(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT pref_value FROM global_preferences WHERE pref_key = :key');
    $stmt->execute(['key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

function saveGlobalPreference(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO global_preferences (pref_key, pref_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
}

$dsn = 'mysql:host=haproxy-db;port=3307;dbname=clustering;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$scope = $_COOKIE[DATA_SCOPE_COOKIE] ?? DATA_SCOPE_SESSION;
if (!in_array($scope, [DATA_SCOPE_SESSION, DATA_SCOPE_GLOBAL], true)) {
    $scope = DATA_SCOPE_SESSION;
}

if (!empty($_GET['scope_mode']) && in_array($_GET['scope_mode'], [DATA_SCOPE_SESSION, DATA_SCOPE_GLOBAL], true)) {
    $scope = $_GET['scope_mode'];
    setcookie(DATA_SCOPE_COOKIE, $scope, time() + 365 * 24 * 60 * 60, '/');
    $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? 'index-db.php', '?') ?: 'index-db.php';
    header('Location: ' . $redirectTarget);
    exit;
}

$server     = gethostname();
$sessionId  = session_id() ?: '—';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
$userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? 'Inconnu';
$historyKey = $scope === DATA_SCOPE_GLOBAL ? 'global_preference' : ($sessionId ?: 'session');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('color', $_POST)) {
    $favorite = trim((string)($_POST['color'] ?? ''));

    if ($scope === DATA_SCOPE_GLOBAL) {
        saveGlobalPreference($pdo, GLOBAL_PREF_KEY, $favorite);
    } else {
        $_SESSION['favorite_color'] = $favorite;
    }

    sessionActivityRecord($historyKey, $remoteAddr, $favorite, [
        'user_agent' => $userAgent,
        'path'       => $_SERVER['REQUEST_URI'] ?? '/',
        'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'scope'      => $scope,
    ]);

    $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? 'index-db.php', '?') ?: 'index-db.php';
    header('Location: ' . $redirectTarget);
    exit;
}

if ($scope === DATA_SCOPE_GLOBAL) {
    $favoriteValue = loadGlobalPreference($pdo, GLOBAL_PREF_KEY) ?? '';
} else {
    $favoriteValue = $_SESSION['favorite_color'] ?? '';
}

$visitorActivity = sessionActivityList($historyKey);
$sessionSnapshot = $_SESSION;
$shortSessionId  = strlen($sessionId) > 12 ? substr($sessionId, 0, 12) . '…' : $sessionId;
$scopeLabel      = $scope === DATA_SCOPE_GLOBAL ? 'Mode global (valeur unique pour tous)' : 'Mode session (valeur isolée)';
$scopeHint       = $scope === DATA_SCOPE_GLOBAL
    ? 'Toute mise à jour est visible immédiatement par chaque navigateur.'
    : 'La valeur est stockée dans la session PHP de ce navigateur.';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Sessions en base de données · <?= htmlspecialchars($server) ?></title>
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
    .hero h1 {
        margin: 0;
        font-size: 30px;
        color: #103082;
    }
    .hero .subtitle {
        margin: 6px 0 0;
        color: #4a5d7a;
        max-width: 520px;
        line-height: 1.4;
    }
    .eyebrow {
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: 0.2em;
        color: #5b6f92;
    }
    .chip-stack {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .chip {
        padding: 8px 14px;
        border-radius: 999px;
        background: #f1f5ff;
        border: 1px solid rgba(34, 75, 160, 0.2);
        font-size: 13px;
        color: #0f3272;
    }
    .scope-toggle {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin: 18px 0 0;
        align-items: center;
    }
    .scope-toggle span { font-weight: 600; color: #1a2c55; }
    .scope-btn {
        border-radius: 999px;
        padding: 8px 16px;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid rgba(11, 35, 82, 0.2);
        color: #0d2d6b;
        background: #eef2ff;
    }
    .scope-btn.active { background: #1b4fd7; color: #fff; border-color: #153ca5; }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin: 24px 0;
    }
    .stat-card {
        padding: 16px;
        border-radius: 16px;
        background: rgba(45, 108, 223, 0.06);
        border: 1px solid rgba(20, 59, 145, 0.12);
    }
    .stat-card span {
        display: block;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #5d6c8b;
        margin-bottom: 6px;
    }
    .stat-card strong { font-size: 18px; color: #0b2351; }
    .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 24px;
    }
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
    .btn-secondary {
        background: #eff2fb;
        color: #143463;
        border-color: rgba(20, 52, 99, 0.2);
    }
    .panel {
        background: #f8faff;
        border-radius: 18px;
        padding: 20px;
        border: 1px solid rgba(20, 59, 145, 0.08);
        margin-bottom: 24px;
    }
    .panel h2 { margin: 0 0 8px; font-size: 20px; color: #10224d; }
    .panel p.description { margin: 0 0 18px; color: #5a6785; }
    .form-layout {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
    }
    label { font-size: 14px; font-weight: 600; color: #19294f; }
    input[type="text"] {
        width: min(320px, 100%);
        border-radius: 12px;
        border: 1px solid rgba(15, 34, 78, 0.2);
        padding: 12px 14px;
        font-size: 15px;
        margin-top: 6px;
    }
    .session-data {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
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
    .timeline {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .timeline-entry {
        padding: 14px;
        border-radius: 14px;
        background: #fff;
        border: 1px solid rgba(12, 30, 72, 0.08);
    }
    .timeline-head {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 10px;
    }
    .ip-tag { font-weight: 600; color: #0d254f; }
    .hits { color: #64748b; font-size: 13px; }
    .history-items { display: flex; flex-direction: column; gap: 8px; }
    .history-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        font-size: 14px;
    }
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
                <p class="eyebrow">Sessions partagées</p>
                <h1>Observatoire des sessions en base</h1>
                <p class="subtitle">
                    <?= htmlspecialchars($server) ?> peut stocker la couleur soit dans la session PHP, soit dans
                    une préférence globale partagée par l’ensemble des clients.
                </p>
                <div class="scope-toggle">
                    <span>Source de stockage :</span>
                    <a class="scope-btn <?= $scope === DATA_SCOPE_SESSION ? 'active' : '' ?>" href="?scope_mode=session">
                        Session individuelle
                    </a>
                    <a class="scope-btn <?= $scope === DATA_SCOPE_GLOBAL ? 'active' : '' ?>" href="?scope_mode=global">
                        Valeur globale
                    </a>
                </div>
            </div>
            <div class="chip-stack">
                <span class="chip">Session&nbsp;#<?= htmlspecialchars($shortSessionId) ?></span>
                <span class="chip">Mode&nbsp;: <?= htmlspecialchars($scope === DATA_SCOPE_GLOBAL ? 'Global' : 'Session') ?></span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span>Adresse IP</span>
                <strong><?= htmlspecialchars($remoteAddr) ?></strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;">User-Agent&nbsp;: <?= htmlspecialchars($userAgent) ?></p>
            </div>
            <div class="stat-card">
                <span>Mode d'écriture</span>
                <strong><?= htmlspecialchars($scopeLabel) ?></strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;"><?= htmlspecialchars($scopeHint) ?></p>
            </div>
            <div class="stat-card">
                <span>Valeur suivie</span>
                <strong><?= htmlspecialchars($favoriteValue !== '' ? $favoriteValue : '—') ?></strong>
                <p style="margin:6px 0 0; font-size:12px; color:#54617e;">Champ «&nbsp;Couleur préférée&nbsp;»</p>
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
            <p class="description">
                <?php if ($scope === DATA_SCOPE_GLOBAL): ?>
                    La valeur est enregistrée dans la table <code>global_preferences</code> et partagée par tous les navigateurs.
                <?php else: ?>
                    La valeur est stockée dans la session PHP courante (identifiée via le handler en base).
                <?php endif; ?>
            </p>
            <form method="post" class="form-layout">
                <label>
                    Valeur saisie
                    <input type="text" name="color" value="<?= htmlspecialchars($favoriteValue) ?>" placeholder="Ex. vert néon">
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
            <?php if ($scope === DATA_SCOPE_GLOBAL): ?>
                <div class="session-data" style="margin-top:16px;">
                    <div class="session-row">
                        <span class="session-key">favorite_color (global)</span>
                        <span class="session-value"><?= htmlspecialchars($favoriteValue !== '' ? $favoriteValue : '—') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Historique de cette source</h2>
            <p class="description">
                Le suivi est indexé sur «&nbsp;<?= htmlspecialchars($historyKey) ?>&nbsp;». Les événements listés
                sont donc communs à tous en mode global, ou spécifiques à cette session sinon.
            </p>
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
            ['SRV', 'PHPSESSID', 'SESSION_DATA_SCOPE'].forEach(function (cookieName) {
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
