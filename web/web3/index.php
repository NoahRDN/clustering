<?php
$logicalName  = gethostname();
$serverIp     = $_SERVER['SERVER_ADDR'] ?? gethostbyname($logicalName);
$clientIp     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'Inconnue');
$hostHeader   = $_SERVER['HTTP_HOST'] ?? 'Non précisé';
$protocol     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestUri   = $_SERVER['REQUEST_URI'] ?? '/';
$httpMethod   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? 'Non précisé';
$serverPort   = $_SERVER['SERVER_PORT'] ?? 'N/A';
$requestTime  = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time());
$fullUrl      = $hostHeader !== 'Non précisé' ? sprintf('%s://%s%s', $protocol, $hostHeader, $requestUri) : $requestUri;
$forwardChain = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$requestHash  = substr(hash('sha1', implode('|', [$clientIp, $logicalName, $requestTime, $httpMethod, $requestUri])), 0, 12);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Test de connexion - <?= htmlspecialchars($logicalName, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        body {
            margin: 0;
            padding: 32px;
            background: linear-gradient(135deg, #e1ebff, #fefefe);
            color: #0f172a;
        }
        .container {
            max-width: 920px;
            margin: auto;
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.15);
        }
        h1 {
            margin-top: 0;
            font-size: 28px;
            color: #0c3ebc;
        }
        .lead { margin-bottom: 24px; color: #475569; }
        .badges {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .badge {
            padding: 16px;
            border-radius: 16px;
            background: #eff4ff;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .badge span {
            display: block;
            font-size: 12px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 6px;
        }
        .badge strong {
            font-size: 20px;
            color: #0f172a;
        }
        .details {
            margin-top: 28px;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 0;
            overflow: hidden;
            list-style: none;
        }
        .details li {
            display: flex;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }
        .details li:last-child { border-bottom: none; }
        .details span {
            font-weight: 600;
            color: #1d2a5b;
        }
        .details code {
            background: #0f172a;
            color: #e2e8f0;
            padding: 3px 6px;
            border-radius: 6px;
        }
        .muted { color: #64748b; font-size: 14px; margin-top: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Test de connexion</h1>
    <p class="lead">Cette page confirme que l’instance répond correctement derrière HAProxy.</p>

    <div class="badges">
        <div class="badge">
            <span>Nom logique</span>
            <strong><?= htmlspecialchars($logicalName, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div class="badge">
            <span>IP du serveur</span>
            <strong><?= htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div class="badge">
            <span>IP cliente</span>
            <strong><?= htmlspecialchars($clientIp, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    </div>

    <ul class="details">
        <li>
            <span>URL appelée</span>
            <div><?= htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <li>
            <span>Méthode HTTP</span>
            <div><?= htmlspecialchars($httpMethod, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <li>
            <span>Port du backend</span>
            <div><?= htmlspecialchars((string) $serverPort, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <li>
            <span>User Agent</span>
            <div><?= htmlspecialchars($userAgent, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <li>
            <span>Date locale</span>
            <div><?= htmlspecialchars($requestTime, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <li>
            <span>Empreinte requête</span>
            <div><code><?= htmlspecialchars($requestHash, ENT_QUOTES, 'UTF-8') ?></code></div>
        </li>
        <?php if ($forwardChain): ?>
        <li>
            <span>X-Forwarded-For</span>
            <div><?= htmlspecialchars($forwardChain, ENT_QUOTES, 'UTF-8') ?></div>
        </li>
        <?php endif; ?>
    </ul>

    <p class="muted">Besoin d’un diagnostic supplémentaire ? Comparez l’adresse IP logique avec la cible attendue dans HAProxy et surveillez les en-têtes transmis.</p>
</div>
</body>
</html>
