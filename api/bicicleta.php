<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('bicicletas.php');
$adminOnly = in_array(post_string('accion', 'crear'), ['crear', 'editar', 'estado'], true);
if ($adminOnly) require_admin(); else require_mechanic_or_admin();

try {
    verify_csrf();
    $accion = post_string('accion', 'crear');
    $id = post_int('id_bicicleta', 0);

    if ($accion === 'estado') {
        $estado = post_string('estado_operativo');
        if (!isset(BIKE_OPERATIVE_STATES[$estado])) throw new RuntimeException('Estado operativo no válido.');
        $stmt = $pdo->prepare('SELECT estado_operativo FROM bicicletas WHERE id_bicicleta=?');
        $stmt->execute([$id]); $old = $stmt->fetchColumn();
        if (!$old) throw new RuntimeException('La bicicleta no existe.');
        $pdo->prepare('UPDATE bicicletas SET estado_operativo=?, activa=? WHERE id_bicicleta=?')
            ->execute([$estado, $estado === 'fuera_de_servicio' ? 0 : 1, $id]);
        state_history($pdo, 'bicicleta', $id, $old, $estado, 'Cambio manual de estado operativo');
        audit($pdo, null, $old, $estado, null, 'Estado de flota', null, 'bicicleta', $id);
        flash('success', 'Estado operativo actualizado.');
        redirect('bicicletas.php');
    }

    $codigo = strtoupper(post_string('codigo'));
    $modelo = post_string('modelo');
    $nombre = post_string('nombre');
    $marca = post_string('marca', 'eBike');
    $tipo = post_string('tipo_bicicleta', 'Urbana');
    $numeroSerie = post_string('numero_serie');
    $color = post_string('color');
    $descripcion = post_string('descripcion');
    $caracteristicas = post_string('caracteristicas');
    $autonomia = max(0, post_int('autonomia_km', 0));
    $capacidad = max(0, post_int('capacidad_bateria_wh', 0));
    $nivel = min(100, max(0, post_int('nivel_bateria', 100)));
    $precioHora = max(0, post_float('precio_hora', 0));
    $precioDia = max(0, post_float('precio_dia', 0));
    $fechaInc = post_string('fecha_incorporacion');
    $fotoSubida = handle_safe_upload($_FILES['foto'] ?? []);

    if ($accion === 'crear') {
        if ($codigo === '' || $modelo === '') throw new RuntimeException('Código y modelo son obligatorios.');
        $stmt = $pdo->prepare('SELECT 1 FROM bicicletas WHERE codigo=?'); $stmt->execute([$codigo]);
        if ($stmt->fetchColumn()) throw new RuntimeException('Ese código de bicicleta ya está registrado.');
        if (!in_array($tipo, BIKE_TYPES, true)) throw new RuntimeException('Tipo de bicicleta no válido.');
        $pdo->prepare(
            'INSERT INTO bicicletas (codigo, modelo, nombre, foto, descripcion, caracteristicas, autonomia_km,
                                     capacidad_bateria_wh, nivel_bateria, precio_hora, precio_dia, estado_operativo,
                                     fecha_incorporacion, marca, tipo_bicicleta, numero_serie, color, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'disponible\', ?, ?, ?, ?, ?, 1)'
        )->execute([
            $codigo, $modelo, $nombre !== '' ? $nombre : $modelo, $fotoSubida, $descripcion, $caracteristicas,
            $autonomia, $capacidad, $nivel, $precioHora, $precioDia,
            $fechaInc !== '' ? $fechaInc : date('Y-m-d H:i:s'), $marca, $tipo,
            $numeroSerie !== '' ? $numeroSerie : null, $color !== '' ? $color : null
        ]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, null, null, 'disponible', null, 'Alta de bicicleta de flota', $codigo, 'bicicleta', $id);
        flash('success', 'Bicicleta incorporada a la flota.');
    } elseif ($accion === 'editar') {
        if ($id <= 0 || $modelo === '') throw new RuntimeException('Datos de edición no válidos.');
        $stmt = $pdo->prepare('SELECT * FROM bicicletas WHERE id_bicicleta=?'); $stmt->execute([$id]); $old = $stmt->fetch();
        if (!$old) throw new RuntimeException('La bicicleta no existe.');
        $foto = $fotoSubida ?: $old['foto'];
        $pdo->prepare(
            'UPDATE bicicletas SET modelo=?, nombre=?, foto=?, descripcion=?, caracteristicas=?, autonomia_km=?,
                                   capacidad_bateria_wh=?, nivel_bateria=?, precio_hora=?, precio_dia=?,
                                   fecha_incorporacion=?, marca=?, tipo_bicicleta=?, numero_serie=?, color=?
             WHERE id_bicicleta=?'
        )->execute([
            $modelo, $nombre !== '' ? $nombre : $modelo, $foto, $descripcion, $caracteristicas, $autonomia,
            $capacidad, $nivel, $precioHora, $precioDia, $fechaInc !== '' ? $fechaInc : $old['fecha_incorporacion'],
            $marca, $tipo, $numeroSerie !== '' ? $numeroSerie : null, $color !== '' ? $color : null, $id
        ]);
        if ((int)$old['nivel_bateria'] !== $nivel) {
            $pdo->prepare('INSERT INTO bateria_revisiones (id_bicicleta, nivel_bateria, observaciones, id_usuario) VALUES (?, ?, ?, ?)')
                ->execute([$id, $nivel, 'Actualización desde gestión de flota', (int)(current_user()['id'] ?? 0)]);
        }
        audit($pdo, null, $old['estado_operativo'], $old['estado_operativo'], null, 'Edición de flota', $old['codigo'], 'bicicleta', $id);
        flash('success', 'Datos de la bicicleta actualizados.');
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
    redirect('bicicletas.php');
} catch (Throwable $ex) {
    flash('error', 'No se pudo guardar: ' . $ex->getMessage());
    redirect_back('bicicletas.php');
}
