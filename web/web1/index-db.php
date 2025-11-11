<?php
require_once 'db_session_handler.php';

$dbHost = getenv('DB_PROXY_HOST') ?: 'haproxy-db';   // PC1 joue le rôle de PC3 → mettre son IP ici
$dbPort = getenv('DB_PROXY_PORT') ?: '3307';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=clustering;charset=utf8mb4', $dbHost, $dbPort);
$user = 'root';
$pass = 'root';

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$handler = new DBSessionHandler($pdo);
session_set_save_handler($handler, true);
session_start();

$server = gethostname();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['favorite_color'] = $_POST['color'];
    header("Location: ?");
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Session DB - <?= htmlspecialchars($server) ?></title>
</head>
<body style="font-family: Arial; padding: 20px;">
<h1>Serveur <?= htmlspecialchars($server) ?> (Session en Base de Données) 💾</h1>

<form method="post">
  <label>Ta couleur préférée :
    <input type="text" name="color" value="<?= $_SESSION['favorite_color'] ?? '' ?>">
  </label>
  <button type="submit">Enregistrer</button>
</form>

<h2>Session actuelle</h2>
<pre><?php print_r($_SESSION); ?></pre>

</body>
</html>
