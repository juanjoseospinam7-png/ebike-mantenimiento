<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../configuracion.php');
$pdo = db();
try {
    verify_csrf();
    $configs = [
        'nombre_empresa'    => post_string('nombre_empresa'),
        'nombre_taller'     => post_string('nombre_empresa'),
        'nit'               => post_string('nit'),
        'telefono'          => post_string('telefono'),
        'email'             => post_string('email'),
        'direccion'         => post_string('direccion'),
        'horario_atencion'  => post_string('horario_atencion'),
        'politica_alquiler' => post_string('politica_alquiler'),
        'deposito_garantia' => (string)max(0, post_float('deposito_garantia')),
        'garantia_dias'     => (string)max(0, post_int('garantia_dias', 90) ?? 90),
    ];
    if ($configs['nombre_empresa'] === '') throw new RuntimeException('El nombre comercial es obligatorio.');
    if ($configs['email'] !== '' && !filter_var($configs['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO configuracion_taller (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    foreach ($configs as $k => $v) $stmt->execute([$k, $v]);
    audit($pdo, null, null, null, null, 'Configuración de empresa', 'Datos comerciales y políticas de alquiler actualizados', 'configuracion');
    $pdo->commit();
    flash('success', 'Configuración guardada correctamente.');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'No se pudo guardar la configuración: ' . $ex->getMessage());
}
redirect('../configuracion.php');
