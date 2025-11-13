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
}

$visitorActivity = sessionActivityList($server);
$sessionSnapshot = $_SESSION;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Session locale - <?= htmlspecialchars($server) ?></title>
<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f5f7ff; color: #10172a; }
form, .panel { background: #fff; padding: 16px; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); margin-bottom: 20px; }
label { display: block; font-weight: bold; margin-bottom: 8px; }
input[type="text"] { padding: 8px 10px; border-radius: 8px; border: 1px solid #d0d7ee; width: 100%; max-width: 320px; }
button { margin-top: 10px; padding: 10px 18px; border-radius: 999px; border: none; background: #2563eb; color: white; font-weight: bold; cursor: pointer; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 14px; }
th { text-transform: uppercase; font-size: 12px; letter-spacing: 0.05em; color: #475569; }
.empty { font-style: italic; color: #64748b; }
.actions { display: flex; gap: 12px; margin-bottom: 20px; }
.actions button { margin-top: 0; background: #0f172a; }
.history-list { margin: 0; padding-left: 18px; }
.history-list li { margin-bottom: 4px; font-size: 13px; }
.history-list time { display: inline-block; min-width: 130px; color: #475569; }
.session-data { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.session-row { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
.session-key { font-weight: 600; min-width: 120px; color: #0f172a; }
.session-value { font-family: "Fira Code", Menlo, Consolas, monospace; background: #0f172a; color: #e2e8f0; padding: 6px 8px; border-radius: 8px; flex: 1; white-space: pre-wrap; }
</style>
</head>
<body style="font-family: Arial; padding: 20px;">
<h1>Serveur <strong><?= htmlspecialchars($server) ?></strong></h1>
<p>Adresse IP: <strong><?= htmlspecialchars($remoteAddr) ?></strong></p>

<div class="actions">
    <form method="get">
        <button type="submit">Rafraîchir la page</button>
    </form>
    <button type="button" class="clear-client-state">Effacer les cookies (test)</button>
</div>

<form method="post">
  <label>Ta couleur préférée :
    <input type="text" name="color" value="<?= $_SESSION['favorite_color'] ?? '' ?>">
  </label>
  <button type="submit">Enregistrer</button>
</form>

<div class="panel">
    <h2>Session actuelle</h2>
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
        <p class="empty">Aucune donnée de session enregistrée pour le moment.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Historique des visiteurs</h2>
    <?php if ($visitorActivity): ?>
        <table>
            <thead>
                <tr>
                    <th>Adresse IP</th>
                    <th>Historique des valeurs</th>
                    <th>Dernière mise à jour</th>
                    <th>Visites</th>
                    <th>User Agent</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($visitorActivity as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['ip'] ?? 'N/A') ?></td>
                    <td>
                        <?php
                            $historyItems = array_slice($entry['history'] ?? [], 0, 5);
                        ?>
                        <?php if ($historyItems): ?>
                            <ul class="history-list">
                            <?php foreach ($historyItems as $item): ?>
                                <li>
                                    <time><?= isset($item['timestamp']) ? date('Y-m-d H:i:s', (int)$item['timestamp']) : '—' ?></time>
                                    <?= htmlspecialchars($item['value'] ?? '—') ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="empty">Aucune valeur enregistrée.</span>
                        <?php endif; ?>
                    </td>
                    <td><?= isset($entry['last_update']) ? date('Y-m-d H:i:s', (int) $entry['last_update']) : '—' ?></td>
                    <td><?= (int) ($entry['hits'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($entry['meta']['user_agent'] ?? 'N/A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="empty">Aucune modification enregistrée pour le moment.</p>
    <?php endif; ?>
</div>

</body>
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
</html>
