<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../configuracion.php');
}

$pdo = db();

try {
    verify_csrf();
    $nombre = post_string('nombre_taller');
    $nit = post_string('nit');
    $tel = post_string('telefono');
    $email = post_string('email');
    $dir = post_string('direccion');
    $garantia = post_string('garantia_dias', '90');

    if ($nombre === '') throw new RuntimeException('El nombre del taller es obligatorio.');

    $configs = [
        'nombre_taller'  => $nombre,
        'nit'            => $nit,
        'telefono'       => $tel,
        'email'          => $email,
        'direccion'      => $dir,
        'garantia_dias'  => $garantia
    ];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO configuracion_taller (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    foreach ($configs as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    audit($pdo, null, null, null, null, 'Configuración Taller', 'Datos comerciales y fiscales actualizados');
    $pdo->commit();

    flash('success', 'Configuración del taller guardada exitosamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}

redirect('../configuracion.php');
