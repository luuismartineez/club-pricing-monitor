<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
    <div class="container nav-wrap">
        <a class="brand" href="index.php">⚽ <?= e(APP_NAME) ?></a>

        <div class="nav-right">
    <nav class="main-nav">
        <a class="<?= current_page('clubs_add.php') ? 'active' : '' ?>" href="clubs_add.php">＋ Altas de Club</a>
        <a class="<?= current_page('clubs_manage.php') ? 'active' : '' ?>" href="clubs_manage.php">☰ Gestor de Clubes</a>
        <a class="<?= current_page('pricing.php') ? 'active' : '' ?>" href="pricing.php">€ Pricing</a>
        <a class="<?= current_page('scraper.php') ? 'active' : '' ?>" href="scraper.php">⇅ Scraper</a>
        <a class="<?= current_page('history.php') ? 'active' : '' ?>" href="history.php">🕘 Histórico</a>
		<a class="<?= current_page('mail_discounts.php') ? 'active' : '' ?>" href="mail_discounts.php">Promos</a>
        <a class="settings-btn <?= current_page('dashboard.php') ? 'active' : '' ?>" href="dashboard.php" title="Dashboard / Ajustes">⚙ Ajustes</a>
		
		<?php if (!empty($_SESSION['admin_username'])): ?>
    <a class="settings-btn" href="logout.php">Salir</a>
<?php endif; ?>
    </nav>
</div>
		
    </div>
</header>

<main class="container main-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>