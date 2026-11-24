<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();
if (is_admin()) redirect('reportes_admin.php');
$pdo = db();
$user = current_user();
$filtro = $_GET['f'] ?? 'abiertos';
$where = ["r.estado NOT IN ('Cerrado','Cancelado')"];
if ($filtro === 'mios') { $where[] = 'EXISTS (SELECT 1 FROM participantes_reporte p WHERE p.id_reporte=r.id_reporte AND p.id_empleado=?)'; }
if ($filtro === 'terminados') { $where = ["r.estado = 'Terminado'"]; }
$params = [];
if ($filtro === 'mios') $params[] = (int)($user['id_empleado'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, b.codigo AS bici_codigo, b.modelo, b.tipo_bicicleta, b.estado_operativo,
            a.codigo_alquiler, t.codigo_ticket,
            (SELECT COUNT(*) FROM participantes_reporte p WHERE p.id_reporte=r.id_reporte) AS participantes,
            (SELECT COUNT(*) FROM intervenciones_mantenimiento i WHERE i.id_reporte=r.id_reporte) AS intervenciones
     FROM reportes_clientes r
     JOIN bicicletas b ON b.id_bicicleta=r.id_bicicleta
     LEFT JOIN alquileres a ON a.id_alquiler=r.id_alquiler
     LEFT JOIN tickets_alquiler t ON t.id_alquiler=r.id_alquiler
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(r.estado,'En espera','Requiere nueva revisión','En investigación','En mantenimiento','En pruebas','Terminado'), r.creado_en DESC"
);
$stmt->execute($params);
$reportes = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_clientes WHERE estado='En espera'"); $stmt->execute(); $espera = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_clientes WHERE estado IN ('En investigación','En mantenimiento','En pruebas')"); $stmt->execute(); $activos = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM participantes_reporte WHERE id_empleado = ?'); $stmt->execute([(int)($user['id_empleado'] ?? 0)]); $mios = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_clientes WHERE estado='Terminado'"); $stmt->execute(); $terminados = (int)$stmt->fetchColumn();

$pageTitle = 'Bandeja compartida';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro">
    <span class="eyebrow">DEPARTAMENTO TÉCNICO · FLOTA EBIKE</span>
    <h1>Bandeja compartida de incidencias</h1>
    <p>Todos los reportes de la flota están disponibles para el equipo. Únete a un caso y registra tus intervenciones.</p>
</section>

<div class="kpis kpis-4">
    <div class="card kpi"><div class="kpi-top"><span>En espera</span><span class="kpi-icon material-symbols-outlined">pending_actions</span></div><strong class="kpi-value"><?= $espera ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>En trabajo</span><span class="kpi-icon material-symbols-outlined">engineering</span></div><strong class="kpi-value"><?= $activos ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Mis casos</span><span class="kpi-icon material-symbols-outlined">group</span></div><strong class="kpi-value"><?= $mios ?></strong></div>
    <div class="card kpi"><div class="kpi-top"><span>Terminados (validación)</span><span class="kpi-icon material-symbols-outlined">fact_check</span></div><strong class="kpi-value"><?= $terminados ?></strong></div>
</div>

<div class="tabs">
    <a class="<?= $filtro === 'abiertos' ? 'active' : '' ?>" href="mecanico.php">Casos disponibles</a>
    <a class="<?= $filtro === 'mios' ? 'active' : '' ?>" href="mecanico.php?f=mios">Mis participaciones</a>
    <a class="<?= $filtro === 'terminados' ? 'active' : '' ?>" href="mecanico.php?f=terminados">Terminados</a>
</div>

<div class="case-grid">
    <?php foreach ($reportes as $r): ?>
        <article class="case-card">
            <div class="case-top">
                <span class="code-chip"><?= e($r['codigo_seguimiento']) ?></span>
                <span class="badge badge-<?= status_class($r['estado']) ?>"><?= e($r['estado']) ?></span>
            </div>
            <h3><?= e($r['bici_codigo']) ?> · <?= e($r['modelo']) ?></h3>
            <p class="case-desc"><?= e(mb_strimwidth($r['descripcion'], 0, 150, '…')) ?></p>
            <div class="case-meta">
                <span><span class="material-symbols-outlined">person</span><?= e($r['nombre_cliente']) ?></span>
                <span><span class="material-symbols-outlined">receipt_long</span><?= e($r['codigo_alquiler'] ?: 'Sin ticket') ?></span>
                <span><span class="material-symbols-outlined">schedule</span><?= e(time_ago($r['creado_en'])) ?></span>
            </div>
            <?php if ($r['foto'] || $pdo->query('SELECT 1 FROM reporte_fotos WHERE id_reporte=' . (int)$r['id_reporte'] . ' LIMIT 1')->fetchColumn()): ?>
                <span class="case-photo-flag"><span class="material-symbols-outlined">photo_camera</span>Con fotografías</span>
            <?php endif; ?>
            <div class="case-foot">
                <span class="muted small"><?= (int)$r['participantes'] ?> mecánico(s) · <?= (int)$r['intervenciones'] ?> intervención(es)</span>
                <a class="btn btn-primary btn-sm" href="reporte_detalle.php?id=<?= (int)$r['id_reporte'] ?>">Abrir caso</a>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$reportes): ?><div class="empty full"><span class="material-symbols-outlined">inbox</span><h3>No hay casos en esta bandeja</h3></div><?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
