<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();

$pageTitle = 'Mis Bicicletas';
$pdo = db();
$userId = (int)current_user()['id'];
$userName = current_user()['nombre'] ?? '';

// Procesar alta o edición de bicicleta por parte del cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $accion = post_string('accion', 'crear');
        $codigo = post_string('codigo');
        $modelo = post_string('modelo');
        $marca = post_string('marca', 'eBike');
        $tipo = post_string('tipo_bicicleta', 'Urbana');
        $serie = post_string('numero_serie');
        $color = post_string('color');

        if ($codigo === '' || $modelo === '') {
            throw new RuntimeException('El código/placa y el modelo son obligatorios.');
        }

        if ($accion === 'crear') {
            $chk = $pdo->prepare("SELECT id_bicicleta FROM bicicletas WHERE codigo = ? LIMIT 1");
            $chk->execute([$codigo]);
            if ($chk->fetch()) {
                throw new RuntimeException('Ya existe una bicicleta registrada con el código "' . $codigo . '". Usa un identificador único.');
            }

            $ins = $pdo->prepare("INSERT INTO bicicletas (codigo, modelo, marca, cliente, telefono_cliente, id_usuario, tipo_bicicleta, numero_serie, color, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $ins->execute([
                $codigo,
                $modelo,
                $marca ?: 'eBike',
                $userName,
                current_user()['telefono'] ?? null,
                $userId,
                $tipo,
                $serie ?: null,
                $color ?: null
            ]);
            flash('success', 'Bicicleta ' . $codigo . ' registrada exitosamente en tu perfil.');
        } elseif ($accion === 'editar') {
            $idBike = post_int('id_bicicleta');
            if (!$idBike) throw new RuntimeException('ID de bicicleta inválido.');

            // Validar propiedad
            $chk = $pdo->prepare("SELECT id_bicicleta FROM bicicletas WHERE id_bicicleta = ? AND id_usuario = ? LIMIT 1");
            $chk->execute([$idBike, $userId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('No tienes permisos para modificar esta bicicleta.');
            }

            $upd = $pdo->prepare("UPDATE bicicletas SET modelo = ?, marca = ?, tipo_bicicleta = ?, numero_serie = ?, color = ? WHERE id_bicicleta = ? AND id_usuario = ?");
            $upd->execute([$modelo, $marca ?: 'eBike', $tipo, $serie ?: null, $color ?: null, $idBike, $userId]);
            flash('success', 'Información de la bicicleta actualizada correctamente.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('mis_bicicletas.php');
}

$stmt = $pdo->prepare("SELECT b.*, COUNT(m.id_manten) AS total_manten FROM bicicletas b LEFT JOIN mantenimientos m ON m.id_bicicleta = b.id_bicicleta WHERE b.id_usuario = ? OR (b.cliente = ? AND b.cliente IS NOT NULL AND b.cliente <> '') GROUP BY b.id_bicicleta ORDER BY b.id_bicicleta DESC");
$stmt->execute([$userId, $userName]);
$rows = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">PORTAL CLIENTE · VEHÍCULOS</span>
    <h1>Mis Bicicletas Eléctricas</h1>
    <p>Registra todas tus eBikes para llevar su historial de mantenimientos y garantías.</p>
  </div>
  <button class="btn btn-primary" type="button" onclick="openNewBikeModal()">
    <span class="material-symbols-outlined">add</span>Registrar Nueva Bicicleta
  </button>
</div>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Bicicletas vinculadas a tu cuenta</h2>
      <p><?= count($rows) ?> vehículo(s) registrado(s).</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Código / Placa</th>
          <th>Marca & Modelo</th>
          <th>Tipo</th>
          <th>Color</th>
          <th>N° Serie</th>
          <th>Servicios en Taller</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b style="font-family:monospace;color:var(--green-800);font-size:14px"><?= e($r['codigo']) ?></b></td>
          <td><b><?= e($r['marca']) ?></b> <?= e($r['modelo']) ?></td>
          <td><span class="badge badge-neutral"><?= e($r['tipo_bicicleta'] ?: 'Urbana') ?></span></td>
          <td><?= e($r['color'] ?: '—') ?></td>
          <td><code><?= e($r['numero_serie'] ?: '—') ?></code></td>
          <td><span class="badge badge-info"><?= (int)$r['total_manten'] ?> servicio(s)</span></td>
          <td><span class="badge badge-<?= $r['activa'] ? 'success' : 'danger' ?>"><?= $r['activa'] ? 'Activa' : 'Inactiva' ?></span></td>
          <td>
            <div class="actions-cell">
              <a class="btn btn-primary btn-sm" href="reportar.php?id_bicicleta=<?= (int)$r['id_bicicleta'] ?>" title="Reportar falla">
                <span class="material-symbols-outlined">report</span> Reportar
              </a>
              <button class="icon-btn" title="Editar información" onclick='editBikeClient(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                <span class="material-symbols-outlined">edit</span>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="8" class="empty">
            <span class="material-symbols-outlined">electric_bike</span><br>
            Aún no has registrado ninguna bicicleta.<br>
            <button class="btn btn-primary btn-sm" type="button" onclick="openNewBikeModal()" style="margin-top:10px">Registrar mi eBike ahora</button>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Modal Registro / Edición Bicicleta Cliente -->
<div class="modal-backdrop" id="bikeClientModal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="modalBikeTitle">Registrar Bicicleta</h2>
      <button class="icon-btn" type="button" onclick="closeModal('bikeClientModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="mis_bicicletas.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" id="bikeAccion" value="crear">
      <input type="hidden" name="id_bicicleta" id="bikeId" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label>Código / Placa o Identificador <span class="required">*</span></label>
            <input class="input" name="codigo" id="bikeCodigo" required placeholder="Ej. EBK-2026, MI-BICI-01" maxlength="50">
            <small class="muted">Identificador único que se usará para el taller.</small>
          </div>
          <div class="field">
            <label>Modelo de la bicicleta <span class="required">*</span></label>
            <input class="input" name="modelo" id="bikeModelo" required placeholder="Ej. City Lite 350W, Mountain X" maxlength="120">
          </div>
          <div class="field">
            <label>Marca</label>
            <input class="input" name="marca" id="bikeMarca" value="eBike" placeholder="Ej. eBike, Specialized, Trek">
          </div>
          <div class="field">
            <label>Tipo de Bicicleta</label>
            <select class="select" name="tipo_bicicleta" id="bikeTipo">
              <?php foreach (BIKE_TYPES as $bt): ?>
                <option value="<?= e($bt) ?>"><?= e($bt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Color</label>
            <input class="input" name="color" id="bikeColor" placeholder="Ej. Negro mate con verde">
          </div>
          <div class="field">
            <label>Número de Serie / Chasis</label>
            <input class="input" name="numero_serie" id="bikeSerie" placeholder="Grabado en el cuadro / motor">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('bikeClientModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Bicicleta</button>
      </div>
    </form>
  </div>
</div>

<script>
function openNewBikeModal(){
  document.getElementById('modalBikeTitle').textContent = 'Registrar Nueva Bicicleta';
  document.getElementById('bikeAccion').value = 'crear';
  document.getElementById('bikeId').value = '';
  document.getElementById('bikeCodigo').readOnly = false;
  document.getElementById('bikeCodigo').value = '';
  document.getElementById('bikeModelo').value = '';
  document.getElementById('bikeMarca').value = 'eBike';
  document.getElementById('bikeColor').value = '';
  document.getElementById('bikeSerie').value = '';
  openModal('bikeClientModal');
}
function editBikeClient(b){
  document.getElementById('modalBikeTitle').textContent = 'Editar Bicicleta ' + b.codigo;
  document.getElementById('bikeAccion').value = 'editar';
  document.getElementById('bikeId').value = b.id_bicicleta;
  document.getElementById('bikeCodigo').value = b.codigo;
  document.getElementById('bikeCodigo').readOnly = true;
  document.getElementById('bikeModelo').value = b.modelo;
  document.getElementById('bikeMarca').value = b.marca || 'eBike';
  document.getElementById('bikeTipo').value = b.tipo_bicicleta || 'Urbana';
  document.getElementById('bikeColor').value = b.color || '';
  document.getElementById('bikeSerie').value = b.numero_serie || '';
  openModal('bikeClientModal');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
