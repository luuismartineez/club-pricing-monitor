<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();

$clubId = (int)get_param('club_id', '0');
$kitType = get_param('kit_type', '');
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

$sql .= " ORDER BY c.club_name ASC, p.kit_type ASC";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$labels = [];
$values = [];

while ($row = $res->fetch_assoc()) {
    $labels[] = $row['club_name'] . ' · ' . kit_label($row['kit_type']) . ' · ' . $row['audience'];
    $values[] = $row['discount_active'] && $row['price_discount'] !== null
        ? (float)$row['price_discount']
        : (float)$row['price_original'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Gráfica de precios actuales</h1>
        <p>Visualización rápida del pricing actual según los filtros aplicados.</p>
    </div>
    <div class="actions">
        <a class="btn btn-light" href="pricing.php?<?= e(http_build_query($_GET)) ?>">Volver a pricing</a>
    </div>
</div>

<section class="panel">
    <canvas id="pricingChart" height="120"></canvas>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const values = <?= json_encode($values, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('pricingChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Precio actual (€)',
            data: values
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: true }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>