<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();
$pdo = db();
$user = current_user();
$codigoAlquiler = trim((string)($_GET['alquiler'] ?? ''));

$stmt = $pdo->prepare(
    "SELECT a.*, b.codigo AS bici_codigo, b.modelo, b.marca, b.tipo_bicicleta, b.nombre AS bici_nombre
     FROM alquileres a JOIN bicicletas b ON b.id_bicicleta = a.id_bicicleta
     WHERE a.codigo_alquiler = ? AND a.id_cliente = ?"
);
$stmt->execute([$codigoAlquiler, (int)$user['id']]);
$alq = $stmt->fetch();
if (!$alq) {
    flash('error', 'Selecciona uno de tus alquileres para reportar un inconveniente.');
    redirect('mis_alquileres.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $pdo->beginTransaction();
        $idAlquiler = (int)post_int('id_alquiler', 0);
        $stmt = $pdo->prepare('SELECT a.*, b.* FROM alquileres a JOIN bicicletas b ON b.id_bicicleta=a.id_bicicleta
                               WHERE a.id_alquiler = ? AND a.id_cliente = ? FOR UPDATE');
        $stmt->execute([$idAlquiler, (int)$user['id']]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('El alquiler no pertenece a tu cuenta.');
        $categoria = post_string('categoria_problema');
        if (!in_array($categoria, REPORT_CATEGORIES, true)) throw new RuntimeException('Selecciona un tipo de inconveniente válido.');
        $descripcion = post_string('descripcion');
        if (mb_strlen($descripcion) < 10) throw new RuntimeException('Describe el problema con al menos 10 caracteres.');
        $observaciones = post_string('observaciones');
        $fotos = handle_safe_uploads($_FILES['fotos'] ?? [], 4);

        $codigoReporte = unique_code($pdo, 'reportes_clientes', 'codigo_seguimiento', 'INC');
        $pdo->prepare(
            'INSERT INTO reportes_clientes
             (codigo_seguimiento, id_bicicleta, id_alquiler, id_cliente, nombre_cliente, telefono_cliente,
              marca, modelo, codigo_bici, tipo_bicicleta, categoria_problema, prioridad, descripcion, observaciones, foto, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'En espera\')'
        )->execute([
            $codigoReporte, (int)$row['id_bicicleta'], $idAlquiler, (int)$user['id'],
            (string)$user['nombre'], (string)($user['telefono'] ?? ''),
            $row['marca'], $row['modelo'], $row['codigo'], $row['tipo_bicicleta'],
            $categoria, 'alta', $descripcion, $observaciones !== '' ? $observaciones : null, $fotos[0] ?? null
        ]);
        $idReporte = (int)$pdo->lastInsertId();
        foreach ($fotos as $ruta) {
            $pdo->prepare('INSERT INTO reporte_fotos (id_reporte, ruta) VALUES (?, ?)')->execute([$idReporte, $ruta]);
        }

        // Un reporte que afecta la bicicleta bloquea nuevos alquileres de forma inmediata.
        $pdo->prepare("UPDATE bicicletas SET estado_operativo = 'en_revision' WHERE id_bicicleta = ?")->execute([(int)$row['id_bicicleta']]);
        state_history($pdo, 'bicicleta', (int)$row['id_bicicleta'], $row['estado_operativo'], 'en_revision', 'Incidencia reportada durante alquiler ' . $row['codigo_alquiler']);
        state_history($pdo, 'reporte', $idReporte, null, 'En espera', 'Reporte recibido del cliente');
        $pdo->prepare('INSERT INTO alquiler_historial (id_alquiler, estado, descripcion, id_usuario) VALUES (?, ?, ?, ?)')
            ->execute([$idAlquiler, $row['estado'], 'Incidencia reportada: ' . $categoria . ' (' . $codigoReporte . ')', (int)$user['id']]);
        audit($pdo, null, null, 'En espera', null, 'Reporte de incidencia', "$codigoReporte · {$row['codigo']} · {$_POST['categoria_problema']}", 'reporte', $idReporte);
        notify($pdo, 'Nueva incidencia en flota', "$codigoReporte · {$row['codigo']} · $categoria", 'mecanico', null, 'reporte_detalle.php?id=' . $idReporte);
        notify($pdo, 'Incidencia reportada', "Recibimos tu reporte $codigoReporte. El equipo técnico ya fue notificado.", 'cliente', (int)$user['id'], 'seguimiento.php?codigo=' . urlencode($codigoReporte));

        $pdo->commit();
        flash('success', 'Reporte enviado. El departamento técnico ya puede verlo.');
        redirect('seguimiento.php?codigo=' . urlencode($codigoReporte));
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex->getMessage();
    }
}

$pageTitle = 'Reportar un problema';
include __DIR__ . '/partials/header.php';
?>
<a class="back-link" href="ticket.php?codigo=<?= e($alq['codigo_alquiler']) ?>"><span class="material-symbols-outlined">arrow_back</span>Volver al ticket</a>
<section class="page-intro">
    <span class="eyebrow">ALQUILER <?= e($alq['codigo_alquiler']) ?></span>
    <h1>Reportar un problema</h1>
    <p>Tu reporte quedará vinculado a este alquiler, a la bicicleta <?= e($alq['bici_codigo']) ?> y al departamento técnico de eBike.</p>
</section>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="form-card">
    <form method="post" action="" enctype="multipart/form-data" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id_alquiler" value="<?= (int)$alq['id_alquiler'] ?>">
        <label>Tipo de inconveniente *
            <select class="input" name="categoria_problema" required>
                <option value="">Selecciona una opción</option>
                <?php foreach (REPORT_CATEGORIES as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Descripción *
            <textarea class="input" name="descripcion" rows="5" minlength="10" maxlength="1000" required placeholder="¿Qué ocurrió?, ¿en qué momento?, ¿afecta la seguridad o el funcionamiento?"></textarea>
        </label>
        <label>Observaciones adicionales
            <textarea class="input" name="observaciones" rows="3" maxlength="500" placeholder="Ubicación, instrucciones para el mecánico, etc."></textarea>
        </label>
        <label>Fotografías (máx. 4, JPG/PNG/WEBP de 5 MB c/u)
            <input class="input" type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div class="form-actions">
            <a class="btn btn-ghost" href="ticket.php?codigo=<?= e($alq['codigo_alquiler']) ?>">Cancelar</a>
            <button class="btn btn-danger" type="submit"><span class="material-symbols-outlined">send</span>Enviar reporte</button>
        </div>
    </form>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
