<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();
$pdo = db();

$row = $pdo->query(
    "SELECT
       COUNT(*) AS total_bicis,
       SUM(estado_operativo='disponible') AS disponibles,
       SUM(estado_operativo IN ('alquilada','reservada')) AS ocupadas,
       SUM(estado_operativo IN ('en_mantenimiento','en_revision','fuera_de_servicio')) AS mantenimiento,
       SUM(nivel_bateria < 20) AS bateria_baja
     FROM bicicletas"
)->fetch();
$bicis = $row;
$row = $pdo->query(
    "SELECT
       COUNT(*) AS total,
       SUM(estado IN ('solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada')) AS activos,
       SUM(estado='activo') AS en_curso,
       SUM(estado='finalizado') AS finalizados,
       COALESCE(SUM(CASE WHEN estado_pago='confirmado' THEN precio_total ELSE 0 END),0) AS ingresos
     FROM alquileres"
)->fetch();
$alq = $row;
$row = $pdo->query(
    "SELECT
       SUM(estado IN ('En espera','En investigación','En mantenimiento','En pruebas','Requiere nueva revisión')) AS pendientes,
       SUM(estado='Terminado') AS por_validar,
       SUM(estado='Cerrado') AS cerrados
     FROM reportes_clientes"
)->fetch();
$rep = $row;

$porEstado = $pdo->query("SELECT estado, COUNT(*) AS total FROM alquileres GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$ultimosAlq = $pdo->query(
    "SELECT a.codigo_alquiler, a.estado, a.precio_total, a.fecha_inicio, b.codigo AS bici, u.nombre AS cliente
     FROM alquileres a JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
     JOIN usuarios u ON u.id=a.id_cliente ORDER BY a.creado_en DESC LIMIT 6"
)->fetchAll();
$ultimosRep = $pdo->query(
    "SELECT r.codigo_seguimiento, r.estado, r.categoria_problema, r.creado_en, b.codigo AS bici
     FROM reportes_clientes r JOIN bicicletas b ON b.id_bicicleta=r.id_bicicleta
     ORDER BY r.creado_en DESC LIMIT 6"
)->fetchAll();
$bateriaBaja = $pdo->query('SELECT codigo, modelo, nivel_bateria FROM bicicletas WHERE nivel_bateria < 20 ORDER BY nivel_bateria LIMIT 5')->fetchAll();

$pageTitle = 'Centro de operaciones';
include __DIR__ . '/partials/header.php';
?>
<section class="ops-hero">
    <div><span class="eyebrow">eBike · MOVILIDAD ELÉCTRICA</span><h1>Centro de operaciones</h1><p>Una sola vista de alquileres, flota y mantenimiento interno.</p></div>
    <div class="ops-hero-stat"><span>Ingresos confirmados</span><strong><?= e(money($alq['ingresos'] ?? 0)) ?></strong></div>
</section>

