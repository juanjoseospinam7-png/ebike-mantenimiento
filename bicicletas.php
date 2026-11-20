<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();

$pageTitle = 'Flota de Bicicletas';
$pdo = db();

$search = trim($_GET['buscar'] ?? '');
$filterTipo = trim($_GET['tipo'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(b.codigo LIKE ? OR b.modelo LIKE ? OR b.marca LIKE ? OR b.cliente LIKE ? OR b.telefono_cliente LIKE ? OR b.numero_serie LIKE ?)";
    for ($i = 0; $i < 6; $i++) $params[] = "%$search%";
}
if ($filterTipo !== '') {
    $where[] = "b.tipo_bicicleta = ?";
    $params[] = $filterTipo;
}

$sql = "
    SELECT b.*, 
           u.usuario AS usuario_cliente,
           COUNT(m.id_manten) AS total_manten,
           MAX(m.fecha_ingreso) AS ultimo_servicio
    FROM bicicletas b
    LEFT JOIN usuarios u ON u.id = b.id_usuario
    LEFT JOIN mantenimientos m ON m.id_bicicleta = b.id_bicicleta
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " GROUP BY b.id_bicicleta ORDER BY b.id_bicicleta DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$clientes = $pdo->query("SELECT id, nombre, usuario, telefono FROM usuarios WHERE rol='cliente' AND activo=1 ORDER BY nombre")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">VEHÍCULOS & CLIENTES</span>
    <h1>Flota de Bicicletas Eléctricas</h1>
    <p>Consulta las bicicletas registradas, especificaciones técnicas y su historial de órdenes de mantenimiento.</p>
  </div>
  <?php if (is_admin()): ?>
    <button class="btn btn-primary" type="button" onclick="openNewBikeModal()">
      <span class="material-symbols-outlined">add</span>Nueva Bicicleta
    </button>
  <?php endif; ?>
</div>

<section class="panel">
  <!-- Filtros -->
  <form class="filters" method="get" style="grid-template-columns:2fr 1fr auto">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por código, modelo, marca, cliente, teléfono o serie...">
    <select class="select" name="tipo">
      <option value="">Todos los tipos de eBike</option>
      <?php foreach (BIKE_TYPES as $bt): ?>
        <option value="<?= e($bt) ?>" <?= ($filterTipo === $bt) ? 'selected' : '' ?>><?= e($bt) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Filtrar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Bicicletas Registradas en el Taller</h2>
      <p><?= count($rows) ?> bicicleta(s) en la base de datos.</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Código</th>
          <th>Marca & Modelo</th>
          <th>Tipo</th>
          <th>Color / N° Serie</th>
          <th>Cliente Propietario</th>
          <th>Teléfono Contacto</th>
          <th>Mantenimientos</th>
          <th>Último Servicio</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="id"><b style="font-size:14px"><?= e($r['codigo']) ?></b></td>
          <td><b><?= e($r['marca']) ?></b> <?= e($r['modelo']) ?></td>
          <td><span class="badge badge-neutral"><?= e($r['tipo_bicicleta'] ?: 'Urbana') ?></span></td>
          <td>
            <?= e($r['color'] ?: '—') ?><br>
            <small class="muted">Serie: <?= e($r['numero_serie'] ?: 'Sin serie') ?></small>
          </td>
          <td>
            <b><?= e($r['cliente'] ?: 'Sin cliente') ?></b>
            <?php if (!empty($r['usuario_cliente'])): ?>
              <br><small class="muted">Cuenta: @<?= e($r['usuario_cliente']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= e($r['telefono_cliente'] ?: '—') ?></td>
          <td>
            <a href="mantenimientos.php?buscar=<?= urlencode($r['codigo']) ?>" style="font-weight:700;color:var(--green-700)">
              <span class="badge badge-info"><?= (int)$r['total_manten'] ?> orden(es)</span>
            </a>
          </td>
          <td><?= format_date($r['ultimo_servicio']) ?></td>
          <td>
            <span class="badge badge-<?= $r['activa'] ? 'success' : 'danger' ?>">
              <?= $r['activa'] ? 'Activa' : 'Inactiva' ?>
            </span>
          </td>
          <td>
            <div class="actions-cell">
              <a class="btn btn-light btn-sm" href="mantenimientos.php?buscar=<?= urlencode($r['codigo']) ?>" title="Ver historial de mantenimientos">
                <span class="material-symbols-outlined">history</span>
              </a>
              <?php if (is_admin()): ?>
                <button class="icon-btn" title="Editar bicicleta" onclick='editBike(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                  <span class="material-symbols-outlined">edit</span>
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="empty">No se encontraron bicicletas.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- MODAL CREACIÓN / EDICIÓN BICICLETA -->
<div class="modal-backdrop <?= isset($_GET['nuevo']) ? 'show' : '' ?>" id="bikeModal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="bikeTitle">Nueva Bicicleta</h2>
      <button class="icon-btn" onclick="closeModal('bikeModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/bicicleta.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" id="bikeAccion" value="crear">
      <input type="hidden" name="id_bicicleta" id="bikeId">

      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label>Código / Placa Identificadora <span class="required">*</span></label>
            <input class="input" name="codigo" id="codigo" required maxlength="50" placeholder="Ej. EBK-1095">
          </div>

          <div class="field">
            <label>Modelo de la eBike <span class="required">*</span></label>
            <input class="input" name="modelo" id="modelo" required maxlength="120" placeholder="Ej. Trail Pro 500W">
          </div>

          <div class="field">
            <label>Marca</label>
            <input class="input" name="marca" id="marca" value="eBike" placeholder="eBike, Specialized, Trek">
          </div>

          <div class="field">
            <label>Tipo de Bicicleta</label>
            <select class="select" name="tipo_bicicleta" id="tipo_bicicleta">
              <?php foreach (BIKE_TYPES as $bt): ?>
                <option value="<?= e($bt) ?>"><?= e($bt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Vincular a Cuenta de Cliente (Opcional)</label>
            <select class="select" name="id_usuario" id="id_usuario" onchange="autoFillCustomerInfo(this)">
              <option value="">Seleccionar cliente registrado o escribir manual...</option>
              <?php foreach ($clientes as $c): ?>
                <option value="<?= (int)$c['id'] ?>" data-name="<?= e($c['nombre']) ?>" data-phone="<?= e($c['telefono']) ?>">
                  <?= e($c['nombre']) ?> (@<?= e($c['usuario']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Nombre del Cliente</label>
            <input class="input" name="cliente" id="cliente" placeholder="Nombre completo del cliente">
          </div>

          <div class="field">
            <label>Teléfono del Cliente</label>
            <input class="input" name="telefono_cliente" id="telefono_cliente" placeholder="300 000 0000">
          </div>

          <div class="field">
            <label>Color</label>
            <input class="input" name="color" id="color" placeholder="Ej. Negro mate con verde">
          </div>

          <div class="field">
            <label>Número de Serie / Chasis</label>
            <input class="input" name="numero_serie" id="numero_serie" placeholder="Grabado en el cuadro">
          </div>

          <div class="field">
            <label>Estado</label>
            <select class="select" name="activa" id="activa">
              <option value="1">Activa</option>
              <option value="0">Inactiva</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('bikeModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Bicicleta</button>
      </div>
    </form>
  </div>
</div>

<script>
function autoFillCustomerInfo(sel){
  const opt = sel.selectedOptions[0];
  if(opt && opt.value){
    document.getElementById('cliente').value = opt.dataset.name || '';
    document.getElementById('telefono_cliente').value = opt.dataset.phone || '';
  }
}

function openNewBikeModal(){
  document.getElementById('bikeTitle').textContent = 'Nueva Bicicleta';
  document.getElementById('bikeAccion').value = 'crear';
  document.getElementById('bikeId').value = '';
  ['codigo','modelo','cliente','telefono_cliente','color','numero_serie'].forEach(k => document.getElementById(k).value = '');
  document.getElementById('marca').value = 'eBike';
  document.getElementById('id_usuario').value = '';
  document.getElementById('activa').value = '1';
  openModal('bikeModal');
}

function editBike(r){
  document.getElementById('bikeTitle').textContent = 'Editar Bicicleta ' + r.codigo;
  document.getElementById('bikeAccion').value = 'editar';
  document.getElementById('bikeId').value = r.id_bicicleta;
  ['codigo','modelo','marca','tipo_bicicleta','cliente','telefono_cliente','id_usuario','color','numero_serie','activa'].forEach(k => {
    const el = document.getElementById(k);
    if(el) el.value = r[k] ?? '';
  });
  syncSearchableSelect('tipo_bicicleta');
  syncSearchableSelect('id_usuario');
  syncSearchableSelect('activa');
  openModal('bikeModal');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
