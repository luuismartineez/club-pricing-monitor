<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/scraper_engine.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();

$db = db();

$runMessage = '';
$runError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_scraper'])) {
    $selectedClubId = (int)post_param('club_id', '0');

    if ($selectedClubId > 0) {
        $stmt = $db->prepare("SELECT * FROM clubs WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $selectedClubId);
    } else {
        $stmt = $db->prepare("SELECT * FROM clubs WHERE is_active = 1");
    }

    $stmt->execute();
    $clubsResult = $stmt->get_result();

    $messages = [];

    try {
        while ($club = $clubsResult->fetch_assoc()) {
            $items = scrape_club($club);
            $saved = save_scraped_products((int)$club['id'], $items);

            $messages[] = $club['club_name'] . ': ' . $saved['total'] . ' procesadas (' . $saved['created'] . ' nuevas, ' . $saved['updated'] . ' actualizadas)';
        }

        $runMessage = implode(' | ', $messages);
    } catch (Throwable $e) {
        $runError = $e->getMessage();
    }

    $stmt->close();
}

$clubId = (int)get_param('club_id', '0');
$kitType = get_param('kit_type', '');
$audience = get_param('audience', '');
$garmentType = get_param('garment_type', '');
$versionType = get_param('version_type', '');

$clubs = $db->query("SELECT id, club_name FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");

$sql = "
    SELECT p.*, c.club_name
    FROM products p
    INNER JOIN clubs c ON c.id = p.club_id
    WHERE p.is_active = 1
";

$params = [];
$types = '';

if ($clubId > 0) {
    $sql .= " AND p.club_id = ?";
    $types .= 'i';
    $params[] = $clubId;
}

if (in_array($kitType, ['1', '2', '3'], true)) {
    $sql .= " AND p.kit_type = ?";
    $types .= 's';
    $params[] = $kitType;
}

if (in_array($audience, ['hombre', 'mujer', 'nino', 'unisex', 'desconocido'], true)) {
    $sql .= " AND p.audience = ?";
    $types .= 's';
    $params[] = $audience;
}

if (in_array($garmentType, ['camiseta', 'equipacion_completa', 'otra'], true)) {
    $sql .= " AND p.garment_type = ?";
    $types .= 's';
    $params[] = $garmentType;
}

if (in_array($versionType, ['player', 'fan', 'desconocida'], true)) {
    $sql .= " AND p.version_type = ?";
    $types .= 's';
    $params[] = $versionType;
}

$sql .= " ORDER BY c.club_name ASC, p.kit_type ASC, p.updated_at DESC";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Scraper</h1>
        <p>Ejecuta el scraping y visualiza las cards extraídas, con enlace directo a la compra oficial.</p>
    </div>
</div>

<?php if ($runMessage !== ''): ?>
    <div class="alert alert-success"><?= e($runMessage) ?></div>
<?php endif; ?>

<?php if ($runError !== ''): ?>
    <div class="alert alert-error"><?= e($runError) ?></div>
<?php endif; ?>

