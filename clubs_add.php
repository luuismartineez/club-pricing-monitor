<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin_login();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clubName = post_param('club_name');
    $storeUrl = post_param('store_url');
    $kit1 = post_param('kit1_identifier');
    $kit2 = post_param('kit2_identifier');
    $kit3 = post_param('kit3_identifier');
	$crestUrl = post_param('crest_url');

    if ($clubName === '' || $storeUrl === '' || $kit1 === '' || $kit2 === '' || $kit3 === '') {
        flash_set('error', 'Todos los campos son obligatorios.');
        redirect('clubs_add.php');
    }

    $stmt = $db->prepare("
    INSERT INTO clubs (club_name, store_url, crest_url, kit1_identifier, kit2_identifier, kit3_identifier, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1)
");
$stmt->bind_param('ssssss', $clubName, $storeUrl, $crestUrl, $kit1, $kit2, $kit3);
    $stmt->bind_param('sssss', $clubName, $storeUrl, $kit1, $kit2, $kit3);
    $stmt->execute();
    $stmt->close();

    flash_set('success', 'Club registrado correctamente.');
    redirect('clubs_add.php');
}

$clubs = $db->query("SELECT id, club_name, crest_url, store_url, created_at FROM clubs WHERE is_active = 1 ORDER BY club_name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Altas de Club</h1>
        <p>Registra el club, su tienda oficial y los identificadores textuales para localizar 1ª, 2ª y 3ª equipación.</p>
    </div>
</div>

<div class="grid-2">
    <section class="panel">
        <h2>Nuevo club</h2>
        <form method="post">
            <div class="form-grid">
				
                <div class="full">
                    <label>Nombre del club</label>
                    <input type="text" name="club_name" placeholder="Ej. Real Madrid" required>
                </div>

                <div class="full">
                    <label>URL de tienda oficial</label>
                    <input type="url" name="store_url" placeholder="https://..." required>
                </div>
				
				<div class="full">
    <label>URL del escudo (PNG/SVG)</label>
    <input type="url" name="crest_url" placeholder="https://...">
</div>

                <div>
                    <label>Texto identificador 1ª equipación</label>
                    <input type="text" name="kit1_identifier" placeholder="local, home, primera" required>
                </div>

                <div>
                    <label>Texto identificador 2ª equipación</label>
                    <input type="text" name="kit2_identifier" placeholder="visitante, away, segunda" required>
                </div>

                <div class="full">
                    <label>Texto identificador 3ª equipación</label>
                    <input type="text" name="kit3_identifier" placeholder="tercera, third" required>
                </div>
            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit">Guardar club</button>
            </div>
        </form>
    </section>

    <aside class="panel">
        <h2>Clubes dados de alta</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
						<th>Escudo</th>
                        <th>Club</th>
                        <th>URL</th>
                        <th>Alta</th>
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
                        <td><?= e($club['club_name']) ?></td>
                        <td><a href="<?= e($club['store_url']) ?>" target="_blank" rel="noopener">Abrir tienda</a></td>
                        <td><?= e(date('d/m/Y', strtotime($club['created_at']))) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>