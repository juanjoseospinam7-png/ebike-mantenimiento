<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_mechanic_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../mantenimientos.php');
}

$pdo = db();
$accion = post_string('accion');

function get_part_items(): array
{
    $ids = $_POST['repuestos'] ?? [];
    $qtys = $_POST['cantidades'] ?? [];
    if (!is_array($ids)) $ids = [];
    if (!is_array($qtys)) $qtys = [];
    $items = [];
    foreach ($ids as $i => $idRaw) {
        $id = (int)$idRaw;
        $qty = max(1, (int)($qtys[$i] ?? 1));
        if ($id > 0) {
            $items[$id] = ($items[$id] ?? 0) + $qty;
        }
    }
    return $items;
}

function fetch_part_prices(PDO $pdo, array $items): array
{
    if (!$items) return [];
    $placeholders = implode(',', array_fill(0, count($items), '?'));
    $stmt = $pdo->prepare("SELECT id_repuesto, nombre_rep, codigo_rep, precio_actual, costo_repuestos, cantidad_disponible FROM inventario_costos_arreglos WHERE id_repuesto IN ($placeholders) FOR UPDATE");
    $stmt->execute(array_keys($items));
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['id_repuesto']] = $row;
    }
    return $out;
}

function calculate_parts_total(array $items, array $parts): float
{
    $total = 0.0;
    foreach ($items as $id => $qty) {
        if (!isset($parts[$id])) {
            throw new RuntimeException('Uno de los repuestos seleccionados no existe en el catálogo.');
        }
        if ((int)$parts[$id]['cantidad_disponible'] < $qty) {
            throw new RuntimeException('Stock insuficiente para "' . $parts[$id]['nombre_rep'] . '". Disponibles: ' . $parts[$id]['cantidad_disponible'] . ', solicitados: ' . $qty . '.');
        }
        $total += (float)$parts[$id]['precio_actual'] * $qty;
    }
    return $total;
}