<section class="panel">
    <form method="post" class="scraper-toolbar">
        <div style="min-width:240px;">
            <label>Club a scrapear</label>
            <select name="club_id">
                <option value="0">Todos los clubes</option>
                <?php
                $clubsForRun = $db->query("SELECT id, club_name FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");
                while ($club = $clubsForRun->fetch_assoc()):
                ?>
                    <option value="<?= (int)$club['id'] ?>"><?= e($club['club_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="actions">
            <button class="btn btn-warning" type="submit" name="run_scraper" value="1">Ejecutar scraping</button>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <form method="get" class="filters">
        <div>
            <label>Club</label>
            <select name="club_id">
                <option value="0">Todos</option>
                <?php
                $clubsForFilter = $db->query("SELECT id, club_name FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");
                while ($club = $clubsForFilter->fetch_assoc()):
                ?>
                    <option value="<?= (int)$club['id'] ?>" <?= $clubId === (int)$club['id'] ? 'selected' : '' ?>>
                        <?= e($club['club_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div>
            <label>Equipación</label>
            <select name="kit_type">
                <option value="">Todas</option>
                <option value="1" <?= $kitType === '1' ? 'selected' : '' ?>>1ª</option>
                <option value="2" <?= $kitType === '2' ? 'selected' : '' ?>>2ª</option>
                <option value="3" <?= $kitType === '3' ? 'selected' : '' ?>>3ª</option>
            </select>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-light" href="scraper.php">Limpiar</a>
        </div>
		
		<div>
    <label>Género</label>
    <select name="audience">
        <option value="">Todos</option>
        <option value="hombre" <?= $audience === 'hombre' ? 'selected' : '' ?>>Hombre</option>
        <option value="mujer" <?= $audience === 'mujer' ? 'selected' : '' ?>>Mujer</option>
        <option value="nino" <?= $audience === 'nino' ? 'selected' : '' ?>>Niño</option>
        <option value="unisex" <?= $audience === 'unisex' ? 'selected' : '' ?>>Unisex</option>
        <option value="desconocido" <?= $audience === 'desconocido' ? 'selected' : '' ?>>Desconocido</option>
    </select>
</div>

<div>
    <label>Producto</label>
    <select name="garment_type">
        <option value="">Todos</option>
        <option value="camiseta" <?= $garmentType === 'camiseta' ? 'selected' : '' ?>>Solo camiseta</option>
        <option value="equipacion_completa" <?= $garmentType === 'equipacion_completa' ? 'selected' : '' ?>>Equipación completa</option>
        <option value="otra" <?= $garmentType === 'otra' ? 'selected' : '' ?>>Otros</option>
    </select>
</div>

<div>
    <label>Versión</label>
    <select name="version_type">
        <option value="">Todas</option>
        <option value="player" <?= $versionType === 'player' ? 'selected' : '' ?>>Player version</option>
        <option value="fan" <?= $versionType === 'fan' ? 'selected' : '' ?>>Fan version</option>
        <option value="desconocida" <?= $versionType === 'desconocida' ? 'selected' : '' ?>>Desconocida</option>
    </select>
</div>
    </form>
</section>

<section class="price-card-grid" style="margin-top:18px;">
    <?php if ($products->num_rows === 0): ?>
        <div class="empty-state">No hay cards disponibles todavía. Ejecuta el scraper para poblar resultados.</div>
    <?php endif; ?>

    <?php while ($row = $products->fetch_assoc()): ?>
        <article class="kit-card original-card">
            <a href="<?= e($row['product_url']) ?>" target="_blank" rel="noopener">
                <div class="kit-thumb">
                    <?php if (!empty($row['image_url'])): ?>
                        <img src="<?= e($row['image_url']) ?>" alt="<?= e($row['normalized_title']) ?>">
                    <?php else: ?>
                        <div style="padding:20px; text-align:center; color:#6f6a60;">Sin imagen</div>
                    <?php endif; ?>
                </div>
            </a>

            <div class="kit-body">
                <div class="kit-meta">
                    <div>
                        <div>
    <div class="kit-club"><?= e($row['club_name']) ?></div>
    <div class="small"><?= e(kit_label($row['kit_type'])) ?></div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
        <span class="badge badge-muted"><?= e(ucfirst($row['audience'])) ?></span>

        <span class="badge badge-warning">
            <?= $row['garment_type'] === 'equipacion_completa' ? 'Equipación completa' : 'Solo camiseta' ?>
        </span>

        <span class="badge badge-success">
            <?= $row['version_type'] === 'player' ? 'Player version' : 'Fan version' ?>
        </span>
    </div>
</div>

<?php if ((int)$row['discount_active'] === 1): ?>
    <span class="badge badge-success">Descuento</span>
<?php else: ?>
    <span class="badge badge-muted">Sin descuento</span>
<?php endif; ?>
</div>

<div class="kit-title"><?= e($row['normalized_title']) ?></div>

                <div class="kit-prices">
                    <?php if ((int)$row['discount_active'] === 1): ?>
                        <span class="price-main"><?= e(format_price((float)$row['price_discount'])) ?></span>
                        <span class="price-old"><?= e(format_price((float)$row['price_original'])) ?></span>
                    <?php else: ?>
                        <span class="price-main"><?= e(format_price((float)$row['price_original'])) ?></span>
                    <?php endif; ?>
                </div>

                <div class="kit-footer">
                    <span class="small">Actualizado: <?= e(date('d/m/Y H:i', strtotime($row['updated_at']))) ?></span>
                    <a class="btn btn-light" href="<?= e($row['product_url']) ?>" target="_blank" rel="noopener">Ir a tienda oficial</a>
                </div>

                <?php if (!empty($row['source_card_html'])): ?>
    <details class="original-html">
        <summary>Ver HTML fuente</summary>
        <pre><?= e($row['source_card_html']) ?></pre>
    </details>
<?php endif; ?>
            </div>
        </article>
    <?php endwhile; ?>
</section>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>