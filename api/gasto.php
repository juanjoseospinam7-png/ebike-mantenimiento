<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../contabilidad.php');
}

$pdo = db();

try {
    verify_csrf();
    $accion = post_string('accion', 'crear');
    $currentUserId = (int)($_SESSION['ebike_user']['id'] ?? 0);

    if ($accion === 'crear') {
        $categoria = post_string('categoria');
        $descripcion = post_string('descripcion');
        $monto = post_float('monto');
        $fecha = post_string('fecha', date('Y-m-d'));
        $metodo = post_string('metodo_pago', 'Efectivo');
        $comprobante = post_string('comprobante');

        if ($descripcion === '' || $monto <= 0) {
            throw new RuntimeException('Ingresa una descripción válida y un monto mayor a cero.');
        }

        if (!in_array($categoria, EXPENSE_CATEGORIES, true)) {
            throw new RuntimeException('Categoría de gasto no válida.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO gastos (categoria, descripcion, monto, fecha, metodo_pago, comprobante, id_usuario, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$categoria, $descripcion, $monto, $fecha, $metodo, $comprobante ?: null, $currentUserId]);

        audit($pdo, null, null, null, null, 'Registro de Gasto', "Gasto en $categoria por " . money($monto) . " ($descripcion)");

        flash('success', 'Gasto de ' . money($monto) . ' registrado exitosamente.');
    } elseif ($accion === 'eliminar') {
        $idGasto = post_int('id_gasto');
        if (!$idGasto) throw new RuntimeException('ID de gasto inválido.');

        $pdo->prepare("DELETE FROM gastos WHERE id_gasto = ?")->execute([$idGasto]);
        audit($pdo, null, null, null, null, 'Eliminación de Gasto', "Gasto #$idGasto eliminado");

        flash('success', 'Registro de gasto eliminado correctamente.');
    } elseif ($accion === 'marcar_pago') {
        $idManten = post_int('id_manten');
        $metodo = post_string('metodo_pago', 'Efectivo');
        if (!$idManten) throw new RuntimeException('ID de mantenimiento inválido.');

        $pdo->prepare("UPDATE mantenimientos SET estado_pago = 'pagado', metodo_pago = ?, fecha_pago = NOW() WHERE id_manten = ?")
            ->execute([$metodo, $idManten]);

        audit($pdo, $idManten, null, null, null, 'Registro de Pago', "Mantenimiento #$idManten marcado como PAGADO mediante $metodo");

        flash('success', "Servicio #$idManten marcado como PAGADO exitosamente.");
    }
} catch (Throwable $e) {
    flash('error', 'Error en contabilidad: ' . $e->getMessage());
}

redirect('../contabilidad.php');
