<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_login();
$pdo = db();
$codigo = trim((string)($_GET['codigo'] ?? ''));
if ($codigo === '') {
    $pageTitle = 'Seguimiento';
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="page-intro"><span class="eyebrow">TIEMPO REAL</span><h1>Seguimiento</h1><p>Ingresa el código de tu alquiler (ALQ-) o de una incidencia (INC-) para consultar su estado.</p></section>
    <form class="form-card inline-form" method="get" action="seguimiento.php"><?= csrf_field() ?>
        <input class="input" type="search" name="codigo" placeholder="ALQ-2026-XXXXXX o INC-2026-XXXXXX" required>
        <button class="btn btn-primary" type="submit">Consultar</button>
    </form>
    <?php include __DIR__ . '/partials/footer.php'; exit;
}

if (str_starts_with(strtoupper($codigo), 'ALQ') || str_starts_with(strtoupper($codigo), 'TKT')) {
    redirect('ticket.php?codigo=' . urlencode($codigo));
}

$stmt = $pdo->prepare(
    "SELECT r.*, b.codigo AS bici_codigo_actual, b.estado_operativo, b.modelo AS modelo_actual,
            a.codigo_alquiler, t.codigo_ticket, u.nombre AS nombre_usuario
     FROM reportes_clientes r
     JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
     LEFT JOIN alquileres a ON a.id_alquiler = r.id_alquiler
     LEFT JOIN tickets_alquiler t ON t.id_alquiler = r.id_alquiler
     LEFT JOIN usuarios u ON u.id = r.id_cliente
     WHERE r.codigo_seguimiento = ? LIMIT 1"
);
$stmt->execute([$codigo]);
$r = $stmt->fetch();
if (!$r) { flash('error', 'No encontramos ese código de seguimiento.'); redirect(home_for_role()); }
if (is_client() && (int)($r['id_cliente'] ?? 0) !== (int)(current_user()['id'] ?? 0)) {
    flash('error', 'No tienes permisos para consultar este reporte.');
    redirect('mis_alquileres.php');
}

$stmt = $pdo->prepare('SELECT h.*, e.nombre AS mecanico FROM intervenciones_mantenimiento h
                       JOIN empleados e ON e.id_empleado = h.id_mecanico
                       WHERE h.id_reporte = ? ORDER BY h.creado_en DESC');
$stmt->execute([(int)$r['id_reporte']]);
$intervenciones = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM reporte_fotos WHERE id_reporte = ? ORDER BY id_foto');
$stmt->execute([(int)$r['id_reporte']]);
$fotos = $stmt->fetchAll();
$estadoActual = array_search($r['estado'], REPORT_STATES, true);
$pageTitle = 'Seguimiento ' . $r['codigo_seguimiento'];
include __DIR__ . '/partials/header.php';
?>
<a class="back-link" href="<?= is_client() ? 'mis_alquileres.php' : 'mecanico.php' ?>"><span class="material-symbols-outlined">arrow_back</span>Volver</a>
<div class="detail-layout">
    <section class="panel">
        <div class="panel-head"><h2>Reporte <?= e($r['codigo_seguimiento']) ?></h2><span class="badge badge-<?= status_class($r['estado']) ?>"><?= e($r['estado']) ?></span></div>
        <div class="panel-body">
            <div class="ticket-grid">
                <div><span>Bicicleta</span><strong><?= e($r['codigo_bici']) ?> · <?= e($r['modelo']) ?></strong></div>
                <div><span>Alquiler relacionado</span><strong><?= e($r['codigo_alquiler'] ?: 'Sin alquiler asociado') ?></strong></div>
                <div><span>Ticket</span><strong><?= e($r['codigo_ticket'] ?: '—') ?></strong></div>
                <div><span>Tipo de inconveniente</span><strong><?= e($r['categoria_problema']) ?></strong></div>
                <div><span>Recibido</span><strong><?= e(format_date($r['creado_en'])) ?></strong></div>
                <div><span>Estado operativo de la bici</span><strong><?= e(bike_state_label($r['estado_operativo'])) ?></strong></div>
            </div>
            <h3>Descripción</h3><p class="detail-copy"><?= e($r['descripcion']) ?></p>
            <?php if ($r['observaciones']): ?><h3>Observaciones</h3><p class="detail-copy"><?= e($r['observaciones']) ?></p><?php endif; ?>
            <?php if ($fotos): ?>
                <h3>Fotografías del reporte</h3>
                <div class="photo-strip">
                    <?php foreach ($fotos as $f): ?>
                        <?php if (is_file(__DIR__ . '/' . $f['ruta'])): ?>
                            <a href="<?= e($f['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e($f['ruta']) ?>" alt="Evidencia del reporte"></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <aside class="panel">
        <div class="panel-head"><h2>Línea de tiempo</h2></div>
        <div class="panel-body">
            <ol class="timeline">
                <?php foreach (REPORT_STATES as $idx => $state): ?>
                    <?php if ($state === 'Cancelado') continue; ?>
                    <li class="timeline-item <?= $estadoActual !== false && $idx <= $estadoActual ? 'done' : '' ?> <?= $estadoActual === $idx ? 'active' : '' ?>">
                        <span class="timeline-dot"></span><div><strong><?= e($state) ?></strong></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($r['estado'] === 'Requiere nueva revisión'): ?>
                    <li class="timeline-item active warning"><span class="timeline-dot"></span><div><strong>Requiere nueva revisión</strong></div></li>
                <?php endif; ?>
            </ol>
            <h3>Intervenciones del equipo técnico (<?= count($intervenciones) ?>)</h3>
            <?php foreach ($intervenciones as $i): ?>
                <div class="history-line">
                    <strong><?= e($i['mecanico']) ?> · <?= e($i['estado_reporte']) ?></strong>
                    <p><?= e($i['diagnostico']) ?></p><p><?= e($i['trabajos_realizados']) ?></p>
                    <small><?= e(format_date($i['creado_en'])) ?></small>
                </div>
            <?php endforeach; ?>
            <?php if (!$intervenciones): ?><p class="muted">El equipo técnico aún no registra intervenciones.</p><?php endif; ?>
        </div>
    </aside>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
