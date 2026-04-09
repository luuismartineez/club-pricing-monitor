<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();

$db = db();

$stats = [
    'clubs' => 0,
    'products' => 0,
    'discounted' => 0,
    'last_scrape' => null,
];

$res = $db->query("SELECT COUNT(*) AS total FROM clubs WHERE is_active = 1");
$stats['clubs'] = (int)$res->fetch_assoc()['total'];

$res = $db->query("SELECT COUNT(*) AS total FROM products WHERE is_active = 1");
$stats['products'] = (int)$res->fetch_assoc()['total'];

$res = $db->query("SELECT COUNT(*) AS total FROM products WHERE is_active = 1 AND discount_active = 1");
$stats['discounted'] = (int)$res->fetch_assoc()['total'];

$res = $db->query("SELECT MAX(finished_at) AS last_scrape FROM scrape_runs WHERE status IN ('success','partial')");
$row = $res->fetch_assoc();
$stats['last_scrape'] = $row['last_scrape'] ?? null;

$clubs = $db->query("
    SELECT c.*,
           COUNT(p.id) AS active_products
    FROM clubs c
    LEFT JOIN products p ON p.club_id = c.id AND p.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.club_name ASC
");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p>Resumen general del sistema, estado actual de clubes, scraping y pricing.</p>
    </div>
</div>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="label">Clubes activos</div>
        <div class="value"><?= (int)$stats['clubs'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Equipaciones activas</div>
        <div class="value"><?= (int)$stats['products'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Con descuento</div>
        <div class="value"><?= (int)$stats['discounted'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Última actualización</div>
        <div class="value" style="font-size:18px;">
            <?= $stats['last_scrape'] ? e(date('d/m/Y H:i', strtotime($stats['last_scrape']))) : 'Sin ejecuciones' ?>
        </div>
    </div>
</div>

<section class="panel">
    <h2>Resumen de clubes</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Escudo</th>
                    <th>Club</th>
                    <th>Tienda oficial</th>
                    <th>Equipaciones activas</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($club = $clubs->fetch_assoc()): ?>
                <tr>
                    <td>
                        <?php if (!empty($club['crest_url'])): ?>
                            <img class="crest-icon" src="<?= e($club['crest_url']) ?>" alt="<?= e($club['club_name']) ?>">
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($club['club_name']) ?></strong></td>
                    <td><a href="<?= e($club['store_url']) ?>" target="_blank" rel="noopener">Abrir tienda</a></td>
                    <td><?= (int)$club['active_products'] ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>