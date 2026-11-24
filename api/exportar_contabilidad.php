<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

$pdo = db();
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_operativo_' . date('Y-m-d_H-i') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['REPORTE OPERATIVO Y CONTABLE · eBIKE MOVILIDAD ELÉCTRICA'], ';');
fputcsv($out, ['Generado el', date('d/m/Y H:i:s'), 'Moneda', 'Pesos Colombianos (COP)'], ';');
fputcsv($out, [], ';');

$where = ['1=1']; $params = [];
if ($desde !== '') { $where[] = 'DATE(a.fecha_inicio) >= ?'; $params[] = $desde; }
if ($hasta !== '') { $where[] = 'DATE(a.fecha_inicio) <= ?'; $params[] = $hasta; }
$stmt = $pdo->prepare(
    "SELECT a.*, b.codigo AS bici_codigo, b.modelo, u.nombre AS cliente,
            t.codigo_ticket,
            (SELECT COUNT(*) FROM reportes_clientes r WHERE r.id_alquiler=a.id_alquiler) AS incidencias
     FROM alquileres a
     JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
     JOIN usuarios u ON u.id=a.id_cliente
     LEFT JOIN tickets_alquiler t ON t.id_alquiler=a.id_alquiler
     WHERE " . implode(' AND ', $where) . " ORDER BY a.fecha_inicio DESC"
);
$stmt->execute($params);

fputcsv($out, ['--- INGRESOS POR ALQUILER ---'], ';');
fputcsv($out, ['Código alquiler', 'Ticket', 'Cliente', 'Bicicleta', 'Modelo', 'Inicio', 'Fin estimado', 'Horas', 'Total (COP)', 'Estado', 'Pago', 'Método', 'Incidencias'], ';');
$totalIngresos = 0.0;
while ($r = $stmt->fetch()) {
    if ($r['estado_pago'] === 'confirmado') $totalIngresos += (float)$r['precio_total'];
    fputcsv($out, [
        $r['codigo_alquiler'], $r['codigo_ticket'] ?: '—', $r['cliente'], $r['bici_codigo'], $r['modelo'],
        $r['fecha_inicio'], $r['fecha_fin_estimada'], (int)$r['duracion_horas'], $r['precio_total'],
        rental_label($r['estado']), $r['estado_pago'], $r['metodo_pago'] ?: '—', (int)$r['incidencias']
    ], ';');
}
fputcsv($out, ['TOTAL INGRESOS CONFIRMADOS', '', '', '', '', '', '', '', $totalIngresos], ';');
fputcsv($out, [], ';');

$gWhere = ['1=1']; $gParams = [];
if ($desde !== '') { $gWhere[] = 'fecha >= ?'; $gParams[] = $desde; }
if ($hasta !== '') { $gWhere[] = 'fecha <= ?'; $gParams[] = $hasta; }
$gStmt = $pdo->prepare('SELECT * FROM gastos WHERE ' . implode(' AND ', $gWhere) . ' ORDER BY fecha DESC');
$gStmt->execute($gParams);
fputcsv($out, ['--- GASTOS OPERATIVOS ---'], ';');
fputcsv($out, ['ID', 'Fecha', 'Categoría', 'Descripción', 'Método', 'Comprobante', 'Monto (COP)'], ';');
$totalGastos = 0.0;
while ($g = $gStmt->fetch()) {
    $totalGastos += (float)$g['monto'];
    fputcsv($out, [$g['id_gasto'], $g['fecha'], $g['categoria'], $g['descripcion'], $g['metodo_pago'], $g['comprobante'] ?: '—', $g['monto']], ';');
}
fputcsv($out, ['TOTAL GASTOS', '', '', '', '', '', $totalGastos], ';');
fputcsv($out, [], ';');
fputcsv($out, ['RESULTADO OPERATIVO', $totalIngresos - $totalGastos], ';');
fputcsv($out, ['NOTA', 'Los mantenimientos son internos; no se facturan reparaciones a los clientes.'], ';');
fclose($out);
exit;
