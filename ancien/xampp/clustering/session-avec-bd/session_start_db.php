<?php
require_once 'db_session_handler.php';

// 🔧 Paramètres de connexion à la base de données
$dsn = 'mysql:host=localhost;dbname=clustering;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// 🧱 Création du gestionnaire de session
$handler = new DBSessionHandler($pdo);
session_set_save_handler($handler, true);

// 🚀 Démarrage de la session
session_start();
?>
