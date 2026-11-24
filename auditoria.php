<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();

$pageTitle = 'Historial & Auditoría';
$pdo = db();

$search = trim($_GET['buscar'] ?? '');
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(l.accion LIKE ? OR l.detalles LIKE ? OR l.entidad_tipo LIKE ? OR CAST(l.entidad_id AS CHAR) LIKE ? OR e.nombre LIKE ? OR u.nombre LIKE ?)";
    for ($i = 0; $i < 6; $i++) $params[] = "%$search%";
}

$sql = "
    SELECT l.*, 
           m.tipo_manten, 
           b.codigo AS b_codigo,
           e.nombre AS nombre_empleado,
           u.nombre AS nombre_usuario,
           u.rol AS rol_usuario
    FROM logs_auditoria l
    LEFT JOIN mantenimientos m ON m.id_manten = l.id_manten
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = l.id_empleado
    LEFT JOIN usuarios u ON u.id = l.id_usuario
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY l.id_log DESC LIMIT 150";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">TRAZABILIDAD & SEGURIDAD</span>
    <h1>Auditoría de la operación</h1>
    <p>Historial de alquileres, incidencias, mantenimiento interno, flota, pagos y accesos.</p>
  </div>
</div>

<section class="panel">
  <!-- Filtro -->
  <form class="filters" method="get" style="grid-template-columns:1fr auto">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por acción, detalles, orden #, empleado o usuario responsable...">
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Buscar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Trazabilidad de Eventos del Sistema</h2>
      <p>Mostrando los últimos <?= count($rows) ?> eventos registrados en vivo.</p>
    </div>
    <span class="badge badge-success">✓ Sistema de Registro Activo</span>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Log #</th>
          <th>Orden / Bici</th>
          <th>Acción Realizada</th>
          <th>Transición de Estado</th>
          <th>Detalles del Evento</th>
          <th>Responsable</th>
          <th>Fecha y Hora</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="id">#<?= e($r['id_log']) ?></td>
          <td>
            <?php if (!empty($r['id_manten'])): ?>
              <a href="mantenimientos.php?buscar=<?= (int)$r['id_manten'] ?>" style="font-weight:700;color:var(--green-700)">
                #<?= e($r['id_manten']) ?> (<?= e($r['b_codigo'] ?: 'eBike') ?>)
              </a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><b><?= e($r['accion'] ?: 'Cambio de estado') ?></b></td>
          <td>
            <?php if ($r['estado_anterior'] || $r['estado_nuevo']): ?>
              <span class="badge badge-neutral" style="font-size:10px"><?= e($r['estado_anterior'] ?: 'Inicio') ?></span>
              <span class="material-symbols-outlined" style="font-size:14px;color:var(--green);vertical-align:middle">arrow_forward</span>
              <span class="badge badge-success" style="font-size:10px"><?= e($r['estado_nuevo'] ?: 'Actual') ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td style="max-width:320px;font-size:12px;color:var(--ink)">
            <?= e($r['detalles'] ?: 'Operación registrada en el flujo del taller.') ?>
          </td>
          <td>
            <?php if (!empty($r['nombre_usuario'])): ?>
              <b><?= e($r['nombre_usuario']) ?></b><br>
              <small class="muted">(<?= ucfirst(e($r['rol_usuario'] ?: 'Usuario')) ?>)</small>
            <?php elseif (!empty($r['nombre_empleado'])): ?>
              <b><?= e($r['nombre_empleado']) ?></b><br>
              <small class="muted">(Técnico)</small>
            <?php else: ?>
              <span class="muted">Sistema</span>
            <?php endif; ?>
          </td>
          <td><?= format_date($r['fecha_hora']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">No se encontraron eventos en el registro de auditoría.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
