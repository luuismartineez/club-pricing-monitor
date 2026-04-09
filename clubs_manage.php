<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_admin_login();

$db = db();

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
        <h1>Gestor de Clubes</h1>
        <p>Edita o elimina clubes registrados. Cada fila incluye los identificadores que usa el scraper para clasificar equipaciones.</p>
    </div>
</div>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
					<th>Escudo</th>
                    <th>Club</th>
                    <th>Tienda</th>
                    <th>1ª</th>
                    <th>2ª</th>
                    <th>3ª</th>
                    <th>Equipaciones activas</th>
                    <th>Acciones</th>
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
                    <td><?= e($club['kit1_identifier']) ?></td>
                    <td><?= e($club['kit2_identifier']) ?></td>
                    <td><?= e($club['kit3_identifier']) ?></td>
                    <td><?= (int)$club['active_products'] ?></td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-light" href="club_edit.php?id=<?= (int)$club['id'] ?>">Editar</a>
                            <a class="btn btn-danger" href="club_delete.php?id=<?= (int)$club['id'] ?>" onclick="return confirm('¿Seguro que quieres eliminar este club?');">Eliminar</a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>