<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_admin_login();

$db = db();

$period = get_param('period', 'day');
$allowedPeriods = ['hour', 'day', 'week', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'day';
}

function bucket_start(DateTimeImmutable $dt, string $period): DateTimeImmutable
{
    return match ($period) {
        'hour'  => $dt->setTime((int)$dt->format('H'), 0, 0),
        'week'  => $dt->modify('monday this week')->setTime(0, 0, 0),
        'month' => $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 1)->setTime(0, 0, 0),
        'year'  => $dt->setDate((int)$dt->format('Y'), 1, 1)->setTime(0, 0, 0),
        default => $dt->setTime(0, 0, 0),
    };
}

function next_bucket(DateTimeImmutable $dt, string $period): DateTimeImmutable
{
    return match ($period) {
        'hour'  => $dt->modify('+1 hour'),
        'week'  => $dt->modify('+1 week'),
        'month' => $dt->modify('+1 month'),
        'year'  => $dt->modify('+1 year'),
        default => $dt->modify('+1 day'),
    };
}

function format_bucket_label(DateTimeImmutable $dt, string $period): string
{
    return match ($period) {
        'hour'  => $dt->format('d/m H:i'),
        'week'  => $dt->format('d/m/Y'),
        'month' => $dt->format('m/Y'),
        'year'  => $dt->format('Y'),
        default => $dt->format('d/m'),
    };
}

function current_price_from_history_row(array $row): float
{
    if ((int)$row['discount_active'] === 1 && $row['price_discount'] !== null) {
        return (float)$row['price_discount'];
    }

    return (float)$row['price_original'];
}

/*
|--------------------------------------------------------------------------
| GRÁFICA
|--------------------------------------------------------------------------
|
| Base:
| - histórico real
| - camisetas de hombre
| - prioridad fan, fallback desconocida
| - por tramo de tiempo se toma el último valor de cada kit
| - luego se hace media de kits disponibles por club
| - se arrastra el último valor para generar línea plana si no cambia
|
*/

$rawHistorySql = "
    SELECT
        c.club_name,
        c.crest_url,
        p.kit_type,
        p.version_type,
        ph.price_original,
        ph.price_discount,
        ph.discount_active,
        ph.captured_at
    FROM price_history ph
    INNER JOIN products p ON p.id = ph.product_id
    INNER JOIN clubs c ON c.id = p.club_id
    WHERE c.is_active = 1
      AND p.is_active = 1
      AND p.audience = 'hombre'
      AND p.garment_type = 'camiseta'
      AND p.version_type IN ('fan', 'desconocida')
    ORDER BY ph.captured_at ASC, ph.id ASC
";

$rawRes = $db->query($rawHistorySql);

$rawRows = [];
$minDt = null;
$maxDt = null;
$clubMeta = [];

while ($row = $rawRes->fetch_assoc()) {
    $dt = new DateTimeImmutable($row['captured_at']);
    $row['_dt'] = $dt;
    $rawRows[] = $row;

    if ($minDt === null || $dt < $minDt) {
        $minDt = $dt;
    }
    if ($maxDt === null || $dt > $maxDt) {
        $maxDt = $dt;
    }

    $clubMeta[$row['club_name']] = [
        'crest_url' => $row['crest_url']
    ];
}

$bucketObjects = [];
$bucketKeys = [];
$chartLabels = [];

if ($minDt !== null && $maxDt !== null) {
    $now = new DateTimeImmutable('now');

    if ($period === 'hour') {
        $end = bucket_start($now, $period);
        $cursor = $end->modify('-23 hours');
    } elseif ($period === 'day') {
        $end = bucket_start($now, $period);
        $cursor = $end->modify('-6 days');
    } else {
        $cursor = bucket_start($minDt, $period);
        $end = bucket_start($maxDt, $period);
    }

    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d H:i:s');
        $bucketObjects[$key] = $cursor;
        $bucketKeys[] = $key;
        $chartLabels[] = format_bucket_label($cursor, $period);
        $cursor = next_bucket($cursor, $period);
    }
}


/*
|--------------------------------------------------------------------------
| RECORTE VISUAL DE LA GRÁFICA
|--------------------------------------------------------------------------
| hour => últimas 24 horas
| day  => últimos 7 días
| resto => completo
|
*/
$chartLimit = null;

