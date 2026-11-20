<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();

$pageTitle = 'Recepción & Reportes de Clientes';
$pdo = db();

// Asignación de mecánico o cambio de estado desde admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $accion = post_string('accion');
        $idReporte = post_int('id_reporte');
        if (!$idReporte) throw new RuntimeException('ID de reporte inválido.');

        if ($accion === 'asignar') {
            $idTecnico = post_int('id_tecnico');
            if (!$idTecnico) throw new RuntimeException('Selecciona un mecánico de la lista.');

            // Obtener nombre técnico
            $tStmt = $pdo->prepare("SELECT nombre FROM empleados WHERE id_empleado = ?");
            $tStmt->execute([$idTecnico]);
            $tecnicoNombre = $tStmt->fetchColumn() ?: 'Técnico';

            $repStmt = $pdo->prepare("SELECT codigo_seguimiento, codigo_bici, nombre_cliente FROM reportes_clientes WHERE id_reporte = ?");
            $repStmt->execute([$idReporte]);
            $repData = $repStmt->fetch();

            $upd = $pdo->prepare("UPDATE reportes_clientes SET id_tecnico = ?, estado = IF(estado='Recibido','Pendiente de revisión',estado) WHERE id_reporte = ?");
            $upd->execute([$idTecnico, $idReporte]);

            // Obtener usuario vinculado a ese empleado para notificarle
            $uStmt = $pdo->prepare("SELECT id FROM usuarios WHERE id_empleado = ? LIMIT 1");
            $uStmt->execute([$idTecnico]);
            $userMecId = $uStmt->fetchColumn() ?: null;

            notify(
                $pdo,
                "Nuevo reporte asignado: " . ($repData['codigo_bici'] ?? 'eBike'),
                "Se te ha asignado el reporte {$repData['codigo_seguimiento']} de {$repData['nombre_cliente']}.",
                'mecanico',
                $userMecId ? (int)$userMecId : null,
                'mecanico.php'
            );

            audit($pdo, null, null, 'Asignado', $idTecnico, 'Asignación de reporte', "Reporte #$idReporte asignado a $tecnicoNombre");

            flash('success', "Reporte #$idReporte asignado exitosamente a $tecnicoNombre.");
        } elseif ($accion === 'cambiar_estado') {
            $nuevoEstado = post_string('estado');
            if (!in_array($nuevoEstado, MAINTENANCE_STATES, true)) {
                throw new RuntimeException('Estado no válido.');
            }

            $oldStmt = $pdo->prepare("SELECT estado, id_mantenimiento, id_tecnico FROM reportes_clientes WHERE id_reporte = ?");
            $oldStmt->execute([$idReporte]);
            $oldRow = $oldStmt->fetch();

            $upd = $pdo->prepare("UPDATE reportes_clientes SET estado = ?, atendido_en = IF(? IN ('Reparado','Entregado'), COALESCE(atendido_en, NOW()), atendido_en) WHERE id_reporte = ?");
            $upd->execute([$nuevoEstado, $nuevoEstado, $idReporte]);

            if (!empty($oldRow['id_mantenimiento'])) {
                $updM = $pdo->prepare("UPDATE mantenimientos SET estado = ?, fecha_salida = IF(?='Entregado', NOW(), fecha_salida) WHERE id_manten = ?");
                $updM->execute([$nuevoEstado, $nuevoEstado, (int)$oldRow['id_mantenimiento']]);
                audit($pdo, (int)$oldRow['id_mantenimiento'], $oldRow['estado'], $nuevoEstado, (int)($oldRow['id_tecnico'] ?? null), 'Cambio de estado desde admin');
            }

            flash('success', "Estado del reporte #$idReporte actualizado a '$nuevoEstado'.");
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('reportes_admin.php');
}

