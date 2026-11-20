<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();

$pageTitle = 'Reportar Falla de Bicicleta';
$pdo = db();
$userId = (int)current_user()['id'];
$userName = current_user()['nombre'] ?? '';
$userPhone = current_user()['telefono'] ?? '';

// Cargar bicicletas del cliente
$stmtBikes = $pdo->prepare("SELECT * FROM bicicletas WHERE id_usuario = ? OR (cliente = ? AND cliente IS NOT NULL AND cliente <> '') ORDER BY id_bicicleta DESC");
$stmtBikes->execute([$userId, $userName]);
$myBikes = $stmtBikes->fetchAll();

$selectedBikeId = isset($_GET['id_bicicleta']) ? (int)$_GET['id_bicicleta'] : 0;

$error = '';
$successReport = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $bikeOption = post_string('bike_option', 'existing');
        $idBicicleta = post_int('id_bicicleta');
        $codigoBici = post_string('codigo_bici');
        $marca = post_string('marca', 'eBike');
        $modelo = post_string('modelo');
        $tipoBici = post_string('tipo_bicicleta', 'Urbana');
        $nombreCliente = post_string('nombre_cliente', $userName);
        $telefonoCliente = post_string('telefono_cliente', $userPhone);
        $categoria = post_string('categoria_problema', 'Otros');
        $descripcion = post_string('descripcion');
        $observaciones = post_string('observaciones');

        if ($descripcion === '') {
            throw new RuntimeException('Debes describir detalladamente la falla de la bicicleta.');
        }

        // Manejo de la bicicleta
        if ($bikeOption === 'new' || !$idBicicleta) {
            if ($codigoBici === '' || $modelo === '') {
                throw new RuntimeException('Para registrar una nueva bicicleta, indica código/placa y modelo.');
            }
            // Comprobar si ya existe
            $chk = $pdo->prepare("SELECT id_bicicleta FROM bicicletas WHERE codigo = ? LIMIT 1");
            $chk->execute([$codigoBici]);
            $existing = $chk->fetch();
            if ($existing) {
                $idBicicleta = (int)$existing['id_bicicleta'];
            } else {
                $insB = $pdo->prepare("INSERT INTO bicicletas (codigo, modelo, marca, cliente, telefono_cliente, id_usuario, tipo_bicicleta, activa) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $insB->execute([$codigoBici, $modelo, $marca ?: 'eBike', $nombreCliente, $telefonoCliente, $userId, $tipoBici]);
                $idBicicleta = (int)$pdo->lastInsertId();
            }
        } else {
            // Obtener datos de la bicicleta seleccionada
            $bStmt = $pdo->prepare("SELECT codigo, marca, modelo, tipo_bicicleta FROM bicicletas WHERE id_bicicleta = ?");
            $bStmt->execute([$idBicicleta]);
            $bData = $bStmt->fetch();
            if ($bData) {
                $codigoBici = $bData['codigo'];
                $marca = $bData['marca'];
                $modelo = $bData['modelo'];
                $tipoBici = $bData['tipo_bicicleta'];
            }
        }

        // Procesar foto opcional de forma segura
        $fotoPath = null;
        if (!empty($_FILES['foto']['name'])) {
            $fotoPath = handle_safe_upload($_FILES['foto']);
        }

        // Generar número de seguimiento único
        $codigoSeguimiento = 'REP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

        // Guardar reporte
        $pdo->beginTransaction();
        $stmtRep = $pdo->prepare("
            INSERT INTO reportes_clientes 
            (codigo_seguimiento, id_bicicleta, id_cliente, nombre_cliente, telefono_cliente, marca, modelo, codigo_bici, tipo_bicicleta, categoria_problema, descripcion, observaciones, foto, estado, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Recibido', NOW())
        ");
        $stmtRep->execute([
            $codigoSeguimiento,
            $idBicicleta,
            $userId,
            $nombreCliente,
            $telefonoCliente,
            $marca,
            $modelo,
            $codigoBici,
            $tipoBici,
            $categoria,
            $descripcion,
            $observaciones ?: null,
            $fotoPath
        ]);

        $idReporte = (int)$pdo->lastInsertId();

        // Registrar auditoría
        audit($pdo, null, null, 'Recibido', null, 'Reporte de cliente creado', "Reporte #$idReporte con código $codigoSeguimiento para bicicleta $codigoBici");

        // Crear notificación interna para Administradores y Mecánicos
        notify($pdo, "Nuevo reporte de bicicleta ($codigoBici)", "El cliente $nombreCliente ha reportado una falla en categoría: $categoria. Código: $codigoSeguimiento", 'admin', null, 'reportes_admin.php');
        notify($pdo, "Nuevo servicio disponible en recepción", "Bicicleta $codigoBici - $categoria. Código de seguimiento: $codigoSeguimiento", 'mecanico', null, 'mecanico.php');

        $pdo->commit();

        $successReport = [
            'codigo' => $codigoSeguimiento,
            'id' => $idReporte,
            'bici' => $codigoBici . ' · ' . $modelo,
            'categoria' => $categoria
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">RECLAMACIÓN & INGRESO A TALLER</span>
    <h1>Reportar Falla de Bicicleta Eléctrica</h1>
    <p>Describe el problema técnico de tu eBike para que nuestro equipo especializado inicie el diagnóstico.</p>
  </div>
  <a class="btn btn-light" href="cliente.php">
    <span class="material-symbols-outlined">arrow_back</span>Volver a mi panel
  </a>
</div>

<?php if ($successReport): ?>
  <div class="card" style="padding:28px;border-left:5px solid var(--green);margin-bottom:24px;background:#f2fbf5">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:48px;height:48px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;flex:none">
        <span class="material-symbols-outlined" style="font-size:28px">check_circle</span>
      </div>
      <div>
        <h2 style="font:700 22px 'Plus Jakarta Sans';margin:0 0 4px;color:var(--green-900)">¡Reporte registrado exitosamente!</h2>
        <p style="margin:0;color:var(--muted);font-size:14px">Tu solicitud ha sido ingresada al sistema de taller y notificada a los técnicos.</p>
      </div>
    </div>
    <div style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;margin-top:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
      <div>
        <span class="eyebrow">CÓDIGO ÚNICO DE SEGUIMIENTO</span>
        <strong style="font:700 24px monospace;color:var(--green-800);display:block"><?= e($successReport['codigo']) ?></strong>
        <span class="muted" style="font-size:12px">Bicicleta: <?= e($successReport['bici']) ?> · Categoría: <?= e($successReport['categoria']) ?></span>
      </div>
      <div style="display:flex;gap:10px">
        <a class="btn btn-primary" href="seguimiento.php?codigo=<?= urlencode($successReport['codigo']) ?>">
          <span class="material-symbols-outlined">qr_code_scanner</span>Ver Seguimiento en Vivo
        </a>
        <a class="btn btn-light" href="cliente.php">Volver a mis solicitudes</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-error">
    <strong>Error:</strong> <?= e($error) ?>
  </div>
<?php endif; ?>

<section class="panel" style="max-width:960px;margin:auto">
  <div class="panel-head">
    <div>
      <h2>Formulario de Recepción y Diagnóstico</h2>
      <p>Todos los campos con asterisco (<span class="required">*</span>) son obligatorios.</p>
    </div>
  </div>

  <form method="post" action="reportar.php" enctype="multipart/form-data" style="padding:24px">
    <?= csrf_field() ?>

    <div style="margin-bottom:20px;padding:14px;background:var(--green-50);border-radius:12px;border:1px solid var(--green-100)">
      <strong style="font-size:13px;display:block;margin-bottom:8px;color:var(--green-900)">Selecciona la eBike a reportar:</strong>
      <div style="display:flex;gap:20px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600">
          <input type="radio" name="bike_option" value="existing" <?= (!$myBikes || $selectedBikeId || count($myBikes) > 0) ? 'checked' : '' ?> onchange="toggleBikeSelection()">
          Bicicleta ya registrada en mi cuenta
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600">
          <input type="radio" name="bike_option" value="new" <?= (!$myBikes) ? 'checked' : '' ?> onchange="toggleBikeSelection()">
          Registrar una bicicleta nueva para este reporte
        </label>
      </div>
    </div>

    <!-- Selección de Bicicleta Existente -->
    <div id="sectionExistingBike" style="margin-bottom:20px">
      <div class="field">
        <label>Bicicleta Registrada <span class="required">*</span></label>
        <select class="select" name="id_bicicleta" id="selectBicicleta">
          <option value="">Selecciona una de tus bicicletas...</option>
          <?php foreach ($myBikes as $b): ?>
            <option value="<?= (int)$b['id_bicicleta'] ?>" <?= ($selectedBikeId === (int)$b['id_bicicleta']) ? 'selected' : '' ?>>
              <?= e($b['codigo']) ?> · <?= e($b['marca']) ?> <?= e($b['modelo']) ?> (<?= e($b['tipo_bicicleta'] ?: 'Urbana') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Formulario para Bicicleta Nueva -->
    <div id="sectionNewBike" style="display:none;margin-bottom:20px;background:#fdfdfd;padding:18px;border:1px dashed var(--line);border-radius:12px">
      <div class="form-grid">
        <div class="field">
          <label>Código o Placa identificadora <span class="required">*</span></label>
          <input class="input" name="codigo_bici" placeholder="Ej. EBK-9901, BICI-VERDE">
        </div>
        <div class="field">
          <label>Modelo de la bicicleta <span class="required">*</span></label>
          <input class="input" name="modelo" placeholder="Ej. Trail Master 500W">
        </div>
        <div class="field">
          <label>Marca</label>
          <input class="input" name="marca" value="eBike" placeholder="eBike, Trek, etc.">
        </div>
        <div class="field">
          <label>Tipo de Bicicleta</label>
          <select class="select" name="tipo_bicicleta">
            <?php foreach (BIKE_TYPES as $bt): ?>
              <option value="<?= e($bt) ?>"><?= e($bt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Datos del Cliente y Contacto -->
    <div class="form-grid" style="margin-bottom:20px">
      <div class="field">
        <label>Nombre del Cliente <span class="required">*</span></label>
        <input class="input" name="nombre_cliente" required value="<?= e($userName) ?>">
      </div>
      <div class="field">
        <label>Teléfono o Celular de Contacto <span class="required">*</span></label>
        <input class="input" name="telefono_cliente" required value="<?= e($userPhone) ?>" placeholder="300 000 0000">
      </div>
    </div>

    <!-- Detalle de la Falla Técnica -->
    <div class="form-grid" style="margin-bottom:20px">
      <div class="field full">
        <label>Categoría del Problema <span class="required">*</span></label>
        <select class="select" name="categoria_problema" required>
          <?php foreach (PROBLEM_CATEGORIES as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Clasifica el sistema principal afectado para asignar al técnico adecuado.</small>
      </div>

      <div class="field full">
        <label>Descripción detallada de la falla <span class="required">*</span></label>
        <textarea class="textarea" name="descripcion" required placeholder="Describe los síntomas: ¿se apaga sola? ¿ruido extraño? ¿no frena bien? ¿código de error en pantalla?"></textarea>
      </div>

      <div class="field full">
        <label>Observaciones adicionales / Instrucciones</label>
        <textarea class="textarea" name="observaciones" style="min-height:70px" placeholder="Ej. Dejo cargador en la alforja, tengo prisa para el viernes, etc."></textarea>
      </div>

      <div class="field full">
        <label>Fotografía opcional del problema / bicicleta</label>
        <input type="file" name="foto" class="input" accept="image/jpeg,image/png,image/webp">
        <small class="muted">Formatos admitidos: JPG, PNG o WEBP (máx. 5 MB). Ayuda al mecánico en el pre-diagnóstico.</small>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:12px;border-top:1px solid var(--line);padding-top:18px">
      <a class="btn btn-light" href="cliente.php">Cancelar</a>
      <button class="btn btn-primary" type="submit">
        <span class="material-symbols-outlined">send</span>Enviar Reporte a Taller
      </button>
    </div>
  </form>
</section>

<script>
function toggleBikeSelection(){
  const opt = document.querySelector('input[name="bike_option"]:checked')?.value;
  const existingSection = document.getElementById('sectionExistingBike');
  const newSection = document.getElementById('sectionNewBike');
  if(opt === 'new'){
    existingSection.style.display = 'none';
    newSection.style.display = 'block';
  } else {
    existingSection.style.display = 'block';
    newSection.style.display = 'none';
  }
}
window.addEventListener('DOMContentLoaded', toggleBikeSelection);
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
