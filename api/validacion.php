<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('reportes_admin.php');

try {
    verify_csrf();
    $pdo->beginTransaction();
    $idReporte = post_int('id_reporte', 0);
    $accion = post_string('accion');
    $stmt = $pdo->prepare('SELECT r.*, b.codigo AS bici_codigo, b.estado_operativo FROM reportes_clientes r
                           JOIN bicicletas b ON b.id_bicicleta=r.id_bicicleta WHERE r.id_reporte = ? FOR UPDATE');
    $stmt->execute([$idReporte]);
    $r = $stmt->fetch();
    if (!$r) throw new RuntimeException('El reporte no existe.');

    if ($accion === 'aprobar') {
        if ($r['estado'] !== 'Terminado') throw new RuntimeException('Solo se pueden aprobar casos terminados por los mecánicos.');
        $nuevo = 'Cerrado';
        $pdo->prepare('UPDATE reportes_clientes SET estado = ?, cerrado_en = NOW(), validado_por = ? WHERE id_reporte = ?')
            ->execute([$nuevo, (int)(current_user()['id'] ?? 0), $idReporte]);
        if ($r['id_mantenimiento']) {
            $pdo->prepare('UPDATE mantenimientos SET estado = ?, cerrado_en = NOW(), fecha_salida = COALESCE(fecha_salida, NOW()) WHERE id_manten = ?')
                ->execute([$nuevo, (int)$r['id_mantenimiento']]);
        }

        // La bicicleta vuelve a la flota solo si no hay un alquiler activo que la ocupe.
        $stmt = $pdo->prepare(
            "SELECT MAX(CASE WHEN estado IN ('activo','asignado') THEN 1 ELSE 0 END) AS en_uso,
                    MAX(CASE WHEN estado IN ('confirmado','solicitud_recibida') AND fecha_inicio > NOW() THEN 1 ELSE 0 END) AS reservada
             FROM alquileres WHERE id_bicicleta = ? AND estado IN ('confirmado','solicitud_recibida','asignado','activo')"
        );
        $stmt->execute([(int)$r['id_bicicleta']]);
        $occ = $stmt->fetch();
        $biciEstado = ((int)($occ['en_uso'] ?? 0) === 1) ? 'alquilada'
            : (((int)($occ['reservada'] ?? 0) === 1) ? 'reservada' : 'disponible');
        $pdo->prepare('UPDATE bicicletas SET estado_operativo = ? WHERE id_bicicleta = ?')
            ->execute([$biciEstado, (int)$r['id_bicicleta']]);
        state_history($pdo, 'bicicleta', (int)$r['id_bicicleta'], $r['estado_operativo'] ?? 'en_revision', $biciEstado, 'Validación administrativa de ' . $r['codigo_seguimiento']);
        notify($pdo, 'Incidencia cerrada', 'Tu reporte ' . $r['codigo_seguimiento'] . ' fue validado. La bicicleta ' . $r['bici_codigo'] . ' volvió a la flota.', 'cliente', $r['id_cliente'] ? (int)$r['id_cliente'] : null, 'seguimiento.php?codigo=' . urlencode($r['codigo_seguimiento']));
    } elseif ($accion === 'nueva_revision') {
        if (!in_array($r['estado'], ['Terminado', 'En pruebas'], true)) throw new RuntimeException('No se puede solicitar revisión desde el estado actual.');
        $nuevo = 'Requiere nueva revisión';
        $pdo->prepare('UPDATE reportes_clientes SET estado = ?, cerrado_en = NULL, validado_por = NULL WHERE id_reporte = ?')
            ->execute([$nuevo, $idReporte]);
        if ($r['id_mantenimiento']) {
            $pdo->prepare('UPDATE mantenimientos SET estado = ?, cerrado_en = NULL WHERE id_manten = ?')
                ->execute([$nuevo, (int)$r['id_mantenimiento']]);
        }
        notify($pdo, 'Revisión solicitada', 'El administrador solicita nueva revisión en ' . $r['codigo_seguimiento'], 'mecanico', null, 'reporte_detalle.php?id=' . $idReporte);
    } else {
        throw new RuntimeException('Acción de validación no reconocida.');
    }

    state_history($pdo, 'reporte', $idReporte, $r['estado'], $nuevo, 'Validación administrativa');
    audit($pdo, $r['id_mantenimiento'] ? (int)$r['id_mantenimiento'] : null, $r['estado'], $nuevo, null,
        $accion === 'aprobar' ? 'Aprobación de cierre' : 'Nueva revisión solicitada',
        $r['codigo_seguimiento'], 'reporte', $idReporte);
    $pdo->commit();
    flash('success', $accion === 'aprobar' ? 'Incidencia cerrada y bicicleta liberada.' : 'Caso devuelto a los mecánicos con su historial intacto.');
    redirect('reportes_admin.php');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'No se pudo validar el caso: ' . $ex->getMessage());
    redirect('reportes_admin.php');
}
