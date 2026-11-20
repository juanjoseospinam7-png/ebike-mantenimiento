<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();

$pageTitle = 'Mantenimientos';
$pdo = db();

$search = trim($_GET['buscar'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$tipo = trim($_GET['tipo'] ?? '');
$tecnicoFiltro = post_int('id_tecnico', null);
if (!$tecnicoFiltro && isset($_GET['tecnico'])) $tecnicoFiltro = (int)$_GET['tecnico'];

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(CAST(m.id_manten AS CHAR) LIKE ? OR b.codigo LIKE ? OR b.modelo LIKE ? OR b.cliente LIKE ? OR e.nombre LIKE ? OR m.diagnostico LIKE ? OR m.tipo_manten LIKE ? OR r.codigo_seguimiento LIKE ?)";
    for ($i = 0; $i < 8; $i++) $params[] = "%$search%";
}
if ($estado !== '') {
    $where[] = "m.estado = ?";
    $params[] = $estado;
}
if ($tipo !== '') {
    $where[] = "m.tipo_manten = ?";
    $params[] = $tipo;
}
if (is_admin() && $tecnicoFiltro) {
    $where[] = "m.id_tecnico = ?";
    $params[] = $tecnicoFiltro;
}

if (is_mechanic()) {
    $where[] = "m.id_tecnico = ?";
    $params[] = (int)(current_user()['id_empleado'] ?? 0);
}

$sql = "
    SELECT m.*, 
           b.codigo, b.modelo, b.marca, b.cliente, b.telefono_cliente,
           e.nombre AS tecnico, e.cargo AS cargo_tecnico,
           r.codigo_seguimiento, r.nombre_cliente, r.descripcion AS desc_reporte, r.categoria_problema
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    LEFT JOIN reportes_clientes r ON r.id_reporte = m.id_reporte_cliente
";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY m.id_manten DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Cargar repuestos asociados a cada mantenimiento
if ($rows) {
    $ids = array_map(fn($x) => (int)$x['id_manten'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ps = $pdo->prepare("
        SELECT mr.id_manten, mr.id_repuesto, mr.cantidad, mr.precio_unitario, p.nombre_rep, p.codigo_rep
        FROM mantenimiento_repuestos mr
        INNER JOIN inventario_costos_arreglos p ON p.id_repuesto = mr.id_repuesto
        WHERE mr.id_manten IN ($ph)
        ORDER BY p.nombre_rep
    ");
    $ps->execute($ids);
    $byM = [];
    $byD = [];
    foreach ($ps->fetchAll() as $pr) {
        $byM[(int)$pr['id_manten']][] = $pr['nombre_rep'] . ' x' . $pr['cantidad'];
        $byD[(int)$pr['id_manten']][] = [
            'id_repuesto'     => (int)$pr['id_repuesto'],
            'cantidad'        => (int)$pr['cantidad'],
            'precio_unitario' => (float)$pr['precio_unitario'],
            'nombre_rep'      => $pr['nombre_rep']
        ];
    }
    foreach ($rows as &$rr) {
        $rr['repuestos_lista'] = $byM[(int)$rr['id_manten']] ?? [];
        $rr['repuestos_detalle'] = $byD[(int)$rr['id_manten']] ?? [];
    }
    unset($rr);
}

// Catálogos para formularios
if (is_admin()) {
    $employees = $pdo->query("SELECT id_empleado, nombre, cargo, especialidad FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll();
} else {
    $es = $pdo->prepare("SELECT id_empleado, nombre, cargo, especialidad FROM empleados WHERE id_empleado = ? AND activo=1");
    $es->execute([(int)(current_user()['id_empleado'] ?? 0)]);
    $employees = $es->fetchAll();
}

$bikes = $pdo->query("SELECT id_bicicleta, codigo, modelo, marca, cliente FROM bicicletas WHERE activa=1 ORDER BY codigo")->fetchAll();
$parts = $pdo->query("SELECT id_repuesto, codigo_rep, nombre_rep, cantidad_disponible, precio_actual FROM inventario_costos_arreglos WHERE activo=1 ORDER BY nombre_rep")->fetchAll();
$partData = [];
foreach ($parts as $p) {
    $partData[(int)$p['id_repuesto']] = $p;
}

// Comprobar si se pasa un reporte para atender
$openReport = null;
if (!empty($_GET['reporte'])) {
    $rs = $pdo->prepare("SELECT r.*, b.codigo, b.modelo, b.marca FROM reportes_clientes r INNER JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta WHERE r.id_reporte = ?");
    $rs->execute([(int)$_GET['reporte']]);
    $openReport = $rs->fetch() ?: null;
}

// Comprobar si se pasa una orden para editar
$editMaintenance = null;
if (!empty($_GET['editar'])) {
    $idEdit = (int)$_GET['editar'];
    $es = $pdo->prepare("
        SELECT m.*, b.codigo, b.modelo, b.marca, b.cliente, b.telefono_cliente, e.nombre AS tecnico
        FROM mantenimientos m
        LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
        LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
        WHERE m.id_manten = ?
    ");
    $es->execute([$idEdit]);
    $editMaintenance = $es->fetch() ?: null;

    if ($editMaintenance && is_mechanic() && (int)$editMaintenance['id_tecnico'] !== (int)(current_user()['id_empleado'] ?? 0)) {
        $editMaintenance = null;
        flash('error', 'No puedes editar un mantenimiento asignado a otro mecánico.');
    }

    if ($editMaintenance) {
        $eds = $pdo->prepare("SELECT mr.id_repuesto, mr.cantidad, mr.precio_unitario FROM mantenimiento_repuestos mr WHERE mr.id_manten = ?");
        $eds->execute([$idEdit]);
        $editMaintenance['repuestos_detalle'] = $eds->fetchAll();
    }
}

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">ÓRDENES DE TRABAJO TÉCNICO</span>
    <h1>Mantenimientos de eBikes</h1>
    <p>Registra diagnósticos, trabajos realizados, control de repuestos, cálculo de mano de obra y estados de entrega.</p>
  </div>
  <div class="actions">
    <?php if (is_admin()): ?>
      <a class="btn btn-light" href="api/exportar.php" title="Descargar reporte en formato Excel / CSV">
        <span class="material-symbols-outlined">download</span>Exportar Excel / CSV
      </a>
    <?php endif; ?>
    <button class="btn btn-primary" type="button" onclick="openNewModal()">
      <span class="material-symbols-outlined">add</span><?= is_admin() ? 'Nuevo Mantenimiento' : 'Registrar Mantenimiento' ?>
    </button>
  </div>
</div>

<section class="panel">
  <!-- Filtros de búsqueda -->
  <form class="filters" method="get">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por ID, bicicleta, cliente, diagnóstico o técnico...">
    <select class="select" name="estado">
      <option value="">Todos los estados (9 normativos)</option>
      <?php foreach (MAINTENANCE_STATES as $st): ?>
        <option value="<?= e($st) ?>" <?= ($estado === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="tipo">
      <option value="">Todos los tipos</option>
      <option <?= $tipo==='Preventivo'?'selected':'' ?>>Preventivo</option>
      <option <?= $tipo==='Correctivo'?'selected':'' ?>>Correctivo</option>
      <option <?= $tipo==='Garantía'?'selected':'' ?>>Garantía</option>
      <option <?= $tipo==='Diagnóstico'?'selected':'' ?>>Diagnóstico</option>
      <option <?= $tipo==='Instalación de accesorios'?'selected':'' ?>>Instalación de accesorios</option>
    </select>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Filtrar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Historial de Órdenes de Mantenimiento</h2>
      <p><?= count($rows) ?> orden(es) registrada(s).</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Bicicleta / Cliente</th>
          <th>Técnico</th>
          <th>Fecha Ingreso / Salida</th>
          <th>Tipo</th>
          <th>Prioridad</th>
          <th>Estado Actual</th>
          <th>Repuestos Instalados</th>
          <th>Mano Obra</th>
          <th>Total Facturado</th>
          <th>Pago</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="id">
            #<?= e($r['id_manten']) ?>
            <?php if (!empty($r['codigo_seguimiento'])): ?>
              <br><small style="font-family:monospace;color:var(--green-700)"><?= e($r['codigo_seguimiento']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <span class="bike-name"><?= e($r['codigo'] ?: '—') ?></span><br>
            <span class="muted"><?= e($r['modelo'] ?: '') ?> · <?= e($r['cliente'] ?: ($r['nombre_cliente'] ?: 'Cliente taller')) ?></span>
          </td>
          <td><?= e($r['tecnico'] ?: 'ID ' . $r['id_tecnico']) ?></td>
          <td>
            <?= format_date($r['fecha_ingreso']) ?>
            <?php if (!empty($r['fecha_salida'])): ?>
              <br><span class="muted" style="color:var(--green-800)">Salida: <?= format_date($r['fecha_salida']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e($r['tipo_manten']) ?></td>
          <td>
            <?php
              $prioClass = match($r['prioridad']) {
                  'urgente' => 'danger',
                  'alta' => 'warning',
                  'baja' => 'neutral',
                  default => 'info'
              };
            ?>
            <span class="badge badge-<?= $prioClass ?>"><?= ucfirst(e($r['prioridad'] ?: 'media')) ?></span>
          </td>
          <td>
            <span class="badge badge-<?= status_class($r['estado']) ?>" style="font-weight:700">
              <?= e($r['estado'] ?: 'Recibido') ?>
            </span>
          </td>
          <td>
            <?php if (!empty($r['repuestos_lista'])): ?>
              <span style="font-size:11px"><?= e(implode(', ', $r['repuestos_lista'])) ?></span>
            <?php else: ?>
              <span class="muted">Sin repuestos</span>
            <?php endif; ?>
          </td>
          <td><?= money($r['mano_obra']) ?></td>
          <td><b><?= money($r['costo']) ?></b></td>
          <td>
            <span class="badge badge-<?= ($r['estado_pago'] === 'pagado') ? 'success' : 'warning' ?>">
              <?= ucfirst(e($r['estado_pago'] ?: 'Pendiente')) ?>
            </span>
          </td>
          <td>
            <div class="actions-cell">
              <button class="icon-btn" title="Ver ficha técnica" onclick='showMaintenanceDetail(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                <span class="material-symbols-outlined">visibility</span>
              </button>
              <button class="icon-btn" title="Editar orden" onclick='editOrder(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                <span class="material-symbols-outlined">edit</span>
              </button>
              <a class="icon-btn" title="Imprimir Orden / Comprobante" href="imprimir_orden.php?id=<?= (int)$r['id_manten'] ?>" target="_blank">
                <span class="material-symbols-outlined">print</span>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="12" class="empty">
            <span class="material-symbols-outlined">build</span><br>
            No se encontraron órdenes de mantenimiento registradas.
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- MODAL CREACIÓN / EDICIÓN DE MANTENIMIENTO -->
<div class="modal-backdrop <?= (isset($_GET['nuevo']) || $openReport || $editMaintenance) ? 'show' : '' ?>" id="maintenanceModal">
  <div class="modal" style="width:min(880px, 98%)">
    <div class="modal-head">
      <h2 id="modalTitle">Nuevo Mantenimiento</h2>
      <button class="icon-btn" type="button" onclick="closeModal('maintenanceModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/mantenimiento.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" id="accion" value="crear">
      <input type="hidden" name="id_manten" id="id_manten" value="">
      <input type="hidden" name="id_reporte_cliente" id="id_reporte_cliente" value="<?= $openReport ? (int)$openReport['id_reporte'] : '' ?>">

      <div class="modal-body">
        <?php if ($openReport): ?>
          <div class="report-banner">
            <span class="material-symbols-outlined" style="font-size:28px">support_agent</span>
            <div>
              <strong>Vinculado a Reporte de Cliente #<?= (int)$openReport['id_reporte'] ?> (<?= e($openReport['codigo_seguimiento'] ?: '') ?>)</strong>
              <div><b>Cliente:</b> <?= e($openReport['nombre_cliente']) ?> · Tel: <?= e($openReport['telefono_cliente'] ?: 'Sin teléfono') ?></div>
              <div><b>Falla reportada:</b> <?= e($openReport['descripcion']) ?> (Categoría: <?= e($openReport['categoria_problema']) ?>)</div>
            </div>
          </div>
        <?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label>Bicicleta <span class="required">*</span></label>
            <select class="select" name="id_bicicleta" id="id_bicicleta" required>
              <option value="">Buscar / seleccionar bicicleta...</option>
              <?php foreach ($bikes as $b): ?>
                <option value="<?= (int)$b['id_bicicleta'] ?>" <?= ($openReport && (int)$openReport['id_bicicleta'] === (int)$b['id_bicicleta']) ? 'selected' : '' ?>>
                  <?= e($b['codigo']) ?> · <?= e($b['marca']) ?> <?= e($b['modelo']) ?> (<?= e($b['cliente'] ?: 'Taller') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Mecánico Asignado <span class="required">*</span></label>
            <select class="select" name="id_tecnico" id="id_tecnico" required <?= is_mechanic() ? 'disabled' : '' ?>>
              <?php if (is_admin()): ?><option value="">Seleccionar mecánico...</option><?php endif; ?>
              <?php foreach ($employees as $e1): ?>
                <option value="<?= (int)$e1['id_empleado'] ?>" <?= ($openReport && (int)$openReport['id_tecnico'] === (int)$e1['id_empleado']) ? 'selected' : '' ?>>
                  <?= e($e1['nombre']) ?> · <?= e($e1['cargo']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (is_mechanic() && !empty($employees[0]['id_empleado'])): ?>
              <input type="hidden" name="id_tecnico" value="<?= (int)$employees[0]['id_empleado'] ?>">
            <?php endif; ?>
          </div>

          <div class="field">
            <label>Tipo de Mantenimiento <span class="required">*</span></label>
            <select class="select" name="tipo_manten" id="tipo_manten" required>
              <option>Preventivo</option>
              <option>Correctivo</option>
              <option>Garantía</option>
              <option>Diagnóstico</option>
              <option>Instalación de accesorios</option>
            </select>
          </div>

          <div class="field">
            <label>Estado del Servicio (9 Estados Normativos) <span class="required">*</span></label>
            <select class="select" name="estado" id="estado" required>
              <?php foreach (MAINTENANCE_STATES as $st): ?>
                <option value="<?= e($st) ?>"><?= e($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Prioridad de Atención</label>
            <select class="select" name="prioridad" id="prioridad">
              <option value="baja">Baja</option>
              <option value="media" selected>Media</option>
              <option value="alta">Alta</option>
              <option value="urgente">Urgente</option>
            </select>
          </div>

          <div class="field">
            <label>Estado del Pago</label>
            <select class="select" name="estado_pago" id="estado_pago">
              <option value="pendiente">Pendiente de pago</option>
              <option value="pagado">Pagado</option>
              <option value="cancelado">Cancelado</option>
            </select>
          </div>

          <div class="field full">
            <label>Diagnóstico Técnico <span class="required">*</span></label>
            <textarea class="textarea" name="diagnostico" id="diagnostico" required placeholder="Describe la causa técnica de la falla encontrada en la eBike..."></textarea>
          </div>

          <div class="field full">
            <label>Trabajos Realizados</label>
            <textarea class="textarea" name="trabajos_realizados" id="trabajos_realizados" style="min-height:75px" placeholder="Detalle de los ajustes mecánicos, cableado, reemplazos, calibración de motor o frenos..."></textarea>
          </div>

          <div class="field full">
            <label>Observaciones / Recomendaciones para el Cliente</label>
            <textarea class="textarea" name="observaciones" id="observaciones" style="min-height:65px" placeholder="Recomendaciones de carga de batería, presión de neumáticos o cuidados..."></textarea>
          </div>

          <!-- Selección Múltiple de Repuestos -->
          <div class="field full" style="background:#fcfefd;padding:16px;border:1px solid var(--line);border-radius:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
              <label style="margin:0;font-size:13px;font-weight:700">Repuestos del Inventario Utilizados</label>
              <button class="btn btn-light btn-sm" type="button" onclick="addPartRow()">
                <span class="material-symbols-outlined">add</span>Agregar Repuesto
              </button>
            </div>
            <div id="partsRows" class="parts-rows"></div>
            <small class="muted">El stock se descuenta automáticamente en tiempo real. No se admiten existencias negativas.</small>
          </div>

          <div class="field">
            <label>Precio Mano de Obra (COP)</label>
            <input class="input" type="number" step="1000" min="0" name="mano_obra" id="mano_obra" value="0">
          </div>

          <div class="field">
            <label>Subtotal Repuestos (COP)</label>
            <input class="input" type="text" id="total_repuestos" value="$ 0 COP" readonly style="background:#f4f6f5">
          </div>

          <div class="field">
            <label>Descuento Especial (COP)</label>
            <input class="input" type="number" step="1000" min="0" name="descuento" id="descuento" value="0">
          </div>

          <div class="field">
            <label>Total del Servicio (COP) <span class="required">*</span></label>
            <input class="input" type="number" step="1" min="0" name="costo" id="costo" required value="0" readonly style="font-weight:700;font-size:15px;background:#eef8f2;color:var(--green-900)">
          </div>

          <div class="field">
            <label>Método de Pago</label>
            <select class="select" name="metodo_pago" id="metodo_pago">
              <option value="Efectivo">Efectivo</option>
              <option value="Transferencia Bancaria">Transferencia Bancaria</option>
              <option value="Nequi / Daviplata">Nequi / Daviplata</option>
              <option value="Tarjeta de Crédito / Débito">Tarjeta de Crédito / Débito</option>
              <option value="Pendiente">Pendiente de definir</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('maintenanceModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Orden de Mantenimiento</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Detalle Ficha Técnica -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal" style="width:min(720px, 96%)">
    <div class="modal-head">
      <h2>Ficha Técnica del Mantenimiento</h2>
      <button class="icon-btn" onclick="closeModal('detailModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body" id="detailContent"></div>
    <div class="modal-foot">
      <button class="btn btn-light" type="button" onclick="closeModal('detailModal')">Cerrar</button>
      <a class="btn btn-primary" id="btnImprimirModal" href="#" target="_blank">
        <span class="material-symbols-outlined">print</span>Imprimir Ficha
      </a>
    </div>
  </div>
</div>

<script>
const partData = <?= json_encode($partData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

function clearParts(){
  document.getElementById('partsRows').innerHTML = '';
}

function addPartRow(id='', qty=1){
  const wrap = document.getElementById('partsRows');
  const row = document.createElement('div');
  row.className = 'part-row';
  row.innerHTML = `
    <div class="part-select-wrap">
      <select class="select part-select" name="repuestos[]" required>
        <option value="">Seleccionar repuesto...</option>
        ${Object.values(partData).map(p => `
          <option value="${p.id_repuesto}" data-price="${p.precio_actual}" data-stock="${p.cantidad_disponible}" ${String(p.id_repuesto)===String(id)?'selected':''}>
            ${esc(p.codigo_rep || ('REP-'+p.id_repuesto))} · ${esc(p.nombre_rep)} · Stock: ${p.cantidad_disponible} · ${moneyFmt(p.precio_actual)}
          </option>
        `).join('')}
      </select>
    </div>
    <input class="input part-qty" type="number" name="cantidades[]" min="1" value="${qty}" title="Cantidad">
    <button class="icon-btn" type="button" title="Quitar repuesto" onclick="this.closest('.part-row').remove();recalcTotal()">
      <span class="material-symbols-outlined">delete</span>
    </button>
  `;
  wrap.appendChild(row);
  enhanceSearchable(row.querySelector('.part-select'));
  recalcTotal();
}

function moneyFmt(v){
  return new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP', maximumFractionDigits: 0}).format(Number(v||0));
}

function recalcTotal(){
  let partsTotal = 0;
  document.querySelectorAll('#partsRows .part-row').forEach(row => {
    const sel = row.querySelector('.part-select');
    const qty = Number(row.querySelector('.part-qty').value || 1);
    const price = Number(sel?.selectedOptions[0]?.dataset.price || 0);
    partsTotal += (price * qty);
  });

  document.getElementById('total_repuestos').value = moneyFmt(partsTotal);
  const labor = Number(document.getElementById('mano_obra').value || 0);
  const discount = Number(document.getElementById('descuento').value || 0);
  const grandTotal = Math.max(0, partsTotal + labor - discount);
  document.getElementById('costo').value = grandTotal.toFixed(2);
}

function openNewModal(){
  document.getElementById('modalTitle').textContent = 'Nuevo Mantenimiento';
  document.getElementById('accion').value = 'crear';
  document.getElementById('id_manten').value = '';
  document.getElementById('id_reporte_cliente').value = '';
  document.getElementById('mano_obra').value = '0';
  document.getElementById('descuento').value = '0';
  document.getElementById('diagnostico').value = '';
  document.getElementById('trabajos_realizados').value = '';
  document.getElementById('observaciones').value = '';
  clearParts();
  recalcTotal();
  openModal('maintenanceModal');
}

function editOrder(r){
  document.getElementById('modalTitle').textContent = 'Editar Mantenimiento #' + r.id_manten;
  document.getElementById('accion').value = 'editar';
  document.getElementById('id_manten').value = r.id_manten;
  document.getElementById('id_bicicleta').value = r.id_bicicleta || '';
  document.getElementById('id_tecnico').value = r.id_tecnico || '';
  document.getElementById('tipo_manten').value = r.tipo_manten || 'Preventivo';
  document.getElementById('estado').value = r.estado || 'Recibido';
  document.getElementById('prioridad').value = r.prioridad || 'media';
  document.getElementById('estado_pago').value = r.estado_pago || 'pendiente';
  document.getElementById('metodo_pago').value = r.metodo_pago || 'Efectivo';
  document.getElementById('diagnostico').value = r.diagnostico || '';
  document.getElementById('trabajos_realizados').value = r.trabajos_realizados || '';
  document.getElementById('observaciones').value = r.observaciones || '';
  document.getElementById('mano_obra').value = r.mano_obra || 0;
  document.getElementById('descuento').value = r.descuento || 0;
  document.getElementById('id_reporte_cliente').value = r.id_reporte_cliente || '';

  syncSearchableSelect('id_bicicleta');
  syncSearchableSelect('id_tecnico');
  syncSearchableSelect('tipo_manten');
  syncSearchableSelect('estado');

  clearParts();
  if (Array.isArray(r.repuestos_detalle) && r.repuestos_detalle.length) {
    r.repuestos_detalle.forEach(x => addPartRow(x.id_repuesto, x.cantidad));
  }
  recalcTotal();
  openModal('maintenanceModal');
}

function showMaintenanceDetail(r){
  document.getElementById('btnImprimirModal').href = 'imprimir_orden.php?id=' + r.id_manten;
  document.getElementById('detailContent').innerHTML = `
    <div class="detail-grid">
      <div class="detail"><label>Orden de Servicio</label><strong>#${r.id_manten}</strong></div>
      <div class="detail"><label>Estado</label><strong class="badge badge-${statusColor(r.estado)}">${esc(r.estado||'Recibido')}</strong></div>
      <div class="detail"><label>Bicicleta</label><strong>${esc(r.codigo||'—')} · ${esc(r.modelo||'')}</strong></div>
      <div class="detail"><label>Cliente</label><strong>${esc(r.cliente||r.nombre_cliente||'—')}</strong></div>
      <div class="detail"><label>Mecánico Responsable</label><strong>${esc(r.tecnico||('ID '+r.id_tecnico))}</strong></div>
      <div class="detail"><label>Tipo de Mantenimiento</label><strong>${esc(r.tipo_manten||'—')}</strong></div>
      <div class="detail"><label>Fecha Ingreso</label><strong>${esc(r.fecha_ingreso||'—')}</strong></div>
      <div class="detail"><label>Fecha Salida</label><strong>${esc(r.fecha_salida||'Pendiente')}</strong></div>
      <div class="detail" style="grid-column:1/-1"><label>Diagnóstico</label><strong>${esc(r.diagnostico||'—')}</strong></div>
      <div class="detail" style="grid-column:1/-1"><label>Trabajos Realizados</label><strong>${esc(r.trabajos_realizados||'—')}</strong></div>
      <div class="detail"><label>Mano de Obra</label><strong>${moneyFmt(r.mano_obra||0)}</strong></div>
      <div class="detail"><label>Total Facturado</label><strong>${moneyFmt(r.costo||0)}</strong></div>
      <div class="detail"><label>Estado Pago</label><strong>${esc(r.estado_pago||'Pendiente')} (${esc(r.metodo_pago||'Efectivo')})</strong></div>
      <div class="detail"><label>Repuestos</label><strong>${r.repuestos_lista?.length ? esc(r.repuestos_lista.join(', ')) : 'Ninguno'}</strong></div>
    </div>
  `;
  openModal('detailModal');
}

function statusColor(st){
  const s = String(st).toLowerCase();
  if(['reparado','entregado','completado','listo'].some(x=>s.includes(x))) return 'success';
  if(['diagnóstico','reparación','pruebas','proceso'].some(x=>s.includes(x))) return 'info';
  if(['pendiente','esperando','recibido'].some(x=>s.includes(x))) return 'warning';
  if(['cancelado'].some(x=>s.includes(x))) return 'danger';
  return 'neutral';
}

function esc(v){
  return String(v).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

document.addEventListener('input', e => {
  if (['mano_obra', 'descuento'].includes(e.target.id) || e.target.classList.contains('part-qty')) recalcTotal();
});
document.addEventListener('change', e => {
  if (e.target.classList.contains('part-select')) recalcTotal();
});

window.addEventListener('DOMContentLoaded', () => {
  <?php if ($editMaintenance): ?>
    editOrder(<?= json_encode($editMaintenance, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>);
  <?php elseif ($openReport): ?>
    document.getElementById('modalTitle').textContent = 'Atender Reporte de Cliente #<?= (int)$openReport['id_reporte'] ?>';
    document.getElementById('id_bicicleta').value = '<?= (int)$openReport['id_bicicleta'] ?>';
    document.getElementById('tipo_manten').value = 'Correctivo';
    document.getElementById('estado').value = 'En diagnóstico';
    document.getElementById('diagnostico').value = <?= json_encode($openReport['descripcion']) ?>;
    document.getElementById('observaciones').value = <?= json_encode($openReport['observaciones'] ?? '') ?>;
    syncSearchableSelect('id_bicicleta');
    syncSearchableSelect('tipo_manten');
    syncSearchableSelect('estado');
    clearParts();
    recalcTotal();
    openModal('maintenanceModal');
  <?php endif; ?>
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
