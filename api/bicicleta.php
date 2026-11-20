<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../bicicletas.php');
}

$pdo = db();

try {
    verify_csrf();
    $accion = post_string('accion');
    $codigo = post_string('codigo');
    $modelo = post_string('modelo');
    $marca = post_string('marca', 'eBike');
    $tipo = post_string('tipo_bicicleta', 'Urbana');
    $cliente = post_string('cliente');
    $tel = post_string('telefono_cliente');
    $idUser = post_int('id_usuario');
    $serie = post_string('numero_serie');
    $color = post_string('color');
    $activa = post_int('activa', 1) ? 1 : 0;

    if ($codigo === '' || $modelo === '') {
        throw new RuntimeException('Código y modelo son campos obligatorios.');
    }

    if ($accion === 'crear') {
        $chk = $pdo->prepare("SELECT id_bicicleta FROM bicicletas WHERE codigo = ? LIMIT 1");
        $chk->execute([$codigo]);
        if ($chk->fetch()) {
            throw new RuntimeException("Ya existe una bicicleta con el código '$codigo'.");
        }

        $s = $pdo->prepare("
            INSERT INTO bicicletas (codigo, modelo, marca, cliente, telefono_cliente, id_usuario, tipo_bicicleta, numero_serie, color, activa, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $s->execute([
            $codigo, $modelo, $marca ?: 'eBike', $cliente ?: null, $tel ?: null,
            $idUser ?: null, $tipo, $serie ?: null, $color ?: null, $activa
        ]);

        audit($pdo, null, null, null, null, 'Alta de Bicicleta', "Bicicleta $codigo ($modelo) creada");
        flash('success', "Bicicleta '$codigo' registrada correctamente.");
    } elseif ($accion === 'editar') {
        $id = post_int('id_bicicleta');
        if (!$id) throw new RuntimeException('ID de bicicleta inválido.');

        $chk = $pdo->prepare("SELECT id_bicicleta FROM bicicletas WHERE codigo = ? AND id_bicicleta <> ?");
        $chk->execute([$codigo, $id]);
        if ($chk->fetch()) {
            throw new RuntimeException("El código '$codigo' ya pertenece a otra bicicleta.");
        }

        $s = $pdo->prepare("
            UPDATE bicicletas 
            SET codigo = ?, modelo = ?, marca = ?, cliente = ?, telefono_cliente = ?, 
                id_usuario = ?, tipo_bicicleta = ?, numero_serie = ?, color = ?, activa = ?
            WHERE id_bicicleta = ?
        ");
        $s->execute([
            $codigo, $modelo, $marca ?: 'eBike', $cliente ?: null, $tel ?: null,
            $idUser ?: null, $tipo, $serie ?: null, $color ?: null, $activa, $id
        ]);

        audit($pdo, null, null, null, null, 'Edición de Bicicleta', "Bicicleta #$id ($codigo) actualizada");
        flash('success', "Bicicleta '$codigo' actualizada correctamente.");
    } else {
        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    flash('error', 'Error al guardar bicicleta: ' . $e->getMessage());
}

redirect('../bicicletas.php');
