<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();

$db = db();

$clubName = trim((string)get_param('club_name', ''));
$status = trim((string)get_param('status', 'active'));

$sql = "SELECT * FROM mail_discount_live WHERE 1 = 1";
$types = '';
$params = [];

if ($status === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND is_active = 0";
}

if ($clubName !== '') {
    $sql .= " AND club_name = ?";
    $types .= 's';
    $params[] = $clubName;
}

$sql .= " ORDER BY is_active DESC, club_name ASC, updated_at DESC";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result();

$clubs = [
    'Athletic Club',
    'Atletico de Madrid',
    'CA Osasuna',
    'RC Celta',
    'Deportivo Alaves',
    'Elche CF',
    'FC Barcelona',
    'Getafe CF',
    'Girona FC',
    'Levante UD',
    'Rayo Vallecano',
    'RCD Espanyol',
    'RCD Mallorca',
    'Real Betis',
    'Real Madrid',
    'Real Oviedo',
    'Real Sociedad',
    'Sevilla FC',
    'Valencia CF',
    'Villarreal CF',
];


require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Promos de Emails</h1>
        <p>Descuentos detectados desde newsletters y correos promocionales, filtrados solo para camisetas y equipaciones.</p>
    </div>
</div>

<section class="panel">
    <form method="get" class="scraper-toolbar">
        <div style="min-width:220px;">
            <label>Club</label>
            <select name="club_name">
                <option value="">Todos</option>
                <?php foreach ($clubs as $club): ?>
                    <option value="<?= e($club) ?>" <?= $clubName === $club ? 'selected' : '' ?>>
                        <?= e($club) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="min-width:220px;">
            <label>Estado</label>
            <select name="status">
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Activas</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactivas</option>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todas</option>
            </select>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-light" href="mail_discounts.php">Limpiar</a>
        </div>
    </form>
</section>

<section class="price-card-grid" style="margin-top:18px;">
    <?php if ($rows->num_rows === 0): ?>
        <div class="empty-state">No hay promos detectadas con esos filtros.</div>
    <?php endif; ?>

    <?php while ($row = $rows->fetch_assoc()): ?>
        <article class="kit-card probe-card">
            <div class="kit-body">
                <div class="kit-meta">
                    <div>
                        <div class="kit-club"><?= e($row['club_name']) ?></div>
                        <div class="small">
                            <?= e($row['mechanism_family']) ?> · <?= (int)$row['is_active'] === 1 ? 'Activa' : 'Inactiva' ?>
                        </div>
                    </div>
                    <span class="badge badge-success"><?= e($row['discount_value']) ?></span>
                </div>

                <div class="kit-title"><?= e($row['title']) ?></div>

                <div class="probe-facts">
                    <div><strong>Producto:</strong> <?= e($row['applies_to']) ?></div>
                    <div><strong>Dirigido a:</strong> <?= e($row['audience']) ?></div>
                    <div><strong>Etiqueta:</strong> <?= e($row['mechanism_label'] !== '' ? $row['mechanism_label'] : 'Sin etiqueta específica') ?></div>
                </div>

                <div class="probe-snippet"><?= e($row['description']) ?></div>

                <div class="kit-footer">
                    <span class="small probe-page">
                        <?= e((string)$row['source_received_at']) ?>
                    </span>

                    <?php if (!empty($row['source_url'])): ?>
                        <a class="btn btn-light" href="<?= e($row['source_url']) ?>" target="_blank" rel="noopener">Abrir enlace</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endwhile; ?>
</section>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
