<?php
session_start();
require __DIR__ . '/includes/haproxy_admin.php';

$ctx      = buildDashboardContext();
$formType = $_POST['form_type'] ?? '';
$redirect = 'index.php';

switch ($formType) {
    case 'add_web':
        handleAddWeb($ctx, $_POST);
        $redirect = 'index.php#web-form';
        break;
    case 'web_action':
        handleWebAction($ctx, $_POST);
        break;
    default:
        addFlash('error', 'Formulaire inconnu.');
        break;
}

header('Location: ' . $redirect);
exit;
