<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

$pdo = db();

$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_contable_' . date('Y-m-d_H-i') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para compatibilidad con Excel

// Encabezado
fputcsv($out, ['REPORTE CONTABLE Y FINANCIERO - E-BIKE MAINTENANCE SYSTEM (EMS)'], ';');
fputcsv($out, ['Generado el', date('d/m/Y H:i:s'), 'Moneda', 'Pesos Colombianos (COP)'], ';');
fputcsv($out, [], ';');

// 1. SERVICIOS E INGRESOS
fputcsv($out, ['--- INGRESOS POR SERVICIOS DE MANTENIMIENTO ---'], ';');
fputcsv($out, ['ID Servicio', 'Fecha Ingreso', 'Fecha Salida', 'Bicicleta', 'Cliente', 'Mecánico', 'Mano de Obra (COP)', 'Repuestos (COP)', 'Descuento (COP)', 'Total Facturado (COP)', 'Estado Pago', 'Método Pago'], ';');

$where = ["m.estado <> 'Cancelado'"];
$params = [];
if ($desde !== '') {
    $where[] = "DATE(m.fecha_ingreso) >= ?";
    $params[] = $desde;
}
if ($hasta !== '') {
    $where[] = "DATE(m.fecha_ingreso) <= ?";
    $params[] = $hasta;
}

$sql = "
    SELECT m.*, b.codigo, b.modelo, b.cliente AS b_cliente, e.nombre AS tecnico
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.id_manten DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$totalIngresos = 0;
$totalManoObra = 0;
$totalRepuestos = 0;

while ($r = $stmt->fetch()) {
    $totalIngresos += (float)$r['costo'];
    $totalManoObra += (float)$r['mano_obra'];
    $totalRepuestos += (float)$r['total_repuestos'];
    fputcsv($out, [
        $r['id_manten'],
        $r['fecha_ingreso'],
        $r['fecha_salida'] ?: '—',
        $r['codigo'] . ' ' . $r['modelo'],
        $r['b_cliente'] ?: 'Taller',
        $r['tecnico'] ?: 'ID '.$r['id_tecnico'],
        $r['mano_obra'],
        $r['total_repuestos'],
        $r['descuento'],
        $r['costo'],
        $r['estado_pago'],
        $r['metodo_pago'] ?: 'Efectivo'
    ], ';');
}

fputcsv($out, ['TOTALES', '', '', '', '', '', $totalManoObra, $totalRepuestos, '', $totalIngresos, '', ''], ';');
fputcsv($out, [], ';');

// 2. GASTOS
fputcsv($out, ['--- GASTOS OPERATIVOS Y COMPRAS ---'], ';');
fputcsv($out, ['ID Gasto', 'Fecha', 'Categoría', 'Descripción', 'Método Pago', 'Monto Gasto (COP)'], ';');

$gWhere = [];
$gParams = [];
if ($desde !== '') {
    $gWhere[] = "g.fecha >= ?";
    $gParams[] = $desde;
}
if ($hasta !== '') {
    $gWhere[] = "g.fecha <= ?";
    $gParams[] = $hasta;
}
$gSql = "SELECT g.* FROM gastos g";
if ($gWhere) $gSql .= " WHERE " . implode(' AND ', $gWhere);
$gSql .= " ORDER BY g.fecha DESC, g.id_gasto DESC";

$gStmt = $pdo->prepare($gSql);
$gStmt->execute($gParams);

$totalGastos = 0;
while ($g = $gStmt->fetch()) {
    $totalGastos += (float)$g['monto'];
    fputcsv($out, [
        $g['id_gasto'],
        $g['fecha'],
        $g['categoria'],
        $g['descripcion'],
        $g['metodo_pago'],
        $g['monto']
    ], ';');
}

fputcsv($out, ['TOTAL GASTOS', '', '', '', '', $totalGastos], ';');
fputcsv($out, [], ';');

// 3. BALANCE FINAL Y UTILIDAD NETA
$utilidadNeta = $totalIngresos - $totalGastos;
fputcsv($out, ['--- BALANCE Y UTILIDAD NETA ---'], ';');
fputcsv($out, ['Total Ingresos Facturados', $totalIngresos], ';');
fputcsv($out, ['Total Gastos Realizados', $totalGastos], ';');
fputcsv($out, ['UTILIDAD NETA (Ingresos - Gastos)', $utilidadNeta], ';');

fclose($out);
exit;
