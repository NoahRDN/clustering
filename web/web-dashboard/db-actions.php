<?php
session_start();
require __DIR__ . '/includes/haproxy_admin.php';

$ctx      = buildDashboardContext();
$formType = $_POST['form_type'] ?? '';
$redirect = 'index.php#db-form';

switch ($formType) {
    case 'add_db':
        handleDatabaseForm($ctx, $_POST);
        break;
    case 'db_action':
        handleDatabaseAction($ctx, $_POST);
        break;
    default:
        addFlash('error', 'Formulaire DB inconnu.');
        break;
}

header('Location: ' . $redirect);
exit;
