<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();
$pdo = db();
$user = current_user();
$filtro = $_GET['f'] ?? 'todos';
$extra = '';
$params = [(int)$user['id']];
if ($filtro === 'activos') { $extra = " AND a.estado IN ('solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada')"; }
if ($filtro === 'finalizados') { $extra = " AND a.estado IN ('finalizado','cancelado')"; }

$stmt = $pdo->prepare(
    "SELECT a.*, b.codigo AS bici_codigo, b.modelo, b.nombre AS bici_nombre, b.foto, t.codigo_ticket,
            (SELECT COUNT(*) FROM reportes_clientes r WHERE r.id_alquiler = a.id_alquiler) AS reportes
     FROM alquileres a
     JOIN bicicletas b ON b.id_bicicleta = a.id_bicicleta
     LEFT JOIN tickets_alquiler t ON t.id_alquiler = a.id_alquiler
     WHERE a.id_cliente = ? $extra ORDER BY a.creado_en DESC"
);
$stmt->execute($params);
$alquileres = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT
    SUM(estado IN ('solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada')) AS activos,
    SUM(estado = 'activo') AS en_curso,
    SUM(estado = 'finalizado') AS finalizados,
    COALESCE(SUM(CASE WHEN estado = 'finalizado' THEN precio_total ELSE 0 END),0) AS invertido
    FROM alquileres WHERE id_cliente = ?");
$stmt->execute([(int)$user['id']]);
$k = $stmt->fetch();

$pageTitle = 'Mis alquileres';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro">
    <span class="eyebrow">HOLA, <?= e(mb_strtoupper((string)$user['nombre'])) ?></span>
    <h1>Mis alquileres</h1>
    <p>Consulta tus tickets, el estado de cada alquiler y las novedades de tu flota asignada.</p>
</section>

<div class="kpis kpis-4">
    <div class="card kpi"><div class="kpi-top"><span>Alquileres activos</span><span class="kpi-icon material-symbols-outlined">event_busy</span></div><strong class="kpi-value"><?= (int)($k['activos'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>En curso</span><span class="kpi-icon material-symbols-outlined">pedal_bike</span></div><strong class="kpi-value"><?= (int)($k['en_curso'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Finalizados</span><span class="kpi-icon material-symbols-outlined">task_alt</span></div><strong class="kpi-value"><?= (int)($k['finalizados'] ?? 0) ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Total alquilado</span><span class="kpi-icon material-symbols-outlined">payments</span></div><strong class="kpi-value kpi-small"><?= e(money($k['invertido'] ?? 0)) ?></strong></div>
</div>

<div class="tabs">
    <a class="<?= $filtro === 'todos' ? 'active' : '' ?>" href="mis_alquileres.php">Todos</a>
    <a class="<?= $filtro === 'activos' ? 'active' : '' ?>" href="mis_alquileres.php?f=activos">Activos</a>
    <a class="<?= $filtro === 'finalizados' ? 'active' : '' ?>" href="mis_alquileres.php?f=finalizados">Historial</a>
</div>

<div class="rental-list">
    <?php foreach ($alquileres as $a): ?>
        <article class="rental-card">
            <div class="rental-photo <?= $a['foto'] ? '' : 'is-placeholder' ?>">
                <?php if ($a['foto'] && is_file(__DIR__ . '/' . $a['foto'])): ?>
                    <img src="<?= e($a['foto']) ?>" alt="eBike">
                <?php else: ?><span class="material-symbols-outlined">electric_bike</span><?php endif; ?>
            </div>
            <div class="rental-main">
                <div class="rental-title">
                    <div><h3><?= e($a['bici_nombre'] ?: $a['modelo']) ?></h3><small><?= e($a['bici_codigo']) ?> · Ticket <?= e($a['codigo_ticket']) ?></small></div>
                    <span class="badge badge-<?= status_class($a['estado']) ?>"><?= e(rental_label($a['estado'])) ?></span>
                </div>
                <div class="rental-meta">
                    <span><span class="material-symbols-outlined">calendar_today</span><?= e(format_date($a['fecha_inicio'])) ?></span>
                    <span><span class="material-symbols-outlined">schedule</span><?= (int)$a['duracion_horas'] ?> h</span>
                    <span><span class="material-symbols-outlined">event</span><?= e(format_date($a['fecha_fin_estimada'])) ?></span>
                    <span><span class="material-symbols-outlined">payments</span><?= e(money($a['precio_total'])) ?></span>
                </div>
                <div class="rental-actions">
                    <a class="btn btn-primary btn-sm" href="ticket.php?codigo=<?= e($a['codigo_alquiler']) ?>">Ver ticket y seguimiento</a>
                    <?php if ((int)$a['reportes'] > 0): ?><span class="badge badge-warning"><?= (int)$a['reportes'] ?> incidencia(s)</span><?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$alquileres): ?>
        <div class="empty full"><span class="material-symbols-outlined">receipt_long</span><h3>Aún no tienes alquileres</h3><p>Explora la flota y reserva tu primera eBike.</p><a class="btn btn-primary" href="cliente.php">Explorar flota</a></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