try {
    verify_csrf();
    $currentUserId = (int)($_SESSION['ebike_user']['id'] ?? 0);

    if ($accion === 'crear') {
        $idBike = post_int('id_bicicleta');
        $idTec = post_int('id_tecnico');
        if (is_mechanic()) {
            $idTec = (int)(current_user()['id_empleado'] ?? 0);
            if ($idTec <= 0) throw new RuntimeException('Tu cuenta de mecánico no está vinculada a un registro de empleado.');
        }

        $diagnostico = post_string('diagnostico');
        $tipo = post_string('tipo_manten');
        $estado = post_string('estado');
        $prioridad = post_string('prioridad', 'media');
        $trabajos = post_string('trabajos_realizados');
        $observaciones = post_string('observaciones');
        $estadoPago = post_string('estado_pago', 'pendiente');
        $metodoPago = post_string('metodo_pago', 'Efectivo');
        $reportId = post_int('id_reporte_cliente');
        $labor = max(0, post_float('mano_obra'));
        $descuento = max(0, post_float('descuento'));
        $items = get_part_items();

        if (!$idBike || !$idTec || $diagnostico === '' || $tipo === '' || $estado === '') {
            throw new RuntimeException('Por favor completa todos los campos obligatorios (*).');
        }

        $pdo->beginTransaction();

        $parts = fetch_part_prices($pdo, $items);
        $partsTotal = calculate_parts_total($items, $parts);
        $costoFinal = max(0, $partsTotal + $labor - $descuento);
        $firstPart = $items ? (int)array_key_first($items) : null;

        $isSalida = in_array(mb_strtolower(trim($estado)), ['reparado', 'entregado', 'completado', 'finalizado', 'listo'], true);
        $fechaPago = ($estadoPago === 'pagado') ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare("
            INSERT INTO mantenimientos 
            (id_bicicleta, id_tecnico, diagnostico, trabajos_realizados, observaciones, tipo_manten, estado, prioridad, id_repuesto, costo, mano_obra, total_repuestos, descuento, estado_pago, metodo_pago, fecha_pago, fecha_salida, id_reporte_cliente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1, NOW(), NULL), ?)
        ");
        $stmt->execute([
            $idBike, $idTec, $diagnostico, $trabajos ?: null, $observaciones ?: null, $tipo, $estado, $prioridad,
            $firstPart, $costoFinal, $labor, $partsTotal, $descuento, $estadoPago, $metodoPago, $fechaPago,
            $isSalida ? 1 : 0, $reportId ?: null
        ]);
        $idManten = (int)$pdo->lastInsertId();

        // Descontar inventario y registrar movimientos en kardex
        foreach ($items as $partId => $qty) {
            $pdo->prepare("INSERT INTO mantenimiento_repuestos (id_manten, id_repuesto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)")
                ->execute([$idManten, $partId, $qty, (float)$parts[$partId]['precio_actual']]);

            $pdo->prepare("UPDATE inventario_costos_arreglos SET cantidad_disponible = cantidad_disponible - ? WHERE id_repuesto = ?")
                ->execute([$qty, $partId]);

            $pdo->prepare("INSERT INTO movimientos_inventario (id_repuesto, tipo, cantidad, costo_unitario, motivo, id_manten, id_usuario, fecha) VALUES (?, 'salida', ?, ?, ?, ?, ?, NOW())")
                ->execute([$partId, $qty, (float)$parts[$partId]['costo_repuestos'], "Salida por Mantenimiento #$idManten", $idManten, $currentUserId]);
        }

        // Auditoría
        audit($pdo, $idManten, null, $estado, $idTec, 'Creación de mantenimiento', "Costo inicial: $costoFinal COP");

        // Sincronizar reporte de cliente si existe
        if ($reportId) {
            $pdo->prepare("UPDATE reportes_clientes SET estado = ?, id_mantenimiento = ?, id_tecnico = ?, atendido_en = IF(?=1, NOW(), atendido_en) WHERE id_reporte = ?")
                ->execute([$estado, $idManten, $idTec, $isSalida ? 1 : 0, $reportId]);

            // Notificar al cliente si tiene usuario registrado
            $cStmt = $pdo->prepare("SELECT id_cliente, codigo_seguimiento FROM reportes_clientes WHERE id_reporte = ?");
            $cStmt->execute([$reportId]);
            $cRow = $cStmt->fetch();
            if ($cRow && !empty($cRow['id_cliente'])) {
                notify($pdo, "Actualización de tu eBike ({$cRow['codigo_seguimiento']})", "Tu reporte cambió al estado: $estado.", 'cliente', (int)$cRow['id_cliente'], 'seguimiento.php?codigo=' . urlencode($cRow['codigo_seguimiento']));
            }
        }

        $pdo->commit();
        flash('success', "Mantenimiento #$idManten creado correctamente. Total liquidado: " . money($costoFinal));
    } elseif ($accion === 'editar') {
        $id = post_int('id_manten');
        if (!$id) throw new RuntimeException('ID de mantenimiento inválido.');

        $idBike = post_int('id_bicicleta');
        $idTec = post_int('id_tecnico');
        if (is_mechanic()) {
            $idTec = (int)(current_user()['id_empleado'] ?? 0);
            if ($idTec <= 0) throw new RuntimeException('Tu cuenta de mecánico no está vinculada a un registro de empleado.');
        }

        $diagnostico = post_string('diagnostico');
        $tipo = post_string('tipo_manten');
        $estado = post_string('estado');
        $prioridad = post_string('prioridad', 'media');
        $trabajos = post_string('trabajos_realizados');
        $observaciones = post_string('observaciones');
        $estadoPago = post_string('estado_pago', 'pendiente');
        $metodoPago = post_string('metodo_pago', 'Efectivo');
        $reportId = post_int('id_reporte_cliente');
        $labor = max(0, post_float('mano_obra'));
        $descuento = max(0, post_float('descuento'));
        $items = get_part_items();

        if (!$idBike || !$idTec || $diagnostico === '' || $tipo === '' || $estado === '') {
            throw new RuntimeException('Por favor completa todos los campos obligatorios (*).');
        }

        $pdo->beginTransaction();

        $oldStmt = $pdo->prepare("SELECT estado, id_tecnico, id_reporte_cliente, estado_pago, fecha_salida FROM mantenimientos WHERE id_manten = ? FOR UPDATE");
        $oldStmt->execute([$id]);
        $old = $oldStmt->fetch();
        if (!$old) throw new RuntimeException('La orden de mantenimiento solicitada no existe.');

        if (is_mechanic() && (int)$old['id_tecnico'] !== (int)(current_user()['id_empleado'] ?? 0)) {
            throw new RuntimeException('Acceso denegado: No puedes modificar una orden asignada a otro técnico.');
        }

        if (!$reportId && !empty($old['id_reporte_cliente'])) {
            $reportId = (int)$old['id_reporte_cliente'];
        }

        // Restituir temporalmente los repuestos antiguos para recalcular el stock con precisión
        $oldPartsStmt = $pdo->prepare("SELECT id_repuesto, cantidad FROM mantenimiento_repuestos WHERE id_manten = ? FOR UPDATE");
        $oldPartsStmt->execute([$id]);
        $oldParts = $oldPartsStmt->fetchAll();
        foreach ($oldParts as $op) {
            $pdo->prepare("UPDATE inventario_costos_arreglos SET cantidad_disponible = cantidad_disponible + ? WHERE id_repuesto = ?")
                ->execute([(int)$op['cantidad'], (int)$op['id_repuesto']]);
        }

        // Validar nuevo stock
        $parts = fetch_part_prices($pdo, $items);
        $partsTotal = calculate_parts_total($items, $parts);
        $costoFinal = max(0, $partsTotal + $labor - $descuento);
        $firstPart = $items ? (int)array_key_first($items) : null;

        $isSalida = in_array(mb_strtolower(trim($estado)), ['reparado', 'entregado', 'completado', 'finalizado', 'listo'], true);
        $fechaPago = ($estadoPago === 'pagado') ? ($old['fecha_pago'] ?? date('Y-m-d H:i:s')) : null;

        $stmt = $pdo->prepare("
            UPDATE mantenimientos 
            SET id_bicicleta = ?, id_tecnico = ?, diagnostico = ?, trabajos_realizados = ?, observaciones = ?, 
                tipo_manten = ?, estado = ?, prioridad = ?, id_repuesto = ?, costo = ?, mano_obra = ?, 
                total_repuestos = ?, descuento = ?, estado_pago = ?, metodo_pago = ?, fecha_pago = ?, 
                fecha_salida = IF(?=1, COALESCE(fecha_salida, NOW()), NULL), id_reporte_cliente = ?
            WHERE id_manten = ?
        ");
        $stmt->execute([
            $idBike, $idTec, $diagnostico, $trabajos ?: null, $observaciones ?: null,
            $tipo, $estado, $prioridad, $firstPart, $costoFinal, $labor,
            $partsTotal, $descuento, $estadoPago, $metodoPago, $fechaPago,
            $isSalida ? 1 : 0, $reportId ?: null, $id
        ]);

        // Reemplazar repuestos y aplicar salidas en inventario y kardex
        $pdo->prepare("DELETE FROM mantenimiento_repuestos WHERE id_manten = ?")->execute([$id]);
        foreach ($items as $partId => $qty) {
            $pdo->prepare("INSERT INTO mantenimiento_repuestos (id_manten, id_repuesto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)")
                ->execute([$id, $partId, $qty, (float)$parts[$partId]['precio_actual']]);

            $pdo->prepare("UPDATE inventario_costos_arreglos SET cantidad_disponible = cantidad_disponible - ? WHERE id_repuesto = ?")
                ->execute([$qty, $partId]);

            $pdo->prepare("INSERT INTO movimientos_inventario (id_repuesto, tipo, cantidad, costo_unitario, motivo, id_manten, id_usuario, fecha) VALUES (?, 'salida', ?, ?, ?, ?, ?, NOW())")
                ->execute([$partId, $qty, (float)$parts[$partId]['costo_repuestos'], "Ajuste/Uso en Mantenimiento #$id", $id, $currentUserId]);
        }

        // Auditoría si hubo cambio de estado
        if (($old['estado'] ?? '') !== $estado) {
            audit($pdo, $id, $old['estado'] ?? null, $estado, $idTec, 'Actualización de orden', "Costo: $costoFinal COP, Pago: $estadoPago");
        }

        // Sincronizar reporte de cliente si existe
        if ($reportId) {
            $pdo->prepare("UPDATE reportes_clientes SET estado = ?, id_mantenimiento = ?, id_tecnico = ?, atendido_en = IF(?=1, COALESCE(atendido_en, NOW()), atendido_en) WHERE id_reporte = ?")
                ->execute([$estado, $id, $idTec, $isSalida ? 1 : 0, $reportId]);

            // Notificar al cliente si tiene usuario registrado
            $cStmt = $pdo->prepare("SELECT id_cliente, codigo_seguimiento FROM reportes_clientes WHERE id_reporte = ?");
            $cStmt->execute([$reportId]);
            $cRow = $cStmt->fetch();
            if ($cRow && !empty($cRow['id_cliente'])) {
                notify($pdo, "Actualización de tu eBike ({$cRow['codigo_seguimiento']})", "Tu orden de servicio ha avanzado a: $estado.", 'cliente', (int)$cRow['id_cliente'], 'seguimiento.php?codigo=' . urlencode($cRow['codigo_seguimiento']));
            }
        }

        $pdo->commit();
        flash('success', "Mantenimiento #$id actualizado correctamente. Total liquidado: " . money($costoFinal));
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Error al guardar mantenimiento: ' . $ex->getMessage());
}

redirect('../mantenimientos.php');
