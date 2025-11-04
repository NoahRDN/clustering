<?php
session_start();

// Incrémente un compteur
if (!isset($_SESSION['visites'])) {
    $_SESSION['visites'] = 1;
} else {
    $_SESSION['visites']++;
}

// Identifiant serveur (change sur chaque serveur)
$server_name = "Apache";

echo "<h1>Test de Session via HAProxy</h1>";
echo "Session ID : <strong>" . session_id() . "</strong><br>";
echo "Nombre de visites durant la session : <strong>" . $_SESSION['visites'] . "</strong><br>";
echo "Serveur actuel : <strong>$server_name</strong>";

