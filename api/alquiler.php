<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('cliente.php');

$accion = post_string('accion', 'crear');
$clienteAcciones = ['crear', 'solicitar_finalizacion'];
$adminAcciones = ['confirmar', 'asignar', 'activar', 'finalizar', 'cancelar', 'registrar_pago'];
if (in_array($accion, $clienteAcciones, true)) require_client(); else require_admin();

try {
    verify_csrf();
    $pdo->beginTransaction();

    if ($accion === 'crear') {
        $idBici = post_int('id_bicicleta', 0);
        $inicioStr = post_string('fecha_inicio');
        $horas = post_int('duracion_horas', 0);
        $notas = post_string('notas');
        $inicio = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $inicioStr);
        $errores = [];
        if (!$inicio || $inicio->format('Y-m-d\\TH:i') !== $inicioStr) $errores[] = 'Selecciona una fecha y hora válidas.';
        if (!in_array($horas, [1, 2, 4, 8, 24, 48, 72], true)) $errores[] = 'La duración seleccionada no es válida.';
        if ($inicio && $inicio->getTimestamp() < time() + 300) $errores[] = 'El inicio debe ser al menos 5 minutos posterior a la hora actual.';
        if ($errores) throw new RuntimeException(implode(' ', $errores));

        $stmt = $pdo->prepare('SELECT * FROM bicicletas WHERE id_bicicleta = ? FOR UPDATE');
        $stmt->execute([$idBici]);
        $bike = $stmt->fetch();
        if (!$bike) throw new RuntimeException('La bicicleta no existe.');
        [$ok, $msg] = available_for_rental($pdo, (int)$idBici, $inicio->format('Y-m-d H:i:s'), $inicio->modify("+$horas hours")->format('Y-m-d H:i:s'));
        if (!$ok) throw new RuntimeException($msg);

        $user = current_user();
        $fin = $inicio->modify("+$horas hours");
        $total = rental_price($bike, $horas);
        $codigo = unique_code($pdo, 'alquileres', 'codigo_alquiler', 'ALQ');

        $stmt = $pdo->prepare(
            'INSERT INTO alquileres (codigo_alquiler, id_cliente, id_bicicleta, fecha_inicio, fecha_fin_estimada, duracion_horas, precio_total, estado, estado_pago, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'solicitud_recibida\', \'pendiente\', ?)'
        );
        $stmt->execute([
            $codigo, (int)$user['id'], (int)$idBici, $inicio->format('Y-m-d H:i:s'),
            $fin->format('Y-m-d H:i:s'), $horas, $total, $notas !== '' ? $notas : null
        ]);
        $idAlquiler = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO pagos (id_alquiler, monto, metodo, estado, registrado_por) VALUES (?, ?, \'Pendiente\', \'pendiente\', ?)'
        )->execute([$idAlquiler, $total, (int)$user['id']]);

        $ticketCode = unique_code($pdo, 'tickets_alquiler', 'codigo_ticket', 'TKT');
        $pdo->prepare(
            'INSERT INTO tickets_alquiler (codigo_ticket, id_alquiler, id_cliente, id_bicicleta, cliente_snapshot, bicicleta_snapshot, codigo_bici_snapshot, precio_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticketCode, $idAlquiler, (int)$user['id'], (int)$idBici,
            (string)$user['nombre'], trim($bike['marca'] . ' ' . $bike['modelo']), $bike['codigo'], $total
        ]);

        $pdo->prepare('INSERT INTO alquiler_historial (id_alquiler, estado, descripcion, id_usuario) VALUES (?, ?, ?, ?)')
            ->execute([$idAlquiler, 'solicitud_recibida', 'Solicitud registrada por el cliente. Pendiente de confirmación.', (int)$user['id']]);
        state_history($pdo, 'alquiler', $idAlquiler, null, 'solicitud_recibida', 'Alquiler solicitado');
        refresh_bike_state($pdo, (int)$idBici);
        audit($pdo, null, null, 'solicitud_recibida', null, 'Alquiler creado', "Alquiler $codigo · Bicicleta {$bike['codigo']}", 'alquiler', $idAlquiler);
        notify($pdo, 'Nueva solicitud de alquiler', "$codigo · {$bike['codigo']} · " . format_date($inicio->format('Y-m-d H:i:s')), 'admin', null, 'alquileres_admin.php');
        notify($pdo, 'Solicitud recibida', "Tu solicitud $codigo fue registrada. Te avisaremos cuando sea confirmada.", 'cliente', (int)$user['id'], 'mis_alquileres.php');

        $pdo->commit();
        flash('success', '¡Solicitud registrada! Tu ticket ' . $ticketCode . ' ya está disponible.');
        redirect('ticket.php?codigo=' . urlencode($codigo));
    }

    $idAlquiler = post_int('id_alquiler', 0);
    $stmt = $pdo->prepare('SELECT a.*, b.codigo AS bici_codigo, b.modelo, u.nombre AS cliente_nombre, u.email AS cliente_email
                           FROM alquileres a JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
                           JOIN usuarios u ON u.id=a.id_cliente WHERE a.id_alquiler = ? FOR UPDATE');
    $stmt->execute([$idAlquiler]);
    $alq = $stmt->fetch();
    if (!$alq) throw new RuntimeException('El alquiler no existe.');

    if (!is_admin() && !((int)$alq['id_cliente'] === (int)(current_user()['id'] ?? 0) && $accion === 'solicitar_finalizacion')) {
        throw new RuntimeException('No tienes permisos sobre este alquiler.');
    }

    if ($accion === 'registrar_pago') {
        $estadoPago = post_string('estado_pago', 'pendiente');
        if (!in_array($estadoPago, ['pendiente', 'confirmado', 'fallido'], true)) throw new RuntimeException('Estado de pago inválido.');
        $metodo = post_string('metodo', 'Pendiente');
        $referencia = post_string('referencia');
        $pdo->prepare('UPDATE alquileres SET estado_pago = ?, metodo_pago = ? WHERE id_alquiler = ?')
            ->execute([$estadoPago, $metodo, $idAlquiler]);
        $pdo->prepare('INSERT INTO pagos (id_alquiler, monto, metodo, referencia, estado, registrado_por) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$idAlquiler, $alq['precio_total'], $metodo, $referencia !== '' ? $referencia : null, $estadoPago, (int)(current_user()['id'] ?? 0)]);
        audit($pdo, null, $alq['estado_pago'], $estadoPago, null, 'Registro de pago', $alq['codigo_alquiler'], 'alquiler', $idAlquiler);
        notify($pdo, 'Estado de pago actualizado', $alq['codigo_alquiler'] . ': ' . $estadoPago, 'cliente', (int)$alq['id_cliente'], 'ticket.php?codigo=' . urlencode($alq['codigo_alquiler']));
        $pdo->commit();
        flash('success', 'Estado de pago registrado. No se ha realizado ningún cobro automático.');
        redirect_back('alquileres_admin.php');
    }

    $map = [
        'confirmar' => ['solicitud_recibida', 'confirmado', 'Alquiler confirmado por administración.', 'confirmado'],
        'asignar' => ['confirmado', 'asignado', 'Bicicleta asignada y preparada para entrega.', 'asignado'],
        'activar' => ['asignado', 'activo', 'Alquiler activo: entrega realizada.', 'activo'],
        'solicitar_finalizacion' => ['activo', 'finalizacion_solicitada', 'El cliente solicitó la finalización del alquiler.', 'finalizacion_solicitada'],
        'finalizar' => ['finalizacion_solicitada', 'finalizado', 'Alquiler finalizado por administración.', 'finalizado'],
        'cancelar' => [null, 'cancelado', 'Alquiler cancelado.', 'cancelado'],
    ];
    if (!isset($map[$accion])) throw new RuntimeException('Acción no reconocida.');
    [$desde, $nuevo, $descripcion, $estado] = $map[$accion];
    if ($accion === 'finalizar' && $alq['estado'] === 'activo') $desde = 'activo';
    if ($desde !== null && $alq['estado'] !== $desde) {
        throw new RuntimeException('Transición no permitida. Estado actual: ' . rental_label($alq['estado']));
    }
    $finReal = ($accion === 'finalizar') ? ', fecha_fin_real = NOW()' : '';
    $pdo->prepare("UPDATE alquileres SET estado = ? $finReal WHERE id_alquiler = ?")->execute([$estado, $idAlquiler]);
    $pdo->prepare('INSERT INTO alquiler_historial (id_alquiler, estado, descripcion, id_usuario) VALUES (?, ?, ?, ?)')
        ->execute([$idAlquiler, $estado, $descripcion, (int)(current_user()['id'] ?? 0)]);
    state_history($pdo, 'alquiler', $idAlquiler, $alq['estado'], $estado, $descripcion);
    audit($pdo, null, $alq['estado'], $estado, null, 'Cambio de estado de alquiler', $alq['codigo_alquiler'], 'alquiler', $idAlquiler);
    refresh_bike_state($pdo, (int)$alq['id_bicicleta']);
    notify($pdo, 'Actualización de tu alquiler', $alq['codigo_alquiler'] . ': ' . rental_label($estado), 'cliente', (int)$alq['id_cliente'], 'ticket.php?codigo=' . urlencode($alq['codigo_alquiler']));

    $pdo->commit();
    flash('success', 'Alquiler actualizado correctamente.');
    redirect_back(is_client() ? 'mis_alquileres.php' : 'alquileres_admin.php');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'No se pudo completar la operación: ' . $ex->getMessage());
    redirect_back(is_client() ? 'cliente.php' : 'alquileres_admin.php');
}
