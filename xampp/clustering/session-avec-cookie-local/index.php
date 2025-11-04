  <?php

  $server = isset($_GET['server']) ? preg_replace('/[^a-zA-Z0-9_-]/','',$_GET['server']) : 'xampp';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $color = isset($_POST['color']) ? trim($_POST['color']) : '';
      if ($color !== '') {
      
          $cookie_name = 'prefcolor_' . $server . '_' . time();
          $cookie_value = $color . ' (from ' . $server . ')';
          setcookie($cookie_name, $cookie_value, [
              'expires' => time() + 30*24*3600,
              'path' => '/',
              'httponly' => false,
              'samesite' => 'Lax'
          ]);
          header('Location: /clustering/session-avec-cookie-local/?server=' . urlencode($server));
          exit;
      }
  }

  ?>
  <!doctype html>
  <html lang="fr">
  <head>
    <meta charset="utf-8">
    <title>Exercice Cookie Couleur - Server <?= htmlspecialchars($server) ?></title>
    <style>
      body { font-family: Arial, sans-serif; padding:20px; }
      .preview { width: 100px; height: 30px; border:1px solid #ccc; display:inline-block; vertical-align: middle; margin-left:10px; }
    </style>
  </head>
  <body>
    <h1>Server <?= htmlspecialchars($server) ?> — Cookie couleur</h1>

    <form method="post" action="/clustering/session-avec-cookie-local/?server=<?= htmlspecialchars($server) ?>">
      <label>Ta couleur préférée :
        <input type="text" name="color" placeholder="ex: blue or #ff0000" required>
      </label>
      <button type="submit">Enregistrer dans un cookie</button>
    </form>

    <h2>Cookies présents côté serveur ($_COOKIE)</h2>
    <pre><?php echo htmlentities(print_r($_COOKIE, true)); ?></pre>

  </body>
  </html>
