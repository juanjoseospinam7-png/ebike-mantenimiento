<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();
$pdo = db();
$periodo = $_GET['periodo'] ?? 'mes';
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
$cond = ['1=1']; $params = [];
if ($desde !== '' && $hasta !== '') {
    $periodo = 'custom';
    $cond[] = 'DATE(fecha_inicio) BETWEEN ? AND ?'; array_push($params, $desde, $hasta);
} elseif ($periodo === 'hoy') { $cond[] = 'DATE(fecha_inicio)=CURDATE()'; }
elseif ($periodo === 'semana') { $cond[] = 'fecha_inicio >= DATE_SUB(NOW(), INTERVAL 7 DAY)'; }
elseif ($periodo === 'anio') { $cond[] = 'fecha_inicio >= DATE_SUB(NOW(), INTERVAL 1 YEAR)'; }
elseif ($periodo === 'mes') { $cond[] = 'fecha_inicio >= DATE_SUB(NOW(), INTERVAL 1 MONTH)'; }
$where = implode(' AND ', $cond);

$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN estado_pago='confirmado' THEN precio_total ELSE 0 END),0) AS ingresos,
    COALESCE(SUM(CASE WHEN estado_pago='pendiente' THEN precio_total ELSE 0 END),0) AS pendiente,
    COALESCE(SUM(CASE WHEN estado_pago='fallido' THEN precio_total ELSE 0 END),0) AS fallido,
    COUNT(*) AS alquileres FROM alquileres WHERE $where");
$stmt->execute($params); $ing = $stmt->fetch();

$gastosCond = ['1=1']; $gastosParams = [];
if ($desde !== '' && $hasta !== '') { $gastosCond[] = 'fecha BETWEEN ? AND ?'; array_push($gastosParams, $desde, $hasta); }
elseif ($periodo === 'hoy') { $gastosCond[] = 'fecha=CURDATE()'; }
elseif ($periodo === 'semana') { $gastosCond[] = 'fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'; }
elseif ($periodo === 'mes') { $gastosCond[] = 'fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)'; }
elseif ($periodo === 'anio') { $gastosCond[] = 'fecha >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)'; }
$stmt = $pdo->prepare('SELECT COALESCE(SUM(monto),0) FROM gastos WHERE ' . implode(' AND ', $gastosCond));
$stmt->execute($gastosParams); $gastos = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT DATE_FORMAT(fecha_inicio,'%Y-%m') AS mes, SUM(CASE WHEN estado_pago='confirmado' THEN precio_total ELSE 0 END) AS total
                       FROM alquileres WHERE fecha_inicio >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                       GROUP BY DATE_FORMAT(fecha_inicio,'%Y-%m') ORDER BY mes");
$chart = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT a.codigo_alquiler, a.precio_total, a.estado_pago, a.metodo_pago, a.fecha_inicio, b.codigo AS bici, u.nombre AS cliente
                       FROM alquileres a JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
                       JOIN usuarios u ON u.id=a.id_cliente WHERE $where ORDER BY a.fecha_inicio DESC LIMIT 30");
$stmt->execute($params); $detalle = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT g.*, u.nombre AS usuario FROM gastos g LEFT JOIN usuarios u ON u.id=g.id_usuario WHERE ' . implode(' AND ', $gastosCond) . ' ORDER BY g.fecha DESC LIMIT 30');
$stmt->execute($gastosParams); $gastosList = $stmt->fetchAll();
$pageTitle = 'Finanzas del alquiler';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro"><span class="eyebrow">RESULTADOS DE LA OPERACIÓN</span><h1>Finanzas</h1><p>Ingresos por alquileres confirmados y gastos reales de la operación. No se registran cobros por reparaciones a clientes.</p></section>
<div class="toolbar">
    <form class="filters" method="get" action="contabilidad.php">
        <select class="input" name="periodo">
            <?php foreach (['hoy'=>'Hoy','semana'=>'Últimos 7 días','mes'=>'Este mes','anio'=>'Este año','todos'=>'Histórico'] as $k=>$v): ?>
                <option value="<?= e($k) ?>" <?= $periodo === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" type="date" name="desde" value="<?= e($desde) ?>">
        <input class="input" type="date" name="hasta" value="<?= e($hasta) ?>">
        <button class="btn btn-soft" type="submit">Filtrar</button>
        <a class="btn btn-ghost" href="api/exportar_contabilidad.php?desde=<?= e($desde) ?>&hasta=<?= e($hasta) ?>">Exportar CSV</a>
    </form>
    <button class="btn btn-primary" type="button" onclick="openModal('modalGasto')"><span class="material-symbols-outlined">add</span>Registrar gasto</button>
