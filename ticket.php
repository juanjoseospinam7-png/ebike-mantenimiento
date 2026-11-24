<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_login();
$pdo = db();
$codigo = trim((string)($_GET['codigo'] ?? ''));
$stmt = $pdo->prepare(
    "SELECT a.*, t.codigo_ticket, t.emitido_en, t.cliente_snapshot, t.bicicleta_snapshot, t.codigo_bici_snapshot,
            b.codigo AS bici_codigo, b.modelo, b.nombre AS bici_nombre, b.foto, b.capacidad_bateria_wh,
            u.nombre AS cliente_nombre, u.email AS cliente_email, u.telefono AS cliente_telefono
     FROM alquileres a
     LEFT JOIN tickets_alquiler t ON t.id_alquiler = a.id_alquiler
     JOIN bicicletas b ON b.id_bicicleta = a.id_bicicleta
     JOIN usuarios u ON u.id = a.id_cliente
     WHERE a.codigo_alquiler = ? OR t.codigo_ticket = ? LIMIT 1"
);
$stmt->execute([$codigo, $codigo]);
$a = $stmt->fetch();
if (!$a) { flash('error', 'No existe un alquiler con ese código.'); redirect(home_for_role()); }
if (is_client() && (int)$a['id_cliente'] !== (int)(current_user()['id'] ?? 0)) {
    flash('error', 'No tienes permisos para consultar este alquiler.');
    redirect('mis_alquileres.php');
}
if (!is_client() && !is_admin() && !is_mechanic()) redirect('login.php');

$stmt = $pdo->prepare('SELECT * FROM alquiler_historial WHERE id_alquiler = ? ORDER BY creado_en, id_historial');
$stmt->execute([(int)$a['id_alquiler']]);
$historial = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id_reporte, codigo_seguimiento, categoria_problema, estado, descripcion, creado_en
                       FROM reportes_clientes WHERE id_alquiler = ? ORDER BY creado_en DESC');
$stmt->execute([(int)$a['id_alquiler']]);
$incidencias = $stmt->fetchAll();

$pageTitle = 'Ticket ' . ($a['codigo_ticket'] ?: $a['codigo_alquiler']);
include __DIR__ . '/partials/header.php';
?>
<a class="back-link" href="<?= is_client() ? 'mis_alquileres.php' : 'alquileres_admin.php' ?>"><span class="material-symbols-outlined">arrow_back</span>Volver</a>