if ($period === 'hour') {
    $chartLimit = 24;
} elseif ($period === 'day') {
    $chartLimit = 7;
}

if ($chartLimit !== null && count($bucketKeys) > $chartLimit) {
    $bucketKeys = array_slice($bucketKeys, -$chartLimit);
    $chartLabels = array_slice($chartLabels, -$chartLimit);

    $bucketObjects = array_intersect_key(
        $bucketObjects,
        array_flip($bucketKeys)
    );
}

$bucketLatestByClubKit = [];

foreach ($rawRows as $row) {
    $club = $row['club_name'];
    $kit = $row['kit_type'];
    $version = $row['version_type'];
    $dt = $row['_dt'];
    $bucketDt = bucket_start($dt, $period);
    $bucketKey = $bucketDt->format('Y-m-d H:i:s');

    $priority = ($version === 'fan') ? 0 : 1;
    $compoundKey = $club . '|' . $kit . '|' . $bucketKey;

    if (!isset($bucketLatestByClubKit[$compoundKey])) {
        $bucketLatestByClubKit[$compoundKey] = [
            'price' => current_price_from_history_row($row),
            'captured_at' => $row['captured_at'],
            'priority' => $priority
        ];
        continue;
    }

    $existing = $bucketLatestByClubKit[$compoundKey];

    if ($priority < $existing['priority']) {
        $bucketLatestByClubKit[$compoundKey] = [
            'price' => current_price_from_history_row($row),
            'captured_at' => $row['captured_at'],
            'priority' => $priority
        ];
        continue;
    }

    if ($priority === $existing['priority'] && strtotime($row['captured_at']) > strtotime($existing['captured_at'])) {
        $bucketLatestByClubKit[$compoundKey] = [
            'price' => current_price_from_history_row($row),
            'captured_at' => $row['captured_at'],
            'priority' => $priority
        ];
    }
}

$clubBucketAverages = [];

foreach ($bucketLatestByClubKit as $compoundKey => $info) {
    [$club, $kit, $bucketKey] = explode('|', $compoundKey);

    if (!isset($clubBucketAverages[$club])) {
        $clubBucketAverages[$club] = [];
    }

    if (!isset($clubBucketAverages[$club][$bucketKey])) {
        $clubBucketAverages[$club][$bucketKey] = [];
    }

    $clubBucketAverages[$club][$bucketKey][$kit] = $info['price'];
}

$clubColors = [
    'Real Madrid'        => '#F4F4F4',
    'Real Oviedo'        => '#1D5DCC',
    'Villarreal'         => '#F4D000',
    'Atletico de Madrid' => '#FFFFFF',  // Blanco para fondo
    'Real Betis'         => '#008000',
    'Athletic Club'      => '#D71920',  // Rojo para el Athletic Club
	'Espanyol'           => '#0064B1',
	'Real Sociedad'     => '#0057B8',
	'Mallorca' => '#C4122E',
	'Elche' => '#0B8F47',
	'Valencia' => '#F38411',
	'Rayo Vallecano' => '#FFFFFF',
	'Celta' => '#25C6DA',
	'Getafe' => '#1E88E5',
	'Girona' => '#E85D75',
	'Sevilla' => '#C1121F',
	'Alaves' => '#0056A4',
	'Levante' => '#A61E36',







];

$datasets = [];

foreach ($clubBucketAverages as $clubName => $bucketData) {
    $series = [];
    $lastValue = null;
    $firstVisibleBucketKey = $bucketKeys[0] ?? null;

    if ($firstVisibleBucketKey !== null) {
        $previousBucketKeys = array_filter(
            array_keys($bucketData),
            static fn(string $bucketKey): bool => $bucketKey < $firstVisibleBucketKey
        );

        if (count($previousBucketKeys) > 0) {
            rsort($previousBucketKeys);
            $latestPreviousBucketKey = $previousBucketKeys[0];

            if (isset($bucketData[$latestPreviousBucketKey]) && count($bucketData[$latestPreviousBucketKey]) > 0) {
                $lastValue = round(
                    array_sum($bucketData[$latestPreviousBucketKey]) / count($bucketData[$latestPreviousBucketKey]),
                    2
                );
            }
        }
    }

    foreach ($bucketKeys as $bucketKey) {
        if (isset($bucketData[$bucketKey]) && count($bucketData[$bucketKey]) > 0) {
            $avg = array_sum($bucketData[$bucketKey]) / count($bucketData[$bucketKey]);
            $lastValue = round($avg, 2);
            $series[] = $lastValue;
        } else {
            $series[] = $lastValue;
        }
    }


    $color = $clubColors[$clubName] ?? '#B9985A';

    $datasets[] = [
        'label' => $clubName,
        'data' => $series,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'pointBackgroundColor' => $color,
        'pointBorderColor' => $color,
        'tension' => 0.32,
'fill' => false,
'pointRadius' => $period === 'year' ? 4 : 3,
'pointHoverRadius' => 7,
'borderWidth' => 4,
'stepped' => false,
    ];
}

