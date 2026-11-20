<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../inventario.php');
}

$pdo = db();
$currentUserId = (int)($_SESSION['ebike_user']['id'] ?? 0);

try {
    verify_csrf();
    $accion = post_string('accion');

    if ($accion === 'crear') {
        $nombre = post_string('nombre_rep');
        $codigo = post_string('codigo_rep');
        $desc = post_string('descripcion');
        $categoria = post_string('categoria', 'Repuestos Generales');
        $stock = max(0, post_int('cantidad_disponible', 0) ?? 0);
        $stockMin = max(1, post_int('stock_minimo', 5) ?? 5);
        $precio = max(0, post_float('precio_actual'));
        $costoRep = max(0, post_float('costo_repuestos'));
        $mano = max(0, post_float('costo_mano_obra'));
        $prov = post_string('proveedor');

        if ($nombre === '') throw new RuntimeException('El nombre del repuesto es obligatorio.');

        // Autogenerar código si viene vacío
        if ($codigo === '') {
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id_repuesto),0)+1 FROM inventario_costos_arreglos")->fetchColumn();
            $codigo = 'REP-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);
        } else {
            $chk = $pdo->prepare("SELECT id_repuesto FROM inventario_costos_arreglos WHERE codigo_rep = ?");
            $chk->execute([$codigo]);
            if ($chk->fetch()) throw new RuntimeException("El código '$codigo' ya está en uso.");
        }

        $pdo->beginTransaction();
        $s = $pdo->prepare("
            INSERT INTO inventario_costos_arreglos 
            (codigo_rep, nombre_rep, descripcion, categoria, cantidad_disponible, stock_minimo, precio_actual, costo_repuestos, proveedor, costo_mano_obra, activo, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $s->execute([$codigo, $nombre, $desc ?: null, $categoria, $stock, $stockMin, $precio, $costoRep, $prov ?: null, $mano]);
        $newId = (int)$pdo->lastInsertId();

        if ($stock > 0) {
            $pdo->prepare("INSERT INTO movimientos_inventario (id_repuesto, tipo, cantidad, costo_unitario, motivo, id_usuario, fecha) VALUES (?, 'entrada', ?, ?, 'Inventario inicial', ?, NOW())")
                ->execute([$newId, $stock, $costoRep, $currentUserId]);
        }

        audit($pdo, null, null, null, null, 'Alta de Repuesto', "Repuesto $codigo: $nombre creado con stock $stock");
        $pdo->commit();

        flash('success', "Repuesto '$nombre' registrado exitosamente.");
    } elseif ($accion === 'editar') {
        $id = post_int('id_repuesto');
        if (!$id) throw new RuntimeException('ID de repuesto inválido.');

        $nombre = post_string('nombre_rep');
        $codigo = post_string('codigo_rep');
        $desc = post_string('descripcion');
        $categoria = post_string('categoria', 'Repuestos Generales');
        $stock = max(0, post_int('cantidad_disponible', 0) ?? 0);
        $stockMin = max(1, post_int('stock_minimo', 5) ?? 5);
        $precio = max(0, post_float('precio_actual'));
        $costoRep = max(0, post_float('costo_repuestos'));
        $mano = max(0, post_float('costo_mano_obra'));
        $prov = post_string('proveedor');
        $activo = post_int('activo', 1);

        if ($nombre === '') throw new RuntimeException('El nombre del repuesto es obligatorio.');

        $chk = $pdo->prepare("SELECT id_repuesto FROM inventario_costos_arreglos WHERE codigo_rep = ? AND id_repuesto <> ?");
        $chk->execute([$codigo, $id]);
        if ($chk->fetch()) throw new RuntimeException("El código '$codigo' ya está en uso por otro repuesto.");

        $s = $pdo->prepare("
            UPDATE inventario_costos_arreglos 
            SET codigo_rep = ?, nombre_rep = ?, descripcion = ?, categoria = ?, cantidad_disponible = ?, 
                stock_minimo = ?, precio_actual = ?, costo_repuestos = ?, proveedor = ?, costo_mano_obra = ?, activo = ?
            WHERE id_repuesto = ?
        ");
        $s->execute([$codigo, $nombre, $desc ?: null, $categoria, $stock, $stockMin, $precio, $costoRep, $prov ?: null, $mano, $activo, $id]);

        audit($pdo, null, null, null, null, 'Edición de Repuesto', "Repuesto #$id ($nombre) actualizado");
        flash('success', "Repuesto '$nombre' actualizado correctamente.");
    } elseif ($accion === 'entrada_stock') {
        $id = post_int('id_repuesto');
        $cantidad = post_int('cantidad');
        $costoCompra = post_float('costo_compra');
        $motivo = post_string('motivo', 'Compra a proveedor');
        $prov = post_string('proveedor');

        if (!$id || !$cantidad || $cantidad <= 0) {
            throw new RuntimeException('Indica una cantidad válida mayor a cero.');
        }

        $pdo->beginTransaction();

        $pdo->prepare("UPDATE inventario_costos_arreglos SET cantidad_disponible = cantidad_disponible + ?, costo_repuestos = IF(? > 0, ?, costo_repuestos), proveedor = IF(? <> '', ?, proveedor) WHERE id_repuesto = ?")
            ->execute([$cantidad, $costoCompra, $costoCompra, $prov, $prov, $id]);

        $pdo->prepare("INSERT INTO movimientos_inventario (id_repuesto, tipo, cantidad, costo_unitario, motivo, id_usuario, fecha) VALUES (?, 'entrada', ?, ?, ?, ?, NOW())")
            ->execute([$id, $cantidad, $costoCompra, $motivo, $currentUserId]);

        // Registrar gasto automáticamente si se indicó un costo mayor a cero
        if ($costoCompra > 0) {
            $totalCompra = $costoCompra * $cantidad;
            $descGasto = "Compra de inventario: $cantidad unidades (Repuesto #$id) - $prov";
            $pdo->prepare("INSERT INTO gastos (categoria, descripcion, monto, fecha, metodo_pago, comprobante, id_usuario, creado_en) VALUES ('Compra de repuestos', ?, ?, CURDATE(), 'Transferencia Bancaria', 'Entrada Inv #$id', ?, NOW())")
                ->execute([$descGasto, $totalCompra, $currentUserId]);
        }

        audit($pdo, null, null, null, null, 'Entrada de Stock', "+$cantidad unidades en repuesto #$id ($motivo)");
        $pdo->commit();

        flash('success', "Se agregaron $cantidad unidades al inventario.");
    } elseif ($accion === 'toggle_activo') {
        $id = post_int('id_repuesto');
        if (!$id) throw new RuntimeException('ID inválido.');

        $pdo->prepare("UPDATE inventario_costos_arreglos SET activo = IF(activo=1, 0, 1) WHERE id_repuesto = ?")->execute([$id]);
        flash('success', 'Estado del repuesto modificado.');
    } else {
        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Error en inventario: ' . $e->getMessage());
}

redirect('../inventario.php');