<div class="ticket-wrap">
    <section class="ticket-print">
        <header class="ticket-head">
            <div class="ticket-brand">
                <span class="material-symbols-outlined">electric_bike</span>
                <div><strong>eBike</strong><small>Movilidad Eléctrica</small></div>
            </div>
            <div class="ticket-head-right">
                <small>TICKET DE ALQUILER</small>
                <strong><?= e($a['codigo_ticket'] ?: $a['codigo_alquiler']) ?></strong>
            </div>
        </header>

        <div class="ticket-status">
            <span class="badge badge-<?= status_class($a['estado']) ?>"><?= e(rental_label($a['estado'])) ?></span>
            <span class="badge badge-<?= status_class($a['estado_pago']) ?>">Pago: <?= e(ucfirst($a['estado_pago'])) ?></span>
            <small>Emitido <?= e(format_date($a['emitido_en'] ?: $a['creado_en'])) ?></small>
        </div>

        <div class="ticket-grid">
            <div><span>Código de alquiler</span><strong><?= e($a['codigo_alquiler']) ?></strong></div>
            <div><span>Código de bicicleta</span><strong><?= e($a['bici_codigo']) ?></strong></div>
            <div><span>Bicicleta</span><strong><?= e($a['bicicleta_snapshot'] ?: ($a['bici_nombre'] . ' ' . $a['modelo'])) ?></strong></div>
            <div><span>Cliente</span><strong><?= e($a['cliente_snapshot'] ?: $a['cliente_nombre']) ?></strong></div>
            <div><span>Contacto</span><strong><?= e($a['cliente_email'] ?: '—') ?> <?= e($a['cliente_telefono'] ?: '') ?></strong></div>
            <div><span>Duración contratada</span><strong><?= (int)$a['duracion_horas'] ?> horas</strong></div>
            <div><span>Inicio</span><strong><?= e(format_date($a['fecha_inicio'])) ?></strong></div>
            <div><span>Fin estimado</span><strong><?= e(format_date($a['fecha_fin_estimada'])) ?></strong></div>
            <?php if ($a['fecha_fin_real']): ?><div><span>Fin real</span><strong><?= e(format_date($a['fecha_fin_real'])) ?></strong></div><?php endif; ?>
            <div><span>Precio total</span><strong><?= e(money($a['precio_total'])) ?></strong></div>
        </div>
        <?php if ($a['notas']): ?><p class="ticket-note"><strong>Notas:</strong> <?= e($a['notas']) ?></p><?php endif; ?>
        <p class="fine-print">El pago se registra como pendiente/confirmado/fallido según gestión interna. Este sistema no ejecuta cobros en línea.</p>
    </section>

    <div class="ticket-side">
        <section class="panel">
            <div class="panel-head"><h2>Seguimiento del alquiler</h2></div>
            <div class="panel-body">
                <ol class="timeline">
                    <?php foreach (RENTAL_TIMELINE as $idx => $state): ?>
                        <?php
                        $estadoActual = array_search($a['estado'], RENTAL_TIMELINE, true);
                        $cancelado = $a['estado'] === 'cancelado';
                        $done = !$cancelado && $estadoActual !== false && $idx <= $estadoActual;
                        $active = !$cancelado && $idx === $estadoActual;
                        $fecha = null;
                        foreach ($historial as $h) if ($h['estado'] === $state) { $fecha = $h['creado_en']; break; }
                        ?>
                        <li class="timeline-item <?= $done ? 'done' : '' ?> <?= $active ? 'active' : '' ?>">
                            <span class="timeline-dot"></span>
                            <div><strong><?= e(rental_label($state)) ?></strong><small><?= $fecha ? e(format_date($fecha)) : 'Pendiente' ?></small></div>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($cancelado): ?><li class="timeline-item danger"><span class="timeline-dot"></span><div><strong>Alquiler cancelado</strong></div></li><?php endif; ?>
                </ol>
                <details class="history-details">
                    <summary>Historial de novedades (<?= count($historial) ?>)</summary>
                    <?php foreach (array_reverse($historial) as $h): ?>
                        <div class="history-line"><strong><?= e(rental_label($h['estado'])) ?></strong><p><?= e($h['descripcion']) ?></p><small><?= e(format_date($h['creado_en'])) ?></small></div>
                    <?php endforeach; ?>
                </details>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Incidencias de este alquiler</h2></div>
            <div class="panel-body">
                <?php foreach ($incidencias as $inc): ?>
                    <div class="incident-line">
                        <div><strong><?= e($inc['codigo_seguimiento']) ?></strong><span class="badge badge-<?= status_class($inc['estado']) ?>"><?= e($inc['estado']) ?></span></div>
                        <p><?= e($inc['categoria_problema']) ?> · <?= e(mb_strimwidth($inc['descripcion'], 0, 90, '…')) ?></p>
                        <a class="link" href="reporte_estado.php?codigo=<?= e($inc['codigo_seguimiento']) ?>">Ver estado del reporte</a>
                    </div>
                <?php endforeach; ?>
                <?php if (!$incidencias): ?><p class="muted">No has reportado inconvenientes en este alquiler.</p><?php endif; ?>
                <?php if (is_client() && !in_array($a['estado'], ['finalizado', 'cancelado'], true)): ?>
                    <a class="btn btn-danger btn-block" href="reportar.php?alquiler=<?= e($a['codigo_alquiler']) ?>">
                        <span class="material-symbols-outlined">report_problem</span>Reportar un problema
                    </a>
                    <?php if ($a['estado'] === 'activo'): ?>
                        <form method="post" action="api/alquiler.php" class="inline-form"><?= csrf_field() ?>
                            <input type="hidden" name="accion" value="solicitar_finalizacion">
                            <input type="hidden" name="id_alquiler" value="<?= (int)$a['id_alquiler'] ?>">
                            <button class="btn btn-soft btn-block" type="submit">Solicitar finalización</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<div class="no-print"><button class="btn btn-soft" onclick="window.print()"><span class="material-symbols-outlined">print</span>Imprimir ticket</button></div>
<?php include __DIR__ . '/partials/footer.php'; ?>
