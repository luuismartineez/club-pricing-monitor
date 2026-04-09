<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();


$db = db();

$clubId = (int)get_param('club_id', '0');
$kitType = get_param('kit_type', '');
$discount = get_param('discount', '');
$sort = get_param('sort', 'price_asc');
$priceMin = get_param('price_min', '');
$priceMax = get_param('price_max', '');
$q = get_param('q', '');
$audience = get_param('audience', '');
$garmentType = get_param('garment_type', '');
$versionType = get_param('version_type', '');

$sql = "
    SELECT p.*, c.club_name
    FROM products p
    INNER JOIN clubs c ON c.id = p.club_id
    WHERE p.is_active = 1
      AND c.is_active = 1
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

if ($discount === '1') {
    $sql .= " AND p.discount_active = 1";
} elseif ($discount === '0') {
    $sql .= " AND p.discount_active = 0";
}

if ($priceMin !== '' && is_numeric(str_replace(',', '.', $priceMin))) {
    $sql .= " AND COALESCE(p.price_discount, p.price_original) >= ?";
    $types .= 'd';
    $params[] = (float)str_replace(',', '.', $priceMin);
}

if ($priceMax !== '' && is_numeric(str_replace(',', '.', $priceMax))) {
    $sql .= " AND COALESCE(p.price_discount, p.price_original) <= ?";
    $types .= 'd';
    $params[] = (float)str_replace(',', '.', $priceMax);
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (p.scraped_title LIKE ? OR p.normalized_title LIKE ? OR c.club_name LIKE ?)";
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$order = match($sort) {
    'price_desc' => 'COALESCE(p.price_discount, p.price_original) DESC',
    'club_asc' => 'c.club_name ASC, COALESCE(p.price_discount, p.price_original) ASC',
    default => 'COALESCE(p.price_discount, p.price_original) ASC',
};

$sql .= " ORDER BY {$order}, p.updated_at DESC";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result();

$clubs = $db->query("SELECT id, club_name FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Pricing</h1>
        <p>Comparativa de precios con filtros por club, equipación, descuento, rango, texto y ordenación.</p>
    </div>
</div>

<section class="panel">
    <form method="get" class="filters">
        <div>
            <label>Club</label>
            <select name="club_id">
                <option value="0">Todos</option>
                <?php while ($club = $clubs->fetch_assoc()): ?>
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

        <div>
            <label>Descuento activo</label>
            <select name="discount">
                <option value="">Todos</option>
                <option value="1" <?= $discount === '1' ? 'selected' : '' ?>>Sí</option>
                <option value="0" <?= $discount === '0' ? 'selected' : '' ?>>No</option>
            </select>
        </div>

        <div>
            <label>Ordenación</label>
            <select name="sort">
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Precio menor a mayor</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Precio mayor a menor</option>
                <option value="club_asc" <?= $sort === 'club_asc' ? 'selected' : '' ?>>Club + precio</option>
            </select>
        </div>

        <div>
            <label>Precio mínimo</label>
            <input type="number" step="0.01" name="price_min" value="<?= e($priceMin) ?>">
        </div>

        <div>
            <label>Precio máximo</label>
            <input type="number" step="0.01" name="price_max" value="<?= e($priceMax) ?>">
        </div>

        <div class="search-wide">
            <label>Buscador global</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por club o título de camiseta">
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Aplicar filtros</button>
            <a class="btn btn-light" href="pricing.php">Limpiar</a>
			<a class="btn btn-light" href="pricing_chart.php?<?= e(http_build_query($_GET)) ?>">Ver gráfica</a>
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
        <div class="empty-state">No hay resultados para los filtros seleccionados.</div>
    <?php endif; ?>

    <?php while ($row = $products->fetch_assoc()): ?>
        <?php
            $finalPrice = $row['discount_active'] ? (float)$row['price_discount'] : (float)$row['price_original'];
            $oldPrice = $row['discount_active'] ? (float)$row['price_original'] : null;
        ?>
        <article class="kit-card">
            <div class="kit-thumb">
                <?php if (!empty($row['image_url'])): ?>
                    <img src="<?= e($row['image_url']) ?>" alt="<?= e($row['normalized_title']) ?>">
                <?php else: ?>
                    <div style="padding:20px; text-align:center; color:#6f6a60;">Sin imagen</div>
                <?php endif; ?>
            </div>

            <div class="kit-body">
                <div class="kit-meta">
                    <div>
                        <div class="kit-club">
    <?= htmlspecialchars($row['club_name']) ?> · <?= htmlspecialchars(kit_label($row['kit_type'])) ?>
</div>
<div class="small" style="margin-top:4px;">
    <?= htmlspecialchars(ucfirst($row['audience'])) ?> ·
    <?= $row['garment_type'] === 'equipacion_completa' ? 'Equipación completa' : 'Solo camiseta' ?> ·
    <?= $row['version_type'] === 'player' ? 'Player version' : 'Fan version' ?>
</div>
                        <div class="small"><?= e(kit_label($row['kit_type'])) ?></div>
                    </div>

                    <?php if ((int)$row['discount_active'] === 1): ?>
                        <span class="badge badge-success">Descuento</span>
                    <?php else: ?>
                        <span class="badge badge-muted">Sin descuento</span>
                    <?php endif; ?>
                </div>

                <div class="kit-title"><?= e($row['normalized_title']) ?></div>

                <div class="kit-prices">
                    <span class="price-main"><?= e(format_price($finalPrice)) ?></span>
                    <?php if ($oldPrice !== null): ?>
                        <span class="price-old"><?= e(format_price($oldPrice)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="kit-footer">
                    <span class="small">Actualizado: <?= e(date('d/m/Y H:i', strtotime($row['updated_at']))) ?></span>
                    <?php if (!empty($row['product_url'])): ?>
                        <a class="btn btn-light" href="<?= e($row['product_url']) ?>" target="_blank" rel="noopener">Comprar</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endwhile; ?>
</section>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>