/*
|--------------------------------------------------------------------------
| CLASIFICACIONES
|--------------------------------------------------------------------------
|
| Se toma del histórico el último precio por club y kit:
| - hombre
| - camiseta
| - fan, con fallback desconocida
| Luego se ordena de mayor a menor.
|
*/

function fetch_latest_liga_from_history(mysqli $db, string $kitType): array
{
    $sql = "
        SELECT
            c.club_name,
            c.crest_url,
            p.normalized_title,
            p.image_url,
            p.version_type,
            ph.price_original,
            ph.price_discount,
            ph.discount_active,
            ph.captured_at
        FROM clubs c
        INNER JOIN products p ON p.club_id = c.id
        INNER JOIN price_history ph ON ph.product_id = p.id
        WHERE c.is_active = 1
          AND p.is_active = 1
          AND p.kit_type = ?
          AND p.audience = 'hombre'
          AND p.garment_type = 'camiseta'
          AND p.version_type IN ('fan', 'desconocida')
        ORDER BY
            c.club_name ASC,
            CASE WHEN p.version_type = 'fan' THEN 0 ELSE 1 END ASC,
            ph.captured_at DESC,
            ph.id DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $kitType);
    $stmt->execute();
    $res = $stmt->get_result();

    $picked = [];

    while ($row = $res->fetch_assoc()) {
        $club = $row['club_name'];

        if (!isset($picked[$club])) {
            $row['_current_price'] = ((int)$row['discount_active'] === 1 && $row['price_discount'] !== null)
                ? (float)$row['price_discount']
                : (float)$row['price_original'];

            $picked[$club] = $row;
        }
    }

    $stmt->close();

    $rows = array_values($picked);

    usort($rows, function ($a, $b) {
        return $b['_current_price'] <=> $a['_current_price'];
    });

    return $rows;
}

$ligaLocal = fetch_latest_liga_from_history($db, '1');
$ligaVisitante = fetch_latest_liga_from_history($db, '2');
$ligaTercera = fetch_latest_liga_from_history($db, '3');

function fetch_battle_options(mysqli $db): array
{
    $stmt = $db->prepare("
        SELECT
            p.id,
            p.kit_type,
            p.normalized_title,
            p.image_url,
            p.price_original,
            p.price_discount,
            p.discount_active,
            p.version_type,
            c.club_name,
            c.crest_url,
            p.updated_at
        FROM products p
        INNER JOIN clubs c ON c.id = p.club_id
        WHERE p.is_active = 1
          AND c.is_active = 1
          AND p.kit_type IN ('1','2','3')
          AND p.audience = 'hombre'
          AND p.garment_type = 'camiseta'
          AND p.version_type IN ('fan','desconocida')
        ORDER BY
            c.club_name ASC,
            p.kit_type ASC,
            CASE
                WHEN p.version_type = 'fan' THEN 0
                WHEN p.version_type = 'desconocida' THEN 1
                ELSE 2
            END ASC,
            p.updated_at DESC,
            p.id DESC
    ");
    $stmt->execute();
    $res = $stmt->get_result();

    $picked = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['club_name'] . '|' . $row['kit_type'];

        if (!isset($picked[$key])) {
            $currentPrice = ((int)$row['discount_active'] === 1 && $row['price_discount'] !== null)
                ? (float)$row['price_discount']
                : (float)$row['price_original'];

            $row['current_price'] = $currentPrice;
            $row['kit_label'] = kit_label($row['kit_type']);
            $row['option_label'] = $row['club_name'] . ' · ' . kit_label($row['kit_type']) . ' · ' . format_price($currentPrice);

            $picked[$key] = $row;
        }
    }

    $stmt->close();

    return array_values($picked);
}

$battleOptions = fetch_battle_options($db);