$search = trim($_GET['buscar'] ?? '');
$filterEstado = trim($_GET['estado'] ?? '');
$filterCat = trim($_GET['categoria'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(r.codigo_seguimiento LIKE ? OR r.nombre_cliente LIKE ? OR r.telefono_cliente LIKE ? OR b.codigo LIKE ? OR r.codigo_bici LIKE ? OR r.descripcion LIKE ?)";
    for ($i = 0; $i < 6; $i++) $params[] = "%$search%";
}
if ($filterEstado !== '') {
    $where[] = "r.estado = ?";
    $params[] = $filterEstado;
}
if ($filterCat !== '') {
    $where[] = "r.categoria_problema = ?";
    $params[] = $filterCat;
}

$sql = "
    SELECT r.*, 
           b.codigo AS b_codigo, b.modelo AS b_modelo, b.marca AS b_marca,
           e.nombre AS nombre_tecnico,
           m.id_manten, m.costo, m.estado AS estado_manten
    FROM reportes_clientes r
    LEFT JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = r.id_tecnico
    LEFT JOIN mantenimientos m ON m.id_manten = r.id_mantenimiento
";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY r.id_reporte DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$mechanics = $pdo->query("SELECT id_empleado, nombre, cargo, especialidad FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">RECEPCIÓN DE TALLER</span>
    <h1>Recepción & Reportes de Bicicletas</h1>
    <p>Supervisa todos los reportes enviados por clientes, asigna técnicos responsables y controla su avance.</p>
  </div>
  <a class="btn btn-primary" href="mantenimientos.php?nuevo=1">
    <span class="material-symbols-outlined">add</span>Nueva orden directa
  </a>
</div>

<section class="panel">
  <!-- Filtros -->
  <form class="filters" method="get" style="grid-template-columns:2fr 1fr 1fr auto">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por código de seguimiento, cliente, teléfono o bicicleta...">
    <select class="select" name="estado">
      <option value="">Todos los estados</option>
      <?php foreach (MAINTENANCE_STATES as $st): ?>
        <option value="<?= e($st) ?>" <?= ($filterEstado === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="categoria">
      <option value="">Todas las categorías</option>
      <?php foreach (PROBLEM_CATEGORIES as $cat): ?>
        <option value="<?= e($cat) ?>" <?= ($filterCat === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Filtrar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Solicitudes registradas en el taller</h2>
      <p><?= count($reports) ?> reporte(s) encontrado(s).</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Código Seguimiento</th>
          <th>Bicicleta</th>
          <th>Cliente & Contacto</th>
          <th>Categoría & Falla</th>
          <th>Fecha Ingreso</th>
          <th>Técnico Asignado</th>
          <th>Estado Actual</th>
          <th>Costo (COP)</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($reports as $r): ?>
        <tr>
          <td>
            <a href="seguimiento.php?codigo=<?= urlencode($r['codigo_seguimiento']) ?>" target="_blank" style="font-family:monospace;font-weight:700;color:var(--green-700)">
              <?= e($r['codigo_seguimiento']) ?>
            </a>
          </td>
          <td>
            <strong><?= e($r['b_codigo'] ?: $r['codigo_bici']) ?></strong><br>
            <span class="muted"><?= e($r['b_modelo'] ?: $r['modelo']) ?></span>
          </td>
          <td>
            <b><?= e($r['nombre_cliente']) ?></b><br>
            <span class="muted"><?= e($r['telefono_cliente'] ?: 'Sin teléfono') ?></span>
          </td>
          <td>
            <span class="badge badge-neutral"><?= e($r['categoria_problema']) ?></span><br>
            <span style="font-size:12px;color:var(--ink)" title="<?= e($r['descripcion']) ?>">
              <?= e(mb_strimwidth($r['descripcion'], 0, 45, '...')) ?>
            </span>
          </td>
          <td><?= format_date($r['creado_en']) ?></td>
          <td>
            <?php if (!empty($r['nombre_tecnico'])): ?>
              <span class="badge badge-info" style="font-size:11px">👨‍🔧 <?= e($r['nombre_tecnico']) ?></span>
            <?php else: ?>
              <span class="badge badge-warning">Sin asignar</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= status_class($r['estado']) ?>">
              <?= e($r['estado']) ?>
            </span>
          </td>
          <td>
            <?php if (!empty($r['costo'])): ?>
              <b><?= money($r['costo']) ?></b>
            <?php else: ?>
              <span class="muted">Pendiente</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="actions-cell">
              <!-- Botón Asignar Técnico -->
              <button class="icon-btn" title="Asignar o Cambiar Técnico" onclick='openAssignModal(<?= (int)$r['id_reporte'] ?>, <?= json_encode($r['nombre_cliente']) ?>, <?= (int)($r['id_tecnico'] ?? 0) ?>)'>
                <span class="material-symbols-outlined">person_pin</span>
              </button>

              <!-- Botón Ver / Atender Orden -->
              <?php if (!empty($r['id_mantenimiento'])): ?>
                <a class="icon-btn" title="Ir a Orden de Mantenimiento #<?= (int)$r['id_mantenimiento'] ?>" href="mantenimientos.php?editar=<?= (int)$r['id_mantenimiento'] ?>">
                  <span class="material-symbols-outlined">build</span>
                </a>
              <?php else: ?>
                <a class="icon-btn" title="Crear Orden para este Reporte" href="mantenimientos.php?nuevo=1&reporte=<?= (int)$r['id_reporte'] ?>" style="background:var(--green-100);color:var(--green-900)">
                  <span class="material-symbols-outlined">playlist_add</span>
                </a>
              <?php endif; ?>

              <!-- Cambiar Estado -->
              <button class="icon-btn" title="Cambiar Estado" onclick='openStatusModal(<?= (int)$r['id_reporte'] ?>, <?= json_encode($r['estado']) ?>)'>
                <span class="material-symbols-outlined">published_with_changes</span>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$reports): ?>
        <tr>
          <td colspan="9" class="empty">No se encontraron reportes con los criterios de búsqueda.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Modal Asignar Técnico -->
<div class="modal-backdrop" id="assignModal">
  <div class="modal">
    <div class="modal-head">
      <h2>Asignar Mecánico Responsable</h2>
      <button class="icon-btn" type="button" onclick="closeModal('assignModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="reportes_admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="asignar">
      <input type="hidden" name="id_reporte" id="assignReportId">
      <div class="modal-body">
        <p id="assignDesc" style="margin-top:0;font-size:13px;color:var(--muted)"></p>
        <div class="field">
          <label>Seleccionar Mecánico del Taller <span class="required">*</span></label>
          <select class="select" name="id_tecnico" id="assignTecnicoSelect" required>
            <option value="">Buscar / seleccionar mecánico...</option>
            <?php foreach ($mechanics as $m): ?>
              <option value="<?= (int)$m['id_empleado'] ?>">
                <?= e($m['nombre']) ?> · <?= e($m['cargo']) ?> <?= $m['especialidad'] ? '('.$m['especialidad'].')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="muted" style="margin-top:10px;font-size:11px">
          Al asignar el reporte, el estado cambiará a <b>Pendiente de revisión</b> y el mecánico recibirá una notificación en su panel.
        </p>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('assignModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Asignar Mecánico</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Cambiar Estado -->
<div class="modal-backdrop" id="statusModal">
  <div class="modal">
    <div class="modal-head">
      <h2>Cambiar Estado del Reporte</h2>
      <button class="icon-btn" type="button" onclick="closeModal('statusModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="reportes_admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="cambiar_estado">
      <input type="hidden" name="id_reporte" id="statusReportId">
      <div class="modal-body">
        <div class="field">
          <label>Nuevo Estado <span class="required">*</span></label>
          <select class="select" name="estado" id="statusSelect" required>
            <?php foreach (MAINTENANCE_STATES as $st): ?>
              <option value="<?= e($st) ?>"><?= e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('statusModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Actualizar Estado</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAssignModal(id, client, currentTec){
  document.getElementById('assignReportId').value = id;
  document.getElementById('assignDesc').textContent = 'Asignando orden de ' + client + ' (Reporte #' + id + ')';
  document.getElementById('assignTecnicoSelect').value = currentTec || '';
  syncSearchableSelect('assignTecnicoSelect');
  openModal('assignModal');
}
function openStatusModal(id, currentStatus){
  document.getElementById('statusReportId').value = id;
  document.getElementById('statusSelect').value = currentStatus;
  syncSearchableSelect('statusSelect');
  openModal('statusModal');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
