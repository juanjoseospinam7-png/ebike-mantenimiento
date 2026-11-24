<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, b.codigo AS bici_codigo_actual, b.estado_operativo, b.numero_serie, b.nivel_bateria,
            a.codigo_alquiler, t.codigo_ticket, e.nombre AS responsable
     FROM reportes_clientes r
     JOIN bicicletas b ON b.id_bicicleta=r.id_bicicleta
     LEFT JOIN alquileres a ON a.id_alquiler=r.id_alquiler
     LEFT JOIN tickets_alquiler t ON t.id_alquiler=r.id_alquiler
     LEFT JOIN empleados e ON e.id_empleado=r.id_tecnico
     WHERE r.id_reporte = ?"
);
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { flash('error', 'El caso no existe.'); redirect(is_admin() ? 'reportes_admin.php' : 'mecanico.php'); }

$stmt = $pdo->prepare('SELECT p.*, e.nombre, e.especialidad FROM participantes_reporte p
                       JOIN empleados e ON e.id_empleado=p.id_empleado WHERE p.id_reporte=? ORDER BY p.creado_en');
$stmt->execute([$id]); $participantes = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT i.*, e.nombre AS mecanico, rep.nombre_rep FROM intervenciones_mantenimiento i
                       JOIN empleados e ON e.id_empleado=i.id_mecanico
                       LEFT JOIN inventario_costos_arreglos rep ON rep.id_repuesto=i.id_repuesto
                       WHERE i.id_reporte=? ORDER BY i.creado_en DESC');
$stmt->execute([$id]); $intervenciones = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM reporte_fotos WHERE id_reporte=? ORDER BY id_foto');
$stmt->execute([$id]); $fotos = $stmt->fetchAll();
$repuestos = $pdo->query('SELECT id_repuesto, codigo_rep, nombre_rep, cantidad_disponible FROM inventario_costos_arreglos WHERE activo=1 ORDER BY nombre_rep')->fetchAll();
$yaParticipa = false;
foreach ($participantes as $p) if ((int)$p['id_empleado'] === (int)(current_user()['id_empleado'] ?? 0)) $yaParticipa = true;
$pageTitle = 'Caso ' . $r['codigo_seguimiento'];
include __DIR__ . '/partials/header.php';
?>
<a class="back-link" href="<?= is_admin() ? 'reportes_admin.php' : 'mecanico.php' ?>"><span class="material-symbols-outlined">arrow_back</span>Volver a la bandeja</a>
<div class="detail-layout">
    <section>
        <div class="panel">
            <div class="panel-head">
                <h2><?= e($r['codigo_seguimiento']) ?> · <?= e($r['bici_codigo']) ?></h2>
                <span class="badge badge-<?= status_class($r['estado']) ?>"><?= e($r['estado']) ?></span>
            </div>
            <div class="panel-body">
                <div class="ticket-grid">
                    <div><span>Modelo</span><strong><?= e($r['modelo']) ?></strong></div>
                    <div><span>Ticket de alquiler</span><strong><?= e($r['codigo_ticket'] ?: '—') ?></strong></div>
                    <div><span>Cliente</span><strong><?= e($r['nombre_cliente']) ?></strong></div>
                    <div><span>Tipo de problema</span><strong><?= e($r['categoria_problema']) ?></strong></div>
                    <div><span>Recibido</span><strong><?= e(format_date($r['creado_en'])) ?></strong></div>
                    <div><span>Responsable</span><strong><?= e($r['responsable'] ?: 'Sin asignar') ?></strong></div>
                    <div><span>Nivel de batería</span><strong><?= (int)$r['nivel_bateria'] ?>%</strong></div>
                    <div><span>Estado de la bici</span><strong><?= e(bike_state_label($r['estado_operativo'])) ?></strong></div>
                </div>
                <h3>Descripción del problema</h3><p class="detail-copy"><?= e($r['descripcion']) ?></p>
                <?php if ($r['observaciones']): ?><h3>Observaciones del cliente</h3><p class="detail-copy"><?= e($r['observaciones']) ?></p><?php endif; ?>
                <?php if ($fotos): ?>
                    <h3>Fotografías</h3>
                    <div class="photo-strip">
                        <?php foreach ($fotos as $f): if (is_file(__DIR__ . '/' . $f['ruta'])): ?>
                            <a href="<?= e($f['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e($f['ruta']) ?>" alt="Evidencia"></a>
                        <?php endif; endforeach; ?>
                    </div>
                <?php endif; ?>
                <h3>Mecánicos participantes (<?= count($participantes) ?>)</h3>
                <div class="participants">
                    <?php foreach ($participantes as $p): ?>
                        <span class="participant"><span class="material-symbols-outlined">engineering</span><?= e($p['nombre']) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$participantes): ?><span class="muted">Nadie se ha incorporado todavía.</span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Historial compartido de intervenciones</h2></div>
            <div class="panel-body">
                <?php foreach ($intervenciones as $i): ?>
                    <article class="intervention">
                        <header><strong><?= e($i['mecanico']) ?></strong><span class="badge badge-<?= status_class($i['estado_reporte']) ?>"><?= e($i['estado_reporte']) ?></span><small><?= e(format_date($i['creado_en'])) ?></small></header>
                        <p><strong>Diagnóstico:</strong> <?= e($i['diagnostico']) ?></p>
                        <p><strong>Trabajos:</strong> <?= e($i['trabajos_realizados']) ?></p>
                        <?php if ($i['observaciones']): ?><p><strong>Observaciones:</strong> <?= e($i['observaciones']) ?></p><?php endif; ?>
                        <?php if ($i['nombre_rep']): ?><p><strong>Componente:</strong> <?= e($i['nombre_rep']) ?> × <?= (int)$i['cantidad_repuesto'] ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$intervenciones): ?><p class="muted">Todavía no hay intervenciones registradas.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <?php if (is_mechanic()): ?>
            <?php if (!$yaParticipa): ?>
                <form method="post" action="api/reporte_mecanico.php" class="panel form-card"><?= csrf_field() ?>
                    <input type="hidden" name="accion" value="unirme"><input type="hidden" name="id_reporte" value="<?= (int)$r['id_reporte'] ?>">
                    <h3>Participar en este caso</h3>
                    <p class="muted">El reporte pasará a investigación si está en espera. Tu participación quedará registrada.</p>
                    <button class="btn btn-soft btn-block" type="submit"><span class="material-symbols-outlined">group_add</span>Unirme al caso</button>
                </form>
            <?php endif; ?>
            <?php if (!in_array($r['estado'], ['Cerrado', 'Cancelado'], true)): ?>
                <form method="post" action="api/reporte_mecanico.php" class="panel form-card stack-form"><?= csrf_field() ?>
                    <input type="hidden" name="accion" value="intervenir"><input type="hidden" name="id_reporte" value="<?= (int)$r['id_reporte'] ?>">
                    <h3>Registrar intervención</h3>
                    <label>Diagnóstico *
                        <textarea class="input" name="diagnostico" rows="3" minlength="5" required></textarea>
                    </label>
                    <label>Trabajos realizados *
                        <textarea class="input" name="trabajos_realizados" rows="3" minlength="5" required></textarea>
                    </label>
                    <label>Observaciones técnicas
                        <textarea class="input" name="observaciones" rows="2"></textarea>
                    </label>
                    <label>Estado del caso
                        <select class="input" name="estado_reporte">
                            <option>En investigación</option><option>En mantenimiento</option>
                            <option>En pruebas</option><option>Terminado</option>
                            <option>Requiere nueva revisión</option>
                        </select>
                    </label>
                    <label>Componente utilizado (inventario interno)
                        <select class="input" name="id_repuesto">
                            <option value="0">Ninguno</option>
                            <?php foreach ($repuestos as $rep): ?>
                                <option value="<?= (int)$rep['id_repuesto'] ?>"><?= e($rep['nombre_rep']) ?> · stock <?= (int)$rep['cantidad_disponible'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Cantidad <input class="input" type="number" name="cantidad_repuesto" min="0" max="99" value="0"></label>
                    <button class="btn btn-primary btn-block" type="submit"><span class="material-symbols-outlined">save</span>Guardar intervención</button>
                    <p class="fine-print">El historial es inmutable: las intervenciones no pueden eliminarse.</p>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="panel form-card">
                <h3>Supervisión administrativa</h3>
                <p class="muted">Revisa el diagnóstico, las intervenciones y las fotografías desde el centro de validación.</p>
                <a class="btn btn-primary btn-block" href="reportes_admin.php">Ir a validación</a>
            </div>
        <?php endif; ?>
    </aside>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
