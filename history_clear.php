<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';


$db = db();

$db->query("DELETE FROM price_history");
$db->query("DELETE FROM scrape_runs");

flash_set('success', 'Histórico limpiado correctamente.');
redirect('history.php');