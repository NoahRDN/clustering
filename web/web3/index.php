<?php
$server = gethostname();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Serveur <?= htmlspecialchars($server, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: Arial, sans-serif; padding: 24px; background: #f0f4ff; }
        .card { background: white; border-radius: 12px; padding: 24px; max-width: 480px; margin: auto; box-shadow: 0 15px 40px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; color: #123067; }
        pre { background: #101c36; color: #e5edff; padding: 12px; border-radius: 8px; overflow: auto; }
    </style>
</head>
<body>
<div class="card">
    <h1>Instance <?= htmlspecialchars($server, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Cette instance sert de cible supplémentaire pour HAProxy.</p>
    <pre><?php print_r($_SERVER); ?></pre>
</div>
</body>
</html>
