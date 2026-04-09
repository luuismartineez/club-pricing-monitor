<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin_login();

$db = db();
$id = (int)get_param('id', '0');

$stmt = $db->prepare("UPDATE clubs SET is_active = 0 WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

flash_set('success', 'Club desactivado correctamente.');
redirect('clubs_manage.php');