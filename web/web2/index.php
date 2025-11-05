<?php
session_start();

$server = gethostname();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['favorite_color'] = $_POST['color'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Session locale - <?= htmlspecialchars($server) ?></title>
</head>
<body style="font-family: Arial; padding: 20px;">
<h1>Serveur <?= htmlspecialchars($server) ?> 🌐</h1>

<form method="post">
  <label>Ta couleur préférée :
    <input type="text" name="color" value="<?= $_SESSION['favorite_color'] ?? '' ?>">
  </label>
  <button type="submit">Enregistrer</button>
</form>

<h2>Session actuelle</h2>
<pre><?php print_r($_SESSION); ?></pre>

<p><a href="?reload=1">🔁 Recharger (nouvelle requête GET)</a></p>

</body>
</html>
