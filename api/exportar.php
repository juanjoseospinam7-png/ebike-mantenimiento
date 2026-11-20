<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

$pdo = db();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mantenimientos_ebike_' . date('Y-m-d_H-i') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para caracteres especiales y tildes en Microsoft Excel

fputcsv($out, [
    'ID Mantenimiento',
    'Código Seguimiento',
    'Bicicleta Código',
    'Marca & Modelo',
    'Tipo eBike',
    'Cliente Propietario',
    'Teléfono Cliente',
    'Técnico Responsable',
    'Fecha Ingreso',
    'Fecha Salida',
    'Diagnóstico Técnico',
    'Trabajos Realizados',
    'Tipo Servicio',
    'Prioridad',
    'Estado Actual',
    'Mano de Obra (COP)',
    'Total Repuestos (COP)',
    'Descuento (COP)',
    'Total Liquidado (COP)',
    'Estado Pago',
    'Método Pago'
], ';');

$q = $pdo->query("
    SELECT m.*, 
           b.codigo, b.modelo, b.marca, b.tipo_bicicleta, b.cliente AS b_cliente, b.telefono_cliente AS b_tel,
           e.nombre AS tecnico,
           r.codigo_seguimiento, r.nombre_cliente, r.telefono_cliente
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    LEFT JOIN reportes_clientes r ON r.id_reporte = m.id_reporte_cliente
    ORDER BY m.id_manten DESC
");

while ($r = $q->fetch()) {
    fputcsv($out, [
        $r['id_manten'],
        $r['codigo_seguimiento'] ?: '—',
        $r['codigo'] ?: '—',
        ($r['marca'] ?? 'eBike') . ' ' . ($r['modelo'] ?? ''),
        $r['tipo_bicicleta'] ?: 'Urbana',
        $r['b_cliente'] ?: ($r['nombre_cliente'] ?: 'Taller'),
        $r['b_tel'] ?: ($r['telefono_cliente'] ?: '—'),
        $r['tecnico'] ?: 'ID ' . $r['id_tecnico'],
        $r['fecha_ingreso'],
        $r['fecha_salida'] ?: 'En taller',
        $r['diagnostico'],
        $r['trabajos_realizados'] ?: '—',
        $r['tipo_manten'],
        ucfirst($r['prioridad'] ?: 'media'),
        $r['estado'],
        $r['mano_obra'],
        $r['total_repuestos'],
        $r['descuento'],
        $r['costo'],
        $r['estado_pago'],
        $r['metodo_pago'] ?: 'Efectivo'
    ], ';');
}

fclose($out);
exit;
