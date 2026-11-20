<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();

$pageTitle = 'Inventario & Repuestos';
$pdo = db();

$search = trim($_GET['buscar'] ?? '');
$filterCat = trim($_GET['categoria'] ?? '');
$filterStock = trim($_GET['stock'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(nombre_rep LIKE ? OR codigo_rep LIKE ? OR proveedor LIKE ? OR descripcion LIKE ?)";
    for ($i = 0; $i < 4; $i++) $params[] = "%$search%";
}
if ($filterCat !== '') {
    $where[] = "categoria = ?";
    $params[] = $filterCat;
}
if ($filterStock === 'bajo') {
    $where[] = "cantidad_disponible <= stock_minimo";
} elseif ($filterStock === 'agotado') {
    $where[] = "cantidad_disponible = 0";
}

$sql = "SELECT * FROM inventario_costos_arreglos";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY (cantidad_disponible <= stock_minimo) DESC, id_repuesto DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// KPIs
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM inventario_costos_arreglos WHERE activo=1")->fetchColumn();
$stockCritico = (int)$pdo->query("SELECT COUNT(*) FROM inventario_costos_arreglos WHERE cantidad_disponible <= stock_minimo AND activo=1")->fetchColumn();
$valorTotalInventario = (float)$pdo->query("SELECT COALESCE(SUM(cantidad_disponible * costo_repuestos), 0) FROM inventario_costos_arreglos WHERE activo=1")->fetchColumn();
$categorias = $pdo->query("SELECT DISTINCT categoria FROM inventario_costos_arreglos WHERE categoria IS NOT NULL ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// Últimos movimientos de kardex
$kardex = $pdo->query("
    SELECT k.*, p.nombre_rep, p.codigo_rep, u.nombre AS usuario_nombre
    FROM movimientos_inventario k
    INNER JOIN inventario_costos_arreglos p ON p.id_repuesto = k.id_repuesto
    LEFT JOIN usuarios u ON u.id = k.id_usuario
    ORDER BY k.id_movimiento DESC
    LIMIT 20
")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">CATÁLOGO TÉCNICO & CONTROL DE STOCK</span>
    <h1>Inventario y Repuestos para eBikes</h1>
    <p>Administra repuestos originales, proveedores, costos de adquisición, precios de venta y movimientos de kardex.</p>
  </div>
  <?php if (is_admin()): ?>
    <div class="actions">
      <button class="btn btn-primary" type="button" onclick="openNewPartModal()">
        <span class="material-symbols-outlined">add_box</span>Nuevo Repuesto
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- KPIs Inventario -->
<div class="kpis">
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Referencias Activas</div>
        <div class="kpi-value"><?= $totalItems ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">inventory_2</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Alertas de Stock Bajo</div>
        <div class="kpi-value" style="color:<?= $stockCritico > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $stockCritico ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">warning</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Valor en Bodega (Costo)</div>
        <div class="kpi-value" style="font-size:22px"><?= money($valorTotalInventario) ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">attach_money</span></div>
    </div>
  </div>
</div>

<section class="panel" style="margin-bottom:24px">
  <!-- Filtros -->
  <form class="filters" method="get" style="grid-template-columns:2fr 1fr 1fr auto">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por código, nombre de repuesto, proveedor o descripción...">
    <select class="select" name="categoria">
      <option value="">Todas las categorías</option>
      <?php foreach ($categorias as $cat): ?>
        <option value="<?= e($cat) ?>" <?= ($filterCat === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="stock">
      <option value="">Todos los niveles de stock</option>
      <option value="bajo" <?= ($filterStock === 'bajo') ? 'selected' : '' ?>>Stock bajo o crítico (≤ mínimo)</option>
      <option value="agotado" <?= ($filterStock === 'agotado') ? 'selected' : '' ?>>Agotados (0 unidades)</option>
    </select>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Filtrar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Catálogo de Repuestos y Componentes</h2>
      <p><?= count($rows) ?> repuesto(s) encontrado(s).</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Código</th>
          <th>Repuesto & Categoría</th>
          <th>Stock Actual</th>
          <th>Stock Mín.</th>
          <th>Costo Compra</th>
          <th>Precio Venta</th>
          <th>Mano Obra</th>
          <th>Proveedor</th>
          <th>Estado</th>
          <?php if (is_admin()): ?><th>Acciones</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php 
          $isLow = ((int)$r['cantidad_disponible'] <= (int)$r['stock_minimo']);
          $isOut = ((int)$r['cantidad_disponible'] <= 0);
        ?>
        <tr style="<?= $isOut ? 'background:#fff8f8' : ($isLow ? 'background:#fffdf6' : '') ?>">
          <td><code style="font-weight:700;color:var(--green-800)"><?= e($r['codigo_rep'] ?: ('REP-'.$r['id_repuesto'])) ?></code></td>
          <td>
            <b><?= e($r['nombre_rep']) ?></b><br>
            <span class="badge badge-neutral" style="font-size:10px"><?= e($r['categoria']) ?></span>
            <?php if (!empty($r['descripcion'])): ?>
              <small class="muted" style="display:block"><?= e(mb_strimwidth($r['descripcion'], 0, 40, '...')) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= $isOut ? 'danger' : ($isLow ? 'warning' : 'success') ?>" style="font-size:12px;font-weight:700">
              <?= (int)$r['cantidad_disponible'] ?> uds
            </span>
            <?php if ($isOut): ?>
              <small style="color:var(--danger);display:block;font-weight:700">¡Agotado!</small>
            <?php elseif ($isLow): ?>
              <small style="color:var(--warning);display:block;font-weight:700">¡Alerta stock bajo!</small>
            <?php endif; ?>
          </td>
          <td><?= (int)$r['stock_minimo'] ?> uds</td>
          <td><?= money($r['costo_repuestos']) ?></td>
          <td><b><?= money($r['precio_actual']) ?></b></td>
          <td><?= money($r['costo_mano_obra']) ?></td>
          <td><?= e($r['proveedor'] ?: '—') ?></td>
          <td>
            <span class="badge badge-<?= $r['activo'] ? 'success' : 'neutral' ?>">
              <?= $r['activo'] ? 'Activo' : 'Inactivo' ?>
            </span>
          </td>
          <?php if (is_admin()): ?>
            <td>
              <div class="actions-cell">
                <button class="btn btn-light btn-sm" type="button" title="Registrar Entrada / Compra" onclick='openStockInModal(<?= (int)$r['id_repuesto'] ?>, <?= json_encode($r['nombre_rep']) ?>, <?= (float)$r['costo_repuestos'] ?>, <?= json_encode($r['proveedor'] ?? '') ?>)'>
                  <span class="material-symbols-outlined">add_shopping_cart</span>
                </button>
                <button class="icon-btn" title="Editar Repuesto" onclick='editPart(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                  <span class="material-symbols-outlined">edit</span>
                </button>
              </div>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="empty">No se encontraron repuestos con los filtros aplicados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Kardex / Movimientos Recientes de Inventario -->
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Movimientos de Inventario (Kardex de Entradas y Salidas)</h2>
      <p>Historial auditable de compras, salidas por órdenes de mantenimiento y ajustes de stock.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Fecha y Hora</th>
          <th>Repuesto</th>
          <th>Tipo</th>
          <th>Cantidad</th>
          <th>Motivo / Orden</th>
          <th>Registrado Por</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($kardex as $k): ?>
        <tr>
          <td><?= format_date($k['fecha']) ?></td>
          <td><b><?= e($k['codigo_rep'] ?: ('REP-'.$k['id_repuesto'])) ?></b> · <?= e($k['nombre_rep']) ?></td>
          <td>
            <span class="badge badge-<?= $k['tipo'] === 'entrada' ? 'success' : 'danger' ?>">
              <?= $k['tipo'] === 'entrada' ? '+ ENTRADA' : '- SALIDA' ?>
            </span>
          </td>
          <td><b><?= (int)$k['cantidad'] ?> uds</b></td>
          <td><?= e($k['motivo']) ?></td>
          <td><?= e($k['usuario_nombre'] ?: 'Sistema') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kardex): ?>
        <tr><td colspan="6" class="empty">Aún no hay registros de movimientos en el Kardex.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- MODAL CREACIÓN / EDICIÓN REPUESTO -->
<div class="modal-backdrop <?= isset($_GET['nuevo']) ? 'show' : '' ?>" id="partModal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="partTitle">Nuevo Repuesto</h2>
      <button class="icon-btn" onclick="closeModal('partModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/inventario.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" id="partAccion" value="crear">
      <input type="hidden" name="id_repuesto" id="partId">

      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label>Código del Repuesto</label>
            <input class="input" name="codigo_rep" id="codigo_rep" placeholder="Ej. REP-0010 (Dejar vacío para automático)">
          </div>

          <div class="field">
            <label>Categoría</label>
            <select class="select" name="categoria" id="categoria">
              <?php foreach (PROBLEM_CATEGORIES as $pc): ?>
                <option value="<?= e($pc) ?>"><?= e($pc) ?></option>
              <?php endforeach; ?>
              <option value="Accesorios">Accesorios</option>
              <option value="Repuestos Generales">Repuestos Generales</option>
            </select>
          </div>

          <div class="field full">
            <label>Nombre del Repuesto <span class="required">*</span></label>
            <input class="input" name="nombre_rep" id="nombre_rep" required maxlength="100" placeholder="Ej. Batería 48V 17.5Ah celdas LG">
          </div>

          <div class="field full">
            <label>Descripción / Especificaciones Técnicas</label>
            <textarea class="textarea" name="descripcion" id="descripcion" style="min-height:65px" placeholder="Detalles de compatibilidad, voltaje, medidas..."></textarea>
          </div>

          <div class="field">
            <label>Cantidad Disponible en Stock</label>
            <input class="input" type="number" min="0" name="cantidad_disponible" id="cantidad_disponible" value="0">
          </div>

          <div class="field">
            <label>Stock Mínimo de Alerta</label>
            <input class="input" type="number" min="1" name="stock_minimo" id="stock_minimo" value="5">
          </div>

          <div class="field">
            <label>Costo de Adquisición / Compra (COP)</label>
            <input class="input" type="number" step="100" min="0" name="costo_repuestos" id="costo_repuestos" value="0">
          </div>

          <div class="field">
            <label>Precio de Venta al Cliente (COP) <span class="required">*</span></label>
            <input class="input" type="number" step="100" min="0" name="precio_actual" id="precio_actual" value="0" required>
          </div>

          <div class="field">
            <label>Mano de Obra Sugerida de Instalación (COP)</label>
            <input class="input" type="number" step="100" min="0" name="costo_mano_obra" id="costo_mano_obra" value="0">
          </div>

          <div class="field">
            <label>Proveedor Habitual</label>
            <input class="input" name="proveedor" id="proveedor" maxlength="100" placeholder="Ej. Shimano, Bafang, Samsung">
          </div>

          <div class="field" id="wrapActivo" style="display:none">
            <label>Estado</label>
            <select class="select" name="activo" id="partActivo">
              <option value="1">Activo</option>
              <option value="0">Inactivo</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('partModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Repuesto</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL ENTRADA DE STOCK / COMPRA -->
<div class="modal-backdrop" id="stockInModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-head">
      <h2>Entrada de Stock / Compra a Proveedor</h2>
      <button class="icon-btn" onclick="closeModal('stockInModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/inventario.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="entrada_stock">
      <input type="hidden" name="id_repuesto" id="stockInId">

      <div class="modal-body">
        <p style="font-size:14px;margin-top:0">
          Registrar ingreso para: <strong id="stockInName"></strong>
        </p>

        <div class="field">
          <label>Cantidad a Ingresar <span class="required">*</span></label>
          <input class="input" type="number" min="1" name="cantidad" required value="5">
        </div>

        <div class="field">
          <label>Costo Unitario de Compra (COP)</label>
          <input class="input" type="number" step="100" min="0" name="costo_compra" id="stockInCosto" value="0">
          <small class="muted">Si es mayor a cero, se registrará el egreso contable automáticamente.</small>
        </div>

        <div class="field">
          <label>Proveedor</label>
          <input class="input" name="proveedor" id="stockInProv" placeholder="Nombre del proveedor">
        </div>

        <div class="field">
          <label>Motivo</label>
          <input class="input" name="motivo" value="Compra de reposición de stock">
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('stockInModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Confirmar Entrada</button>
      </div>
    </form>
  </div>
</div>

<script>
function openNewPartModal(){
  document.getElementById('partTitle').textContent = 'Nuevo Repuesto';
  document.getElementById('partAccion').value = 'crear';
  document.getElementById('partId').value = '';
  document.getElementById('wrapActivo').style.display = 'none';
  ['codigo_rep','nombre_rep','descripcion','proveedor'].forEach(k => document.getElementById(k).value = '');
  document.getElementById('cantidad_disponible').value = '0';
  document.getElementById('stock_minimo').value = '5';
  document.getElementById('precio_actual').value = '0';
  document.getElementById('costo_repuestos').value = '0';
  document.getElementById('costo_mano_obra').value = '0';
  openModal('partModal');
}

function editPart(r){
  document.getElementById('partTitle').textContent = 'Editar Repuesto ' + (r.codigo_rep || ('#' + r.id_repuesto));
  document.getElementById('partAccion').value = 'editar';
  document.getElementById('partId').value = r.id_repuesto;
  document.getElementById('wrapActivo').style.display = 'block';

  ['codigo_rep','nombre_rep','descripcion','categoria','cantidad_disponible','stock_minimo','precio_actual','costo_repuestos','costo_mano_obra','proveedor'].forEach(k => {
    const el = document.getElementById(k);
    if(el) el.value = r[k] ?? '';
  });
  if(document.getElementById('partActivo')) document.getElementById('partActivo').value = r.activo;
  syncSearchableSelect('categoria');
  openModal('partModal');
}

function openStockInModal(id, name, costo, prov){
  document.getElementById('stockInId').value = id;
  document.getElementById('stockInName').textContent = name;
  document.getElementById('stockInCosto').value = costo || 0;
  document.getElementById('stockInProv').value = prov || '';
  openModal('stockInModal');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
