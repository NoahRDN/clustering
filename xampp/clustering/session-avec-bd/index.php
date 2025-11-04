<?php
require_once 'session_start_db.php';
// Sélection dynamique du serveur (juste pour affichage)
$server = isset($_GET['server']) ? preg_replace('/[^a-zA-Z0-9_-]/','',$_GET['server']) : 'Xampp';


// ✅ Si une couleur est envoyée, on la stocke DANS LA SESSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $color = isset($_POST['color']) ? trim($_POST['color']) : '';

    if ($color !== '') {
        $_SESSION['favorite_color'] = $color;
        header('Location: /clustering/session-avec-bd/?server=' . urlencode($server));
        exit;
    }
}


?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Session via DB - Server <?= htmlspecialchars($server) ?></title>
  <style>
    body { font-family: Arial, sans-serif; padding:20px; }
  </style>
</head>
<body>

  <h1>Serveur <?= htmlspecialchars($server) ?> — Session Stockée en Base de Données ✅</h1>

  <form method="post" action="/clustering/session-avec-bd/">
    <label>Ta couleur préférée :
      <input type="text" name="color" placeholder="ex: blue ou #ff0000" required>
    </label>
    <button type="submit">Enregistrer en session</button>
  </form>

  <h2>Données de votre session :</h2>
  <pre><?php print_r($_SESSION); ?></pre>

</body>
</html>
