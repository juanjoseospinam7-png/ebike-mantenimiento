<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();
$pdo = db();
$stmt = $pdo->prepare(
    "SELECT r.*, b.codigo AS bici_codigo, b.modelo, a.codigo_alquiler, t.codigo_ticket,
            (SELECT COUNT(*) FROM participantes_reporte p WHERE p.id_reporte=r.id_reporte) AS participantes,
            (SELECT GROUP_CONCAT(e.nombre SEPARATOR ', ') FROM participantes_reporte p
             JOIN empleados e ON e.id_empleado=p.id_empleado WHERE p.id_reporte=r.id_reporte) AS nombres_mecanicos,
            (SELECT COUNT(*) FROM intervenciones_mantenimiento i WHERE i.id_reporte=r.id_reporte) AS intervenciones
     FROM reportes_clientes r
     JOIN bicicletas b ON b.id_bicicleta=r.id_bicicleta
     LEFT JOIN alquileres a ON a.id_alquiler=r.id_alquiler
     LEFT JOIN tickets_alquiler t ON t.id_alquiler=r.id_alquiler
     ORDER BY r.creado_en DESC"
);
$stmt->execute();
$columnas = [
    'En espera' => [], 'En investigación' => [], 'En mantenimiento' => [],
    'Finalizados' => [], 'Cerrados' => [],
];
foreach ($stmt->fetchAll() as $r) {
    $estado = $r['estado'];
    if (in_array($estado, ['En espera'], true)) $col = 'En espera';
    elseif (in_array($estado, ['En investigación'], true)) $col = 'En investigación';
    elseif (in_array($estado, ['En mantenimiento', 'En pruebas'], true)) $col = 'En mantenimiento';
    elseif (in_array($estado, ['Terminado', 'Requiere nueva revisión'], true)) $col = 'Finalizados';
    elseif ($estado === 'Cerrado') $col = 'Cerrados';
    else continue;
    $columnas[$col][] = $r;
}
$pageTitle = 'Centro de validación de incidencias';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro">
    <span class="eyebrow">SUPERVISIÓN DE LA FLOTA</span>
    <h1>Incidencias internas</h1>
    <p>Las incidencias fluyen desde el cliente hasta el equipo técnico y vuelven aquí para su validación final.</p>
</section>
<div class="kanban">
    <?php foreach ($columnas as $nombre => $casos): ?>
        <section class="kanban-col">
            <header><h2><?= e($nombre) ?></h2><span class="count"><?= count($casos) ?></span></header>
            <div class="kanban-body">
                <?php foreach ($casos as $c): ?>
                    <article class="kanban-card">
                        <div class="kanban-top">
                            <span class="code-chip"><?= e($c['bici_codigo']) ?></span>
                            <span class="badge badge-<?= status_class($c['estado']) ?>"><?= e($c['estado']) ?></span>
                        </div>
                        <strong><?= e($c['codigo_seguimiento']) ?></strong>
                        <p><?= e(mb_strimwidth($c['descripcion'], 0, 110, '…')) ?></p>
                        <div class="kanban-meta">
                            <span><span class="material-symbols-outlined">schedule</span><?= e(time_ago($c['creado_en'])) ?></span>
                            <span><span class="material-symbols-outlined">groups</span><?= (int)$c['participantes'] ?></span>
                            <?php if ($c['codigo_alquiler']): ?><span><span class="material-symbols-outlined">receipt_long</span><?= e($c['codigo_alquiler']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($c['nombres_mecanicos']): ?><p class="small muted">Mecánicos: <?= e($c['nombres_mecanicos']) ?></p><?php endif; ?>
                        <a class="btn btn-soft btn-sm btn-block" href="reporte_detalle.php?id=<?= (int)$c['id_reporte'] ?>">Revisar caso</a>
                        <?php if ($nombre === 'Finalizados' && $c['estado'] === 'Terminado'): ?>
                            <form method="post" action="api/validacion.php" class="kanban-actions"><?= csrf_field() ?>
                                <input type="hidden" name="id_reporte" value="<?= (int)$c['id_reporte'] ?>">
                                <button class="btn btn-primary btn-sm" name="accion" value="aprobar" type="submit">Aprobar cierre</button>
                                <button class="btn btn-ghost btn-sm" name="accion" value="nueva_revision" type="submit">Nueva revisión</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$casos): ?><p class="kanban-empty">Sin casos en esta etapa.</p><?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
