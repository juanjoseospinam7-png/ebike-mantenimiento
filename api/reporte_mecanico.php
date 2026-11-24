<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_mechanic();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('mecanico.php');

$accion = post_string('accion', 'unirme');
$user = current_user();
$idEmpleado = (int)($user['id_empleado'] ?? 0);
if ($idEmpleado <= 0) { flash('error', 'Tu cuenta no está vinculada a un mecánico de la flota.'); redirect('mecanico.php'); }

try {
    verify_csrf();
    $pdo->beginTransaction();
    $idReporte = post_int('id_reporte', 0);
    $stmt = $pdo->prepare('SELECT r.* FROM reportes_clientes r WHERE r.id_reporte = ? FOR UPDATE');
    $stmt->execute([$idReporte]);
    $r = $stmt->fetch();
    if (!$r) throw new RuntimeException('El reporte no existe.');
    if (in_array($r['estado'], ['Cerrado', 'Cancelado'], true)) throw new RuntimeException('El caso ya está cerrado; no admite nuevas intervenciones.');

    if ($accion === 'unirme') {
        $pdo->prepare('INSERT IGNORE INTO participantes_reporte (id_reporte, id_empleado, rol_participacion) VALUES (?, ?, ?)')
            ->execute([$idReporte, $idEmpleado, 'Mecánico participante']);
        $nuevo = $r['estado'] === 'En espera' ? 'En investigación' : $r['estado'];
        if ($nuevo !== $r['estado']) {
            $pdo->prepare('UPDATE reportes_clientes SET estado = ?, atendido_en = COALESCE(atendido_en, NOW()) WHERE id_reporte = ?')
                ->execute([$nuevo, $idReporte]);
            state_history($pdo, 'reporte', $idReporte, $r['estado'], $nuevo, 'Unión de mecánico al caso');
        }
        if (empty($r['id_tecnico'])) {
            $pdo->prepare('UPDATE reportes_clientes SET id_tecnico = ? WHERE id_reporte = ?')->execute([$idEmpleado, $idReporte]);
        }
        audit($pdo, null, $r['estado'], $nuevo, $idEmpleado, 'Mecánico incorporado', $r['codigo_seguimiento'], 'reporte', $idReporte);
        notify($pdo, 'Nuevo mecánico en el caso', $r['codigo_seguimiento'] . ': se incorporó ' . (string)($user['nombre'] ?? 'un mecánico'), 'admin', null, 'reporte_detalle.php?id=' . $idReporte);
        $pdo->commit();
        flash('success', 'Te has incorporado al caso. Estado: ' . $nuevo . '.');
        redirect('reporte_detalle.php?id=' . $idReporte);
    }

    if ($accion !== 'intervenir') throw new RuntimeException('Acción no reconocida.');
    $diagnostico = post_string('diagnostico');
    $trabajos = post_string('trabajos_realizados');
    $observaciones = post_string('observaciones');
    $nuevoEstado = post_string('estado_reporte');
    $permitidos = ['En investigación', 'En mantenimiento', 'En pruebas', 'Terminado', 'Requiere nueva revisión'];
    if (mb_strlen($diagnostico) < 5 || mb_strlen($trabajos) < 5) throw new RuntimeException('Registra diagnóstico y trabajos realizados.');
    if (!in_array($nuevoEstado, $permitidos, true)) throw new RuntimeException('Estado de mantenimiento no válido.');

    $idManten = (int)($r['id_mantenimiento'] ?? 0);
    if ($idManten <= 0) {
        $pdo->prepare(
            'INSERT INTO mantenimientos (fecha_ingreso, id_tecnico, diagnostico, trabajos_realizados, observaciones,
                                         tipo_manten, estado, prioridad, id_bicicleta, id_reporte_cliente)
             VALUES (NOW(), ?, ?, ?, ?, \'Incidencia de flota\', ?, ?, ?, ?)'
        )->execute([
            $idEmpleado, $diagnostico, $trabajos, $observaciones !== '' ? $observaciones : null,
            $nuevoEstado, 'alta', (int)$r['id_bicicleta'], $idReporte
        ]);
        $idManten = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE reportes_clientes SET id_mantenimiento = ? WHERE id_reporte = ?')->execute([$idManten, $idReporte]);
    } else {
        $pdo->prepare('UPDATE mantenimientos SET estado = ?, diagnostico = ?, trabajos_realizados = ?, observaciones = ? WHERE id_manten = ?')
            ->execute([$nuevoEstado, $diagnostico, $trabajos, $observaciones !== '' ? $observaciones : null, $idManten]);
    }

    $idRepuesto = post_int('id_repuesto', 0);
    $cantidad = max(0, post_int('cantidad_repuesto', 0));
    if ($idRepuesto > 0 && $cantidad > 0) {
        $stmt = $pdo->prepare('SELECT id_repuesto, nombre_rep, codigo_rep, precio_actual, costo_repuestos, cantidad_disponible
                               FROM inventario_costos_arreglos WHERE id_repuesto = ? FOR UPDATE');
        $stmt->execute([$idRepuesto]);
        $rep = $stmt->fetch();
        if (!$rep) throw new RuntimeException('El componente seleccionado no existe.');
        if ((int)$rep['cantidad_disponible'] < $cantidad) throw new RuntimeException('Stock insuficiente de ' . $rep['nombre_rep'] . '.');

        $pdo->prepare(
            'INSERT INTO mantenimiento_repuestos (id_manten, id_repuesto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad), precio_unitario = VALUES(precio_unitario)'
        )->execute([$idManten, $idRepuesto, $cantidad, $rep['precio_actual']]);
        $pdo->prepare('UPDATE inventario_costos_arreglos SET cantidad_disponible = cantidad_disponible - ? WHERE id_repuesto = ?')
            ->execute([$cantidad, $idRepuesto]);
        $pdo->prepare(
            'INSERT INTO movimientos_inventario (id_repuesto, tipo, cantidad, costo_unitario, motivo, id_manten, id_usuario)
             VALUES (?, \'salida\', ?, ?, ?, ?, ?)'
        )->execute([$idRepuesto, $cantidad, $rep['costo_repuestos'], 'Intervención reporte ' . $r['codigo_seguimiento'], $idManten, (int)$user['id']]);
        $pdo->prepare('UPDATE mantenimientos SET total_repuestos = total_repuestos + ?, costo = costo + ? WHERE id_manten = ?')
            ->execute([(float)$rep['precio_actual'] * $cantidad, (float)$rep['costo_repuestos'] * $cantidad, $idManten]);
    } else {
        $idRepuesto = null;
        $cantidad = 0;
    }

    $pdo->prepare(
        'INSERT INTO intervenciones_mantenimiento
         (id_reporte, id_mecanico, id_manten, diagnostico, trabajos_realizados, observaciones, estado_reporte, id_repuesto, cantidad_repuesto)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$idReporte, $idEmpleado, $idManten, $diagnostico, $trabajos, $observaciones !== '' ? $observaciones : null, $nuevoEstado, $idRepuesto, $cantidad]);

    $pdo->prepare('INSERT IGNORE INTO participantes_reporte (id_reporte, id_empleado, rol_participacion) VALUES (?, ?, ?)')
        ->execute([$idReporte, $idEmpleado, 'Mecánico participante']);
    $pdo->prepare('UPDATE reportes_clientes SET estado = ?, id_tecnico = COALESCE(id_tecnico, ?), atendido_en = COALESCE(atendido_en, NOW()) WHERE id_reporte = ?')
        ->execute([$nuevoEstado, $idEmpleado, $idReporte]);

    state_history($pdo, 'reporte', $idReporte, $r['estado'], $nuevoEstado, 'Intervención registrada por ' . (string)($user['nombre'] ?? 'mecánico'));
    state_history($pdo, 'mantenimiento', $idManten, null, $nuevoEstado, 'Orden actualizada desde bandeja compartida');
    audit($pdo, $idManten, $r['estado'], $nuevoEstado, $idEmpleado, 'Intervención de mantenimiento', $r['codigo_seguimiento'], 'reporte', $idReporte);
    if ($nuevoEstado === 'Terminado') {
        $pdo->prepare('UPDATE mantenimientos SET cerrado_en = NOW(), fecha_salida = COALESCE(fecha_salida, NOW()) WHERE id_manten = ?')->execute([$idManten]);
        notify($pdo, 'Caso terminado, pendiente de validación', $r['codigo_seguimiento'] . ' fue marcado como terminado por ' . (string)($user['nombre'] ?? 'mecánico'), 'admin', null, 'reportes_admin.php');
    }
    notify($pdo, 'Avance del mantenimiento', 'Tu reporte ' . $r['codigo_seguimiento'] . ' está ' . $nuevoEstado, 'cliente', $r['id_cliente'] ? (int)$r['id_cliente'] : null, 'seguimiento.php?codigo=' . urlencode($r['codigo_seguimiento']));

    $pdo->commit();
    flash('success', 'Intervención registrada y compartida con el equipo.');
    redirect('reporte_detalle.php?id=' . $idReporte);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'No se pudo registrar la intervención: ' . $ex->getMessage());
    redirect_back('mecanico.php');
}