</div>

<div class="fin-grid">
    <div class="fin-card"><span class="fin-label">Ingresos confirmados</span><strong class="fin-val green"><?= e(money($ing['ingresos'])) ?></strong></div>
    <div class="fin-card"><span class="fin-label">Pendiente de confirmación</span><strong class="fin-val"><?= e(money($ing['pendiente'])) ?></strong></div>
    <div class="fin-card"><span class="fin-label">Gastos operativos</span><strong class="fin-val danger"><?= e(money($gastos)) ?></strong></div>
    <div class="fin-card"><span class="fin-label">Resultado</span><strong class="fin-val primary"><?= e(money((float)$ing['ingresos'] - $gastos)) ?></strong></div>
</div>
<p class="fine-print">Alquileres en el período: <?= (int)$ing['alquileres'] ?> · Pagos fallidos: <?= e(money($ing['fallido'])) ?>. Los pagos son registros internos, no transacciones de una pasarela.</p>

<div class="panel"><div class="panel-head"><h2>Evolución de ingresos por alquiler</h2></div><div class="panel-body"><canvas id="chartIngresos" height="110"></canvas></div></div>

<div class="grid-main">
    <section class="panel"><div class="panel-head"><h2>Alquileres del período</h2></div><div class="table-wrap"><table class="table">
        <thead><tr><th>Alquiler</th><th>Cliente</th><th>Bici</th><th>Fecha</th><th>Total</th><th>Pago</th></tr></thead><tbody>
        <?php foreach ($detalle as $d): ?>
            <tr><td><a href="ticket.php?codigo=<?= e($d['codigo_alquiler']) ?>"><?= e($d['codigo_alquiler']) ?></a></td><td><?= e($d['cliente']) ?></td><td><?= e($d['bici']) ?></td>
                <td><?= e(format_date($d['fecha_inicio'])) ?></td><td><?= e(money($d['precio_total'])) ?></td>
                <td><span class="badge badge-<?= status_class($d['estado_pago']) ?>"><?= e($d['estado_pago']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$detalle): ?><tr><td colspan="6" class="center">Sin alquileres en el período.</td></tr><?php endif; ?>
        </tbody></table></div></section>
    <section class="panel"><div class="panel-head"><h2>Gastos</h2></div><div class="table-wrap"><table class="table">
        <thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Monto</th><th></th></tr></thead><tbody>
        <?php foreach ($gastosList as $g): ?>
            <tr><td><?= e(format_date($g['fecha'])) ?></td><td><?= e($g['categoria']) ?></td><td><?= e($g['descripcion']) ?></td><td><?= e(money($g['monto'])) ?></td>
                <td><form method="post" action="api/gasto.php" class="inline-form"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_gasto" value="<?= (int)$g['id_gasto'] ?>"><button class="icon-btn" type="submit" title="Eliminar gasto"><span class="material-symbols-outlined">delete</span></button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$gastosList): ?><tr><td colspan="5" class="center">Sin gastos en el período.</td></tr><?php endif; ?>
        </tbody></table></div></section>
</div>

<div class="modal-backdrop" id="modalGasto"><div class="modal">
    <div class="modal-head"><h2>Registrar gasto operativo</h2><button class="icon-btn" onclick="closeModal('modalGasto')" type="button">✕</button></div>
    <form method="post" action="api/gasto.php" class="modal-body stack-form"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
        <label>Categoría<select class="input" name="categoria"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>Descripción<input class="input" name="descripcion" required maxlength="255"></label>
        <label>Monto (COP)<input class="input" type="number" name="monto" min="1" step="100" required></label>
        <label>Fecha<input class="input" type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>"></label>
        <label>Método de pago<input class="input" name="metodo_pago" value="Efectivo"></label>
        <label>Comprobante<input class="input" name="comprobante" maxlength="100"></label>
        <div class="modal-foot"><button class="btn btn-primary" type="submit">Guardar gasto</button></div>
    </form>
</div></div>
<script>
new Chart(document.getElementById('chartIngresos'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($chart, 'mes')) ?>,
        datasets: [{ label: 'Ingresos por alquiler', data: <?= json_encode(array_map('floatval', array_column($chart, 'total'))) ?>,
            backgroundColor: '#16a05d', borderRadius: 8 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
