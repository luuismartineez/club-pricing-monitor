<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin_login();


$db = db();
$id = (int)get_param('id', '0');

$stmt = $db->prepare("SELECT * FROM clubs WHERE id = ? AND is_active = 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$club = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$club) {
    flash_set('error', 'Club no encontrado.');
    redirect('clubs_manage.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clubName = post_param('club_name');
    $storeUrl = post_param('store_url');
    $kit1 = post_param('kit1_identifier');
    $kit2 = post_param('kit2_identifier');
    $kit3 = post_param('kit3_identifier');
	$crestUrl = post_param('crest_url');

    if ($clubName === '' || $storeUrl === '' || $kit1 === '' || $kit2 === '' || $kit3 === '') {
        flash_set('error', 'Todos los campos son obligatorios.');
        redirect('club_edit.php?id=' . $id);
    }

   $update = $db->prepare("
    UPDATE clubs
    SET club_name = ?, store_url = ?, crest_url = ?, kit1_identifier = ?, kit2_identifier = ?, kit3_identifier = ?
    WHERE id = ?
");
$update->bind_param('ssssssi', $clubName, $storeUrl, $crestUrl, $kit1, $kit2, $kit3, $id);

    flash_set('success', 'Club actualizado correctamente.');
    redirect('clubs_manage.php');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Editar club</h1>
        <p>Actualiza la configuración de scraping del club.</p>
    </div>
</div>

<section class="panel">
    <form method="post">
        <div class="form-grid">
			<div class="full">
    <label>URL del escudo</label>
    <input type="url" name="crest_url" value="<?= e($club['crest_url'] ?? '') ?>">
</div>
			
            <div class="full">
                <label>Nombre del club</label>
                <input type="text" name="club_name" value="<?= e($club['club_name']) ?>" required>
            </div>

            <div class="full">
                <label>URL de tienda oficial</label>
                <input type="url" name="store_url" value="<?= e($club['store_url']) ?>" required>
            </div>

            <div>
                <label>Texto identificador 1ª equipación</label>
                <input type="text" name="kit1_identifier" value="<?= e($club['kit1_identifier']) ?>" required>
            </div>

            <div>
                <label>Texto identificador 2ª equipación</label>
                <input type="text" name="kit2_identifier" value="<?= e($club['kit2_identifier']) ?>" required>
            </div>

            <div class="full">
                <label>Texto identificador 3ª equipación</label>
                <input type="text" name="kit3_identifier" value="<?= e($club['kit3_identifier']) ?>" required>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Guardar cambios</button>
            <a class="btn btn-light" href="clubs_manage.php">Volver</a>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>