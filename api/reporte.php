<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../reportes_admin.php');
}

$pdo = db();

try {
    verify_csrf();
    $idBike = post_int('id_bicicleta');
    $nombre = post_string('nombre_cliente');
    $telefono = post_string('telefono_cliente');
    $categoria = post_string('categoria_problema', 'Otros');
    $descripcion = post_string('descripcion');
    $observaciones = post_string('observaciones');

    if (!$idBike || $nombre === '' || $descripcion === '') {
        throw new RuntimeException('Selecciona una bicicleta y completa la descripción de la falla.');
    }

    // Obtener datos de la bicicleta
    $bStmt = $pdo->prepare("SELECT codigo, modelo, marca, tipo_bicicleta FROM bicicletas WHERE id_bicicleta = ?");
    $bStmt->execute([$idBike]);
    $b = $bStmt->fetch();

    $codigoSeg = 'REP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $s = $pdo->prepare("
        INSERT INTO reportes_clientes 
        (codigo_seguimiento, id_bicicleta, nombre_cliente, telefono_cliente, marca, modelo, codigo_bici, tipo_bicicleta, categoria_problema, descripcion, observaciones, estado, creado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Recibido', NOW())
    ");
    $s->execute([
        $codigoSeg,
        $idBike,
        $nombre,
        $telefono ?: null,
        $b['marca'] ?? 'eBike',
        $b['modelo'] ?? 'eBike',
        $b['codigo'] ?? 'S/N',
        $b['tipo_bicicleta'] ?? 'Urbana',
        $categoria,
        $descripcion,
        $observaciones ?: null
    ]);

    $newId = (int)$pdo->lastInsertId();

    audit($pdo, null, null, 'Recibido', null, 'Reporte Creado por Admin', "Reporte #$newId ($codigoSeg) para {$b['codigo']}");
    notify($pdo, "Nuevo reporte registrado en recepción", "Reporte #$newId ($codigoSeg) - Categoría: $categoria", 'mecanico', null, 'mecanico.php');

    flash('success', "Reporte de bicicleta registrado correctamente. Código de seguimiento: $codigoSeg.");
} catch (Throwable $e) {
    flash('error', 'No se pudo crear el reporte: ' . $e->getMessage());
}

redirect('../reportes_admin.php');
