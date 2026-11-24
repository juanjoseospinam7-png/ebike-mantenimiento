<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();
$pdo = db();
$filtro = $_GET['f'] ?? 'todos';
$where = ['1=1'];
if ($filtro === 'activos') $where[] = "a.estado IN ('solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada')";
if ($filtro === 'finalizados') $where[] = "a.estado IN ('finalizado','cancelado')";
if ($filtro === 'incidencias') $where[] = 'EXISTS (SELECT 1 FROM reportes_clientes r WHERE r.id_alquiler=a.id_alquiler)';
$stmt = $pdo->prepare(
    "SELECT a.*, b.codigo AS bici_codigo, b.estado_operativo, u.nombre AS cliente, t.codigo_ticket,
            (SELECT COUNT(*) FROM reportes_clientes r WHERE r.id_alquiler=a.id_alquiler) AS reportes
     FROM alquileres a JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
     JOIN usuarios u ON u.id=a.id_cliente
     LEFT JOIN tickets_alquiler t ON t.id_alquiler=a.id_alquiler
     WHERE " . implode(' AND ', $where) . " ORDER BY a.creado_en DESC"
);
$stmt->execute(); $alquileres = $stmt->fetchAll();
$pageTitle = 'Gestión de alquileres';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro"><span class="eyebrow">OPERACIONES</span><h1>Alquileres</h1><p>Supervisa solicitudes, entregas, finalizaciones y estado de pago de la flota.</p></section>
<div class="tabs">
    <a class="<?= $filtro === 'todos' ? 'active' : '' ?>" href="alquileres_admin.php">Todos</a>
    <a class="<?= $filtro === 'activos' ? 'active' : '' ?>" href="alquileres_admin.php?f=activos">Activos</a>
    <a class="<?= $filtro === 'finalizados' ? 'active' : '' ?>" href="alquileres_admin.php?f=finalizados">Historial</a>
    <a class="<?= $filtro === 'incidencias' ? 'active' : '' ?>" href="alquileres_admin.php?f=incidencias">Con incidencias</a>
</div>
<div class="panel">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Alquiler</th><th>Cliente / Bicicleta</th><th>Fechas</th><th>Total</th><th>Estado</th><th>Pago</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($alquileres as $a): ?>
            <tr>
                <td><strong><?= e($a['codigo_alquiler']) ?></strong><br><small><?= e($a['codigo_ticket'] ?: '—') ?></small></td>
                <td><?= e($a['cliente']) ?><br><small><?= e($a['bici_codigo']) ?> · <?= e(bike_state_label($a['estado_operativo'])) ?></small></td>
                <td><?= e(format_date($a['fecha_inicio'])) ?><br><small>→ <?= e(format_date($a['fecha_fin_estimada'])) ?></small></td>
                <td><?= e(money($a['precio_total'])) ?></td>
                <td><span class="badge badge-<?= status_class($a['estado']) ?>"><?= e(rental_label($a['estado'])) ?></span></td>
                <td><span class="badge badge-<?= status_class($a['estado_pago']) ?>"><?= e($a['estado_pago']) ?></span></td>
                <td>
                    <a class="btn btn-soft btn-sm" href="ticket.php?codigo=<?= e($a['codigo_alquiler']) ?>">Ticket</a>
                    <?php if ((int)$a['reportes'] > 0): ?><span class="badge badge-warning"><?= (int)$a['reportes'] ?> incid.</span><?php endif; ?>
                    <?php if (!in_array($a['estado'], ['finalizado', 'cancelado'], true)): ?>
                        <form method="post" action="api/alquiler.php" class="inline-form actions-cell">
                            <?= csrf_field() ?><input type="hidden" name="id_alquiler" value="<?= (int)$a['id_alquiler'] ?>">
                            <?php if ($a['estado'] === 'solicitud_recibida'): ?>
                                <button class="btn btn-primary btn-sm" name="accion" value="confirmar" type="submit">Confirmar</button>
                            <?php elseif ($a['estado'] === 'confirmado'): ?>
                                <button class="btn btn-primary btn-sm" name="accion" value="asignar" type="submit">Asignar bici</button>
                            <?php elseif ($a['estado'] === 'asignado'): ?>
                                <button class="btn btn-primary btn-sm" name="accion" value="activar" type="submit">Marcar entrega</button>
                            <?php elseif (in_array($a['estado'], ['activo', 'finalizacion_solicitada'], true)): ?>
                                <button class="btn btn-primary btn-sm" name="accion" value="finalizar" type="submit">Finalizar</button>
                            <?php endif; ?>
                            <button class="btn btn-ghost btn-sm" name="accion" value="cancelar" type="submit">Cancelar</button>
                        </form>
                    <?php endif; ?>
                    <details class="pay-details"><summary>Pago</summary>
                        <form method="post" action="api/alquiler.php" class="stack-form"><?= csrf_field() ?>
                            <input type="hidden" name="accion" value="registrar_pago">
                            <input type="hidden" name="id_alquiler" value="<?= (int)$a['id_alquiler'] ?>">
                            <select class="input" name="estado_pago"><option>pendiente</option><option>confirmado</option><option>fallido</option></select>
                            <input class="input" name="metodo" placeholder="Método (Efectivo, Transferencia…)">
                            <input class="input" name="referencia" placeholder="Referencia interna">
                            <button class="btn btn-primary btn-sm" type="submit">Registrar</button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$alquileres): ?><tr><td colspan="7" class="center">No hay alquileres en esta vista.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
