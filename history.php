<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();


$db = db();

$clubId = (int)get_param('club_id', '0');
$q = get_param('q', '');
$sort = get_param('sort', 'recent');

$sql = "
    SELECT
        ph.id,
        ph.price_original,
        ph.price_discount,
        ph.discount_active,
        ph.captured_at,
        p.normalized_title,
        p.kit_type,
        p.audience,
        p.garment_type,
        p.version_type,
        c.club_name,
		c.crest_url
    FROM price_history ph
    INNER JOIN products p ON p.id = ph.product_id
    INNER JOIN clubs c ON c.id = p.club_id
    WHERE 1=1
";

$params = [];
$types = '';

if ($clubId > 0) {
    $sql .= " AND c.id = ?";
    $types .= 'i';
    $params[] = $clubId;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (p.normalized_title LIKE ? OR c.club_name LIKE ?)";
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

$orderBy = match($sort) {
    'price_asc' => 'COALESCE(ph.price_discount, ph.price_original) ASC, ph.captured_at DESC',
    'price_desc' => 'COALESCE(ph.price_discount, ph.price_original) DESC, ph.captured_at DESC',
    default => 'ph.captured_at DESC',
};

$sql .= " ORDER BY {$orderBy} LIMIT 300";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result();

$clubs = $db->query("SELECT id, club_name FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Histórico</h1>
        <p>Registro de precios y descuentos capturados por el scraper.</p>
    </div>
    <div class="actions">
        <a class="btn btn-danger" href="history_clear.php" onclick="return confirm('¿Seguro que quieres limpiar el histórico?');">Limpiar histórico</a>
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

        <div class="search-wide">
            <label>Buscar</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Club o producto">
        </div>
		
		<div>
    <label>Ordenar</label>
    <select name="sort">
        <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Más reciente</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Precio más barato</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Precio más caro</option>
    </select>
</div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-light" href="history.php">Limpiar filtros</a>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
					<th>Escudo</th>
                    <th>Club</th>
                    <th>Producto</th>
                    <th>Cat.</th>
                    <th>Precio</th>
                    <th>Descuento</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><?= e(date('d/m/Y H:i', strtotime($row['captured_at']))) ?></td>
					<td>
    <?php if (!empty($row['crest_url'])): ?>
        <img class="crest-icon" src="<?= e($row['crest_url']) ?>" alt="<?= e($row['club_name']) ?>">
    <?php endif; ?>
</td>
                    <td><?= e($row['club_name']) ?></td>
                    <td><?= e($row['normalized_title']) ?></td>
                    <td>
                        <?= e(kit_label($row['kit_type'])) ?><br>
                        <span class="small">
                            <?= e($row['audience']) ?> /
                            <?= e($row['garment_type']) ?> /
                            <?= e($row['version_type']) ?>
                        </span>
                    </td>
                    <td><?= e(format_price((float)$row['price_original'])) ?></td>
                    <td>
                        <?php if ((int)$row['discount_active'] === 1 && $row['price_discount'] !== null): ?>
                            <span class="badge badge-success"><?= e(format_price((float)$row['price_discount'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-muted">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>