<div class="kpis">
    <div class="card kpi"><div class="kpi-top"><span>Total de bicicletas</span><span class="kpi-icon material-symbols-outlined">electric_bike</span></div><strong class="kpi-value"><?= (int)($bicis['total_bicis'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Disponibles</span><span class="kpi-icon material-symbols-outlined">check_circle</span></div><strong class="kpi-value success"><?= (int)($bicis['disponibles'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Alquiladas / reservadas</span><span class="kpi-icon material-symbols-outlined">event_busy</span></div><strong class="kpi-value info"><?= (int)($bicis['ocupadas'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>En mantenimiento</span><span class="kpi-icon material-symbols-outlined">build</span></div><strong class="kpi-value warning"><?= (int)($bicis['mantenimiento'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Alquileres activos</span><span class="kpi-icon material-symbols-outlined">route</span></div><strong class="kpi-value"><?= (int)($alq['activos'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Finalizados</span><span class="kpi-icon material-symbols-outlined">task_alt</span></div><strong class="kpi-value success"><?= (int)($alq['finalizados'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Incidencias pendientes</span><span class="kpi-icon material-symbols-outlined">report_problem</span></div><strong class="kpi-value warning"><?= (int)($rep['pendientes'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Por validar</span><span class="kpi-icon material-symbols-outlined">fact_check</span></div><strong class="kpi-value info"><?= (int)($rep['por_validar'] ?? 0) ?></strong></div>
</div>

<div class="grid-main">
    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h2>Últimos alquileres</h2><a class="link" href="alquileres_admin.php">Ver todos</a></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Alquiler</th><th>Bicicleta</th><th>Cliente</th><th>Inicio</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($ultimosAlq as $u): ?>
                    <tr><td><a href="ticket.php?codigo=<?= e($u['codigo_alquiler']) ?>"><?= e($u['codigo_alquiler']) ?></a></td>
                        <td><?= e($u['bici']) ?></td><td><?= e($u['cliente']) ?></td><td><?= e(format_date($u['fecha_inicio'])) ?></td>
                        <td><span class="badge badge-<?= status_class($u['estado']) ?>"><?= e(rental_label($u['estado'])) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$ultimosAlq): ?><tr><td colspan="5" class="center">Todavía no hay alquileres registrados.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Incidencias recientes</h2><a class="link" href="reportes_admin.php">Ver bandejas</a></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Reporte</th><th>Bicicleta</th><th>Tipo</th><th>Recibido</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($ultimosRep as $u): ?>
                    <tr><td><?= e($u['codigo_seguimiento']) ?></td><td><?= e($u['bici']) ?></td><td><?= e($u['categoria_problema']) ?></td>
                        <td><?= e(time_ago($u['creado_en'])) ?></td><td><span class="badge badge-<?= status_class($u['estado']) ?>"><?= e($u['estado']) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$ultimosRep): ?><tr><td colspan="5" class="center">No hay incidencias registradas.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <div class="side-stack">
        <section class="panel">
            <div class="panel-head"><h2>Utilización de la flota</h2></div>
            <div class="panel-body"><canvas id="chartFlota" height="220"></canvas></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Batería baja</h2></div>
            <div class="panel-body">
                <?php foreach ($bateriaBaja as $b): ?>
                    <div class="battery-row"><span><?= e($b['codigo']) ?> · <?= e($b['modelo']) ?></span><strong><?= (int)$b['nivel_bateria'] ?>%</strong></div>
                <?php endforeach; ?>
                <?php if (!$bateriaBaja): ?><p class="muted">Todas las baterías tienen nivel suficiente.</p><?php endif; ?>
                <a class="btn btn-soft btn-block" href="bicicletas.php">Gestionar flota</a>
            </div>
        </section>
        <section class="panel quick">
            <div class="panel-head"><h2>Acciones rápidas</h2></div>
            <a class="quick-item" href="alquileres_admin.php"><span class="material-symbols-outlined">receipt_long</span>Supervisar alquileres</a>
            <a class="quick-item" href="reportes_admin.php"><span class="material-symbols-outlined">fact_check</span>Validar incidencias</a>
            <a class="quick-item" href="bicicletas.php"><span class="material-symbols-outlined">electric_bike</span>Administrar flota</a>
            <a class="quick-item" href="contabilidad.php"><span class="material-symbols-outlined">payments</span>Ver finanzas</a>
        </section>
    </div>
</div>
<script>
const estados = <?= json_encode($porEstado, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('chartFlota'), {
    type: 'doughnut',
    data: {
        labels: ['Solicitudes','Confirmados','Asignados','Activos','Finalizando','Finalizados','Cancelados'],
        datasets: [{
            data: ['solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada','finalizado','cancelado'].map(k => Number(estados[k] || 0)),
            backgroundColor: ['#f0a742','#3a8ee6','#8b6fe8','#16a05d','#e07a3f','#0a5c46','#c73d3d'],
            borderWidth: 0
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