require_once __DIR__ . '/includes/header.php';
?>

<div class="home-shell">
    <section class="home-hero">
        <div class="home-title-wrap">
            <h1 class="home-title">Radar histórico de precios</h1>
            <p class="home-subtitle">
                Comparativa superpuesta por club basada en el histórico real de scraping.
                El eje vertical representa el precio y el horizontal el tiempo.
            </p>
        </div>

        <div class="chart-topbar">
            <div class="period-switch">
                <a class="period-pill <?= $period === 'hour' ? 'active' : '' ?>" href="index.php?period=hour">Horas</a>
                <a class="period-pill <?= $period === 'day' ? 'active' : '' ?>" href="index.php?period=day">Días</a>
                <a class="period-pill <?= $period === 'week' ? 'active' : '' ?>" href="index.php?period=week">Semanas</a>
                <a class="period-pill <?= $period === 'month' ? 'active' : '' ?>" href="index.php?period=month">Meses</a>
                <a class="period-pill <?= $period === 'year' ? 'active' : '' ?>" href="index.php?period=year">Años</a>
            </div>
</div>
        <div class="chart-panel">
            <div class="chart-frame">
                <canvas id="historyLineChart"></canvas>
            </div>
        </div>

        <div class="legend-row">
            <?php foreach ($datasets as $dataset): ?>
                <div class="legend-chip">
                    <span class="legend-line" style="background:<?= e($dataset['borderColor']) ?>; color:<?= e($dataset['borderColor']) ?>;"></span>
                    <?php if (!empty($clubMeta[$dataset['label']]['crest_url'])): ?>
                        <img class="legend-crest-sm" src="<?= e($clubMeta[$dataset['label']]['crest_url']) ?>" alt="<?= e($dataset['label']) ?>">
                    <?php endif; ?>
                    <span><?= e($dataset['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="visual-divider">
        <div class="visual-divider-badge">Ranking actual por equipación</div>
    </div>

    <section class="ranking-section">
        <div class="ranking-title-wrap">
            <h2 class="ranking-title">Liga de precios</h2>
            <p class="ranking-subtitle">
                Se toma el último precio histórico disponible por club y por equipación,
                y se ordena de más caro a más barato.
            </p>
        </div>

        <div class="rank-grid">
            <?php
            $ligas = [
                [
                    'title' => 'LaLiga Local',
                    'kicker' => '1ª equipación · camiseta · hombre',
                    'rows' => $ligaLocal
                ],
                [
                    'title' => 'LaLiga Visitante',
                    'kicker' => '2ª equipación · camiseta · hombre',
                    'rows' => $ligaVisitante
                ],
                [
                    'title' => 'LaLiga Tercera',
                    'kicker' => '3ª equipación · camiseta · hombre',
                    'rows' => $ligaTercera
                ],
            ];
            ?>

            <?php foreach ($ligas as $liga): ?>
                <article class="rank-card">
                    <div class="rank-head">
                        <span class="rank-kicker"><?= e($liga['kicker']) ?></span>
                        <h3><?= e($liga['title']) ?></h3>
                        <p>Clasificación en tiempo real según el último histórico capturado.</p>
                    </div>

                    <?php if (empty($liga['rows'])): ?>
                        <div class="home-empty">Todavía no hay suficientes datos para esta clasificación.</div>
                    <?php else: ?>
                        <table class="rank-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Club</th>
                                    <th>Miniatura</th>
                                    <th>Precio</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $pos = 1; foreach ($liga['rows'] as $row): ?>
                                <?php
                                $price = (float)$row['_current_price'];
                                $posClass = '';
                                if ($pos === 1) $posClass = 'top-1';
                                if ($pos === 2) $posClass = 'top-2';
                                if ($pos === 3) $posClass = 'top-3';
                                ?>
                                <tr>
                                    <td class="rank-pos <?= e($posClass) ?>"><?= $pos ?></td>
                                    <td>
                                        <div class="rank-club">
                                            <?php if (!empty($row['crest_url'])): ?>
                                                <img class="rank-crest-sm" src="<?= e($row['crest_url']) ?>" alt="<?= e($row['club_name']) ?>">
                                            <?php endif; ?>
                                            <div>
                                                <div class="rank-club-name"><?= e($row['club_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['image_url'])): ?>
                                            <img class="rank-kit-thumb" src="<?= e($row['image_url']) ?>" alt="<?= e($row['normalized_title']) ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td class="rank-price"><?= e(format_price($price)) ?></td>
                                </tr>
                                <?php $pos++; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
	<div class="visual-divider">
    <div class="visual-divider-badge">La Batalla de las Camisetas</div>
</div>
<section class="battle-section">
    <div class="battle-title-wrap">
        <h2 class="battle-title">La Batalla de las Camisetas</h2>
        <p class="battle-subtitle">
            Enfrenta dos camisetas fan de hombre y descubre cuál domina el combate por precio.
        </p>
    </div>

    <div class="battle-controls">
        <div class="battle-control-card">
            <div class="battle-control-label">Jugador 1</div>
            <select id="battlePlayer1" class="battle-select">
                <?php foreach ($battleOptions as $option): ?>
                    <option value="<?= (int)$option['id'] ?>">
                        <?= e($option['option_label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="battle-vs-chip">VS</div>

        <div class="battle-control-card">
            <div class="battle-control-label">Jugador 2</div>
            <select id="battlePlayer2" class="battle-select">
                <?php foreach ($battleOptions as $index => $option): ?>
                    <option value="<?= (int)$option['id'] ?>" <?= $index === 1 ? 'selected' : '' ?>>
                        <?= e($option['option_label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="battle-start-wrap">
        <button id="battleStartBtn" class="battle-start-btn" type="button">¡Luchar!</button>
    </div>

    <div class="battle-arena">
        <div class="battle-fighter" id="fighter1">
            <div class="battle-health">
                <div class="battle-health-fill battle-health-fill-p1" id="health1"></div>
            </div>

            <div class="battle-card">
                <div class="battle-crest-wrap">
                    <img id="fighter1Crest" class="battle-crest" src="" alt="">
                </div>

                <div class="battle-image-wrap">
                    <img id="fighter1Image" class="battle-image" src="" alt="">
                </div>

                <div class="battle-meta">
                    <div id="fighter1Club" class="battle-club"></div>
                    <div id="fighter1Kit" class="battle-kit"></div>
                    <div id="fighter1Price" class="battle-price"></div>
                </div>
            </div>
        </div>

        <div class="battle-center">
            <div class="battle-vs-big">VS</div>
            <div class="battle-hit-text" id="battleHitText">Ready?</div>
        </div>

        <div class="battle-fighter" id="fighter2">
            <div class="battle-health">
                <div class="battle-health-fill battle-health-fill-p2" id="health2"></div>
            </div>

            <div class="battle-card">
                <div class="battle-crest-wrap">
                    <img id="fighter2Crest" class="battle-crest" src="" alt="">
                </div>

                <div class="battle-image-wrap">
                    <img id="fighter2Image" class="battle-image" src="" alt="">
                </div>

                <div class="battle-meta">
                    <div id="fighter2Club" class="battle-club"></div>
                    <div id="fighter2Kit" class="battle-kit"></div>
                    <div id="fighter2Price" class="battle-price"></div>
                </div>
            </div>
        </div>

        <div class="battle-result" id="battleResult">
            <div class="battle-result-kicker">Ganador</div>
            <div class="battle-result-title" id="battleResultTitle"></div>
            <div class="battle-result-subtitle" id="battleResultSubtitle"></div>
        </div>
    </div>
</section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const historyLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const historyDatasets = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE) ?>;



const chartBackgroundPlugin = {
    id: 'chartBackgroundPlugin',
    beforeDraw(chart) {
        const {ctx, chartArea} = chart;
        if (!chartArea) return;

        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, 'rgba(255,255,255,0.03)');
        gradient.addColorStop(1, 'rgba(255,255,255,0.00)');

        ctx.save();
        ctx.fillStyle = gradient;
        ctx.fillRect(chartArea.left, chartArea.top, chartArea.right - chartArea.left, chartArea.bottom - chartArea.top);
        ctx.restore();
    }
};

const glowPlugin = {
    id: 'glowPlugin',
    beforeDatasetDraw(chart, args) {
        const ctx = chart.ctx;
        const dataset = chart.data.datasets[args.index];

        ctx.save();

        if (dataset.label === 'Atletico de Madrid') {
            ctx.shadowColor = 'rgba(200,16,46,0.65)';
        } else if (dataset.label === 'Athletic Club') {
            ctx.shadowColor = 'rgba(215,25,32,0.65)';
        } else {
            ctx.shadowColor = dataset.borderColor;
        }

        ctx.shadowBlur = 18;
        ctx.lineWidth = dataset.borderWidth || 4;
    },
    afterDatasetDraw(chart) {
        chart.ctx.restore();
    }
};

const chartCanvas = document.getElementById('historyLineChart');
const chartCtx = chartCanvas.getContext('2d');

historyDatasets.forEach(dataset => {
    if (dataset.label === 'Atletico de Madrid') {
        // Rojo con opacidad reducida para que se vea por encima de la línea de Real Madrid
        dataset.borderColor = 'rgba(200,16,46,0.8)';  // Atlético con opacidad baja para que se vea encima
        dataset.backgroundColor = 'rgba(200,16,46,0.3)'; // Menos opacidad
        dataset.pointBackgroundColor = '#C8102E';  // Círculos rojos
        dataset.pointBorderColor = '#C8102E'; // Borde de círculos rojos
        dataset.pointBorderWidth = 7;
    }

    if (dataset.label === 'Real Madrid') {
        // Línea blanca de Real Madrid, sin opacidad
        dataset.borderColor = 'rgba(255,255,255,1)';  // Blanco sólido
        dataset.backgroundColor = 'rgba(255,255,255,1)'; // Blanco sólido
        dataset.pointBackgroundColor = '#FFFFFF';  // Círculos blancos
        dataset.pointBorderColor = '#FFFFFF'; // Borde de círculos blancos
        dataset.pointBorderWidth = 2;
    }

    if (dataset.label === 'Athletic Club') {
        // Establecer los colores para el Athletic Club
        dataset.borderColor = '#D71920';  // Rojo sólido para la línea
        dataset.backgroundColor = '#D71920'; // Rojo sólido para la línea
        dataset.pointBackgroundColor = '#FFFFFF';  // Círculos blancos
        dataset.pointBorderColor = '#D71920';  // Rojo para el borde de los círculos
        dataset.pointBorderWidth = 2;
    }
	
	if (dataset.label === 'Espanyol') {
        dataset.borderColor = '#0064B1';
        dataset.backgroundColor = '#0064B1';
        dataset.pointBackgroundColor = '#E85D2A';
        dataset.pointBorderColor = '#0064B1';
    }

	
	    if (dataset.label === 'Real Sociedad') {
        dataset.borderColor = '#0057B8';
        dataset.backgroundColor = '#0057B8';
        dataset.pointBackgroundColor = '#FFFFFF';
        dataset.pointBorderColor = '#0057B8';
    }
	
	    if (dataset.label === 'Mallorca') {
        dataset.borderColor = '#C4122E';
        dataset.backgroundColor = '#C4122E';
        dataset.pointBackgroundColor = '#111111';
        dataset.pointBorderColor = '#F4C542';
    }   
	   
	if (dataset.label === 'Elche') {
    dataset.borderColor = '#0B8F47';
    dataset.backgroundColor = '#0B8F47';
    dataset.pointBackgroundColor = '#FFFFFF';
    dataset.pointBorderColor = '#0B8F47';
}
	if (dataset.label === 'Valencia') {
    dataset.borderColor = '#F38411';
    dataset.backgroundColor = '#F38411';
    dataset.pointBackgroundColor = '#F38411';
    dataset.pointBorderColor = '#FFD54A';
}
	if (dataset.label === 'Rayo Vallecano') {
    dataset.borderColor = '#FFFFFF';
    dataset.backgroundColor = '#FFFFFF';
    dataset.pointBackgroundColor = '#F2C94C';
    dataset.pointBorderColor = '#D62828';
}
	
	if (dataset.label === 'Celta') {
    dataset.borderColor = '#25C6DA';
    dataset.backgroundColor = '#25C6DA';
    dataset.pointBackgroundColor = '#FFD54A';
    dataset.pointBorderColor = '#D62828';
}

if (dataset.label === 'Getafe') {
    dataset.borderColor = '#1E88E5';
    dataset.backgroundColor = '#1E88E5';
    dataset.pointBackgroundColor = '#FB8C00';
    dataset.pointBorderColor = '#2E7D32';
}
	
	if (dataset.label === 'Girona') {
    dataset.borderColor = '#E85D75';
    dataset.backgroundColor = '#E85D75';
    dataset.pointBackgroundColor = '#F4D35E';
    dataset.pointBorderColor = '#C1121F';
}
	
	if (dataset.label === 'Sevilla') {
    dataset.borderColor = '#C1121F';
    dataset.backgroundColor = '#C1121F';
    dataset.pointBackgroundColor = '#FFFFFF';
    dataset.pointBorderColor = '#C1121F';
}
	
	if (dataset.label === 'Alavés' || dataset.label === 'Alaves') {
    dataset.borderColor = '#0056A4';
    dataset.backgroundColor = '#0056A4';
    dataset.pointBackgroundColor = '#FFFFFF';
    dataset.pointBorderColor = '#0056A4';
    dataset.pointBorderWidth = 2;
}
	
	if (dataset.label === 'Levante') {
    dataset.borderColor = '#A61E36';
    dataset.backgroundColor = '#A61E36';
    dataset.pointBackgroundColor = '#0056A4';
    dataset.pointBorderColor = '#A61E36';
    dataset.pointBorderWidth = 2;
}






});

new Chart(chartCanvas, {
    type: 'line',
    data: {
        labels: historyLabels,
        datasets: historyDatasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        animation: {
            duration: 1500,
            easing: 'easeOutQuart'
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(17,21,18,.96)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: 'rgba(255,255,255,.10)',
                borderWidth: 1,
                padding: 12,
                callbacks: {
                    label: function(context) {
                        if (context.parsed.y === null) return '';
                        return context.dataset.label + ': ' + Number(context.parsed.y).toFixed(2).replace('.', ',') + ' €';
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: 'rgba(255,255,255,.72)',
                    maxRotation: 0,
                    autoSkip: true
                },
                grid: {
                    color: 'rgba(255,255,255,.07)'
                }
            },
            y: {
                beginAtZero: false,
                ticks: {
                    color: 'rgba(255,255,255,.72)',
                    callback: function(value) {
                        return value + ' €';
                    }
                },
                grid: {
                    color: 'rgba(255,255,255,.07)'
                }
            }
        }
    },
    plugins: [chartBackgroundPlugin, glowPlugin]
});
	
</script>
<script>
const battleOptions = <?= json_encode(array_column($battleOptions, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const player1Select = document.getElementById('battlePlayer1');
const player2Select = document.getElementById('battlePlayer2');
const battleStartBtn = document.getElementById('battleStartBtn');

const fighter1 = document.getElementById('fighter1');
const fighter2 = document.getElementById('fighter2');

const fighter1Crest = document.getElementById('fighter1Crest');
const fighter1Image = document.getElementById('fighter1Image');
const fighter1Club = document.getElementById('fighter1Club');
const fighter1Kit = document.getElementById('fighter1Kit');
const fighter1Price = document.getElementById('fighter1Price');

const fighter2Crest = document.getElementById('fighter2Crest');
const fighter2Image = document.getElementById('fighter2Image');
const fighter2Club = document.getElementById('fighter2Club');
const fighter2Kit = document.getElementById('fighter2Kit');
const fighter2Price = document.getElementById('fighter2Price');

const health1 = document.getElementById('health1');
const health2 = document.getElementById('health2');

const battleHitText = document.getElementById('battleHitText');
const battleResult = document.getElementById('battleResult');
const battleResultTitle = document.getElementById('battleResultTitle');
const battleResultSubtitle = document.getElementById('battleResultSubtitle');

function renderBattlePreview(side, option) {
    if (!option) return;

    if (side === 1) {
        fighter1Crest.src = option.crest_url || '';
        fighter1Crest.alt = option.club_name || '';
        fighter1Image.src = option.image_url || '';
        fighter1Image.alt = option.normalized_title || '';
        fighter1Club.textContent = option.club_name || '';
        fighter1Kit.textContent = option.kit_label || '';
        fighter1Price.textContent = Number(option.current_price).toFixed(2).replace('.', ',') + ' €';
    } else {
        fighter2Crest.src = option.crest_url || '';
        fighter2Crest.alt = option.club_name || '';
        fighter2Image.src = option.image_url || '';
        fighter2Image.alt = option.normalized_title || '';
        fighter2Club.textContent = option.club_name || '';
        fighter2Kit.textContent = option.kit_label || '';
        fighter2Price.textContent = Number(option.current_price).toFixed(2).replace('.', ',') + ' €';
    }
}

function resetBattleVisuals() {
    fighter1.classList.remove('is-winner', 'is-defeated', 'is-hit');
    fighter2.classList.remove('is-winner', 'is-defeated', 'is-hit');
    battleResult.classList.remove('show');
    battleHitText.textContent = 'Ready?';
    health1.style.width = '100%';
    health2.style.width = '100%';
}

function setupBattlePreview() {
    const p1 = battleOptions[player1Select.value];
    const p2 = battleOptions[player2Select.value];
    renderBattlePreview(1, p1);
    renderBattlePreview(2, p2);
}

function flashHit(side, text) {
    battleHitText.textContent = text;

    if (side === 1) {
        fighter1.classList.remove('is-hit');
        void fighter1.offsetWidth;
        fighter1.classList.add('is-hit');
    } else {
        fighter2.classList.remove('is-hit');
        void fighter2.offsetWidth;
        fighter2.classList.add('is-hit');
    }
}

function finishBattle(winner, loser, diff, winnerSide) {
    if (winnerSide === 1) {
        fighter1.classList.add('is-winner');
        fighter2.classList.add('is-defeated');
    } else {
        fighter2.classList.add('is-winner');
        fighter1.classList.add('is-defeated');
    }

    battleHitText.textContent = 'K.O.';

    battleResultTitle.textContent = winner.club_name + ' · ' + winner.kit_label;
battleResultSubtitle.textContent = 'Ha ganado por ' + diff.toFixed(2).replace('.', ',') + ' € frente a ' + loser.club_name + ' · ' + loser.kit_label + '.';

    setTimeout(() => {
        battleResult.classList.add('show');
    }, 300);
}

function startBattle() {
    const p1 = battleOptions[player1Select.value];
    const p2 = battleOptions[player2Select.value];

    if (!p1 || !p2) return;

    if (String(p1.id) === String(p2.id)) {
        battleHitText.textContent = 'Selecciona camisetas distintas';
        return;
    }

    resetBattleVisuals();
    setupBattlePreview();

    battleStartBtn.disabled = true;
    player1Select.disabled = true;
    player2Select.disabled = true;

    let hp1 = 100;
    let hp2 = 100;

    const price1 = Number(p1.current_price);
    const price2 = Number(p2.current_price);

    const total = price1 + price2;
    const damageTo1 = Math.max(5, (price2 / total) * 18);
    const damageTo2 = Math.max(5, (price1 / total) * 18);

    const rounds = 10;
    let currentRound = 0;

    const hitTexts = ['¡Golpe!', '¡Impacto!', '¡Combo!', '¡Crítico!', '¡Directo!'];

    const timer = setInterval(() => {
        currentRound++;

        const jitter1 = (Math.random() * 3) - 1.5;
        const jitter2 = (Math.random() * 3) - 1.5;

        hp1 = Math.max(0, hp1 - (damageTo1 + jitter1));
        hp2 = Math.max(0, hp2 - (damageTo2 + jitter2));

        health1.style.width = hp1 + '%';
        health2.style.width = hp2 + '%';

        if (price1 > price2) {
            flashHit(2, hitTexts[Math.floor(Math.random() * hitTexts.length)]);
        } else if (price2 > price1) {
            flashHit(1, hitTexts[Math.floor(Math.random() * hitTexts.length)]);
        } else {
            flashHit(Math.random() > 0.5 ? 1 : 2, '¡Empate!');
        }

        if (currentRound >= rounds || hp1 <= 0 || hp2 <= 0) {
            clearInterval(timer);

            let winner, loser, winnerSide;

            if (price1 >= price2) {
                winner = p1;
                loser = p2;
                winnerSide = 1;
                hp1 = Math.max(18, hp1);
                hp2 = 0;
            } else {
                winner = p2;
                loser = p1;
                winnerSide = 2;
                hp2 = Math.max(18, hp2);
                hp1 = 0;
            }

            health1.style.width = hp1 + '%';
            health2.style.width = hp2 + '%';

            const diff = Math.abs(price1 - price2);

            finishBattle(winner, loser, diff, winnerSide);

            battleStartBtn.disabled = false;
            player1Select.disabled = false;
            player2Select.disabled = false;
        }
    }, 420);
}

player1Select.addEventListener('change', setupBattlePreview);
player2Select.addEventListener('change', setupBattlePreview);
battleStartBtn.addEventListener('click', startBattle);

setupBattlePreview();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>