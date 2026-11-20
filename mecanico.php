<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();
if (is_admin()) redirect('index.php');

$pageTitle = 'Panel del Mecánico';
$pdo = db();
$idEmpleado = (int)(current_user()['id_empleado'] ?? 0);
$nombreTecnico = current_user()['nombre'] ?? 'Mecánico';

// KPIs del técnico
$stmt = $pdo->prepare("SELECT COUNT(*) FROM mantenimientos WHERE id_tecnico = ?");
$stmt->execute([$idEmpleado]);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM mantenimientos WHERE id_tecnico = ? AND LOWER(COALESCE(estado,'')) NOT IN ('reparado','entregado','completado','finalizado','listo','cancelado')");
$stmt->execute([$idEmpleado]);
$activos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM mantenimientos WHERE id_tecnico = ? AND LOWER(COALESCE(estado,'')) IN ('reparado','entregado','completado','finalizado','listo')");
$stmt->execute([$idEmpleado]);
$completados = (int)$stmt->fetchColumn();

// Reportes asignados a este mecánico o pendientes generales
$stmtRep = $pdo->prepare("
    SELECT r.*, b.codigo, b.modelo, b.marca, b.tipo_bicicleta, b.color
    FROM reportes_clientes r
    INNER JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
    WHERE (r.id_tecnico = ? OR (r.id_tecnico IS NULL AND r.estado = 'Recibido'))
      AND r.estado NOT IN ('Reparado', 'Entregado', 'Cancelado')
    ORDER BY CASE WHEN r.id_tecnico = ? THEN 0 ELSE 1 END, r.creado_en ASC
");
$stmtRep->execute([$idEmpleado, $idEmpleado]);
$reports = $stmtRep->fetchAll();

// Órdenes activas en taller asignadas a este mecánico
$stmtOrders = $pdo->prepare("
    SELECT m.*, b.codigo, b.modelo, b.marca, b.cliente, b.telefono_cliente
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    WHERE m.id_tecnico = ?
      AND LOWER(COALESCE(m.estado,'')) NOT IN ('entregado', 'cancelado')
    ORDER BY m.id_manten DESC
");
$stmtOrders->execute([$idEmpleado]);
$activeOrders = $stmtOrders->fetchAll();

// Historial reciente de reparaciones finalizadas por este mecánico
$stmtHist = $pdo->prepare("
    SELECT m.*, b.codigo, b.modelo, b.marca, b.cliente
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    WHERE m.id_tecnico = ?
      AND LOWER(COALESCE(m.estado,'')) IN ('reparado', 'entregado', 'completado', 'finalizado')
    ORDER BY m.fecha_salida DESC, m.id_manten DESC
    LIMIT 10
");
$stmtHist->execute([$idEmpleado]);
$history = $stmtHist->fetchAll();

// Repuestos disponibles para modal rápido
$parts = $pdo->query("SELECT id_repuesto, codigo_rep, nombre_rep, cantidad_disponible, precio_actual FROM inventario_costos_arreglos WHERE activo = 1 ORDER BY nombre_rep")->fetchAll();
$partData = [];
foreach ($parts as $p) {
    $partData[(int)$p['id_repuesto']] = $p;
}

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">TALLER ESPECIALIZADO · E-BIKES</span>
    <h1>Panel del Mecánico: <?= e($nombreTecnico) ?></h1>
    <p>Gestiona tus órdenes de trabajo, registra diagnósticos técnicos, repuestos y actualiza los 9 estados del servicio.</p>
  </div>
  <a class="btn btn-primary" href="mantenimientos.php?nuevo=1">
    <span class="material-symbols-outlined">add</span>Nueva orden de trabajo
  </a>
</div>

<div class="kpis">
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Mis Órdenes Totales</div>
        <div class="kpi-value"><?= $total ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">build</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">En Proceso / Taller</div>
        <div class="kpi-value"><?= $activos ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">construction</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Reparadas / Listas</div>
        <div class="kpi-value"><?= $completados ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">task_alt</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Reportes por Atender</div>
        <div class="kpi-value"><?= count($reports) ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">assignment</span></div>
    </div>
  </div>
</div>

<!-- Reportes de Clientes Asignados o en Espera -->
<section class="panel" style="margin-bottom:24px" id="reportes">
  <div class="panel-head">
    <div>
      <h2>⚡ Solicitudes y Reportes Asignados</h2>
      <p>Bicicletas esperando atención técnica enviadas por clientes.</p>
    </div>
    <span class="badge badge-warning"><?= count($reports) ?> reporte(s) activo(s)</span>
  </div>
  <div style="padding:18px">
    <?php foreach ($reports as $rp): ?>
      <?php $isAssignedToMe = ((int)$rp['id_tecnico'] === $idEmpleado); ?>
      <div class="report-card" style="border-left:4px solid <?= $isAssignedToMe ? 'var(--green)' : 'var(--warning)' ?>">
        <div class="report-card-head">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <span class="badge badge-neutral" style="font-family:monospace"><?= e($rp['codigo_seguimiento'] ?: ('REP-'.$rp['id_reporte'])) ?></span>
              <span class="badge badge-<?= status_class($rp['estado']) ?>"><?= e($rp['estado']) ?></span>
              <?php if ($isAssignedToMe): ?>
                <span class="badge badge-success">Asignado a ti</span>
              <?php else: ?>
                <span class="badge badge-warning">Sin asignar a mecánico</span>
              <?php endif; ?>
            </div>
            <h3 style="font-size:16px;color:var(--ink);margin:0 0 6px">
              <?= e($rp['codigo']) ?> · <?= e($rp['marca']) ?> <?= e($rp['modelo']) ?> (<?= e($rp['tipo_bicicleta'] ?: 'eBike') ?>)
            </h3>
            <p><b>Cliente:</b> <?= e($rp['nombre_cliente']) ?> · Tel: <?= e($rp['telefono_cliente'] ?: 'Sin teléfono') ?></p>
            <p><b>Categoría Falla:</b> <span class="badge badge-neutral"><?= e($rp['categoria_problema'] ?: 'General') ?></span></p>
            <div style="background:#fff;padding:10px 12px;border-radius:8px;border:1px solid var(--line);margin:8px 0;font-size:13px;line-height:1.5">
              <b>Falla reportada:</b> <?= nl2br(e($rp['descripcion'])) ?>
              <?php if (!empty($rp['observaciones'])): ?>
                <br><small class="muted">Observaciones: <?= e($rp['observaciones']) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="report-meta">
          <span>Recibido: <?= format_date($rp['creado_en']) ?></span>
          <?php if (!empty($rp['foto'])): ?>
            <a href="<?= e($rp['foto']) ?>" target="_blank" style="color:var(--green-700);font-weight:700">Ver fotografía adjunta</a>
          <?php endif; ?>
        </div>
        <div class="actions" style="margin-top:12px">
          <?php if (!empty($rp['id_mantenimiento'])): ?>
            <a class="btn btn-primary btn-sm" href="mantenimientos.php?editar=<?= (int)$rp['id_mantenimiento'] ?>">
              <span class="material-symbols-outlined">edit</span>Continuar Mantenimiento #<?= (int)$rp['id_mantenimiento'] ?>
            </a>
          <?php else: ?>
            <a class="btn btn-primary btn-sm" href="mantenimientos.php?nuevo=1&reporte=<?= (int)$rp['id_reporte'] ?>">
              <span class="material-symbols-outlined">build</span>Iniciar Orden de Trabajo
            </a>
          <?php endif; ?>
          <a class="btn btn-light btn-sm" href="seguimiento.php?codigo=<?= urlencode($rp['codigo_seguimiento'] ?: ('REP-'.$rp['id_reporte'])) ?>" target="_blank">
            <span class="material-symbols-outlined">visibility</span>Ver Seguimiento
          </a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$reports): ?>
      <div class="empty" style="padding:32px">
        <span class="material-symbols-outlined" style="color:var(--green)">check_circle</span><br>
        ¡Excelente! No tienes solicitudes pendientes de atención en este momento.
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Órdenes en Proceso Asignadas -->
<section class="panel" style="margin-bottom:24px">
  <div class="panel-head">
    <div>
      <h2>Órdenes de Trabajo en Curso</h2>
      <p>Servicios activos asignados a tu usuario.</p>
    </div>
    <a class="btn btn-light btn-sm" href="mantenimientos.php">Ver todas mis órdenes</a>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Bicicleta</th>
          <th>Cliente</th>
          <th>Fecha Ingreso</th>
          <th>Tipo</th>
          <th>Estado Actual (9 Estados)</th>
          <th>Diagnóstico / Trabajo</th>
          <th>Costo Total</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activeOrders as $o): ?>
        <tr>
          <td class="id">#<?= e($o['id_manten']) ?></td>
          <td>
            <strong><?= e($o['codigo'] ?: '—') ?></strong><br>
            <span class="muted"><?= e($o['modelo'] ?: '') ?></span>
          </td>
          <td>
            <?= e($o['cliente'] ?: 'Cliente taller') ?><br>
            <span class="muted"><?= e($o['telefono_cliente'] ?: '') ?></span>
          </td>
          <td><?= format_date($o['fecha_ingreso']) ?></td>
          <td><span class="badge badge-neutral"><?= e($o['tipo_manten']) ?></span></td>
          <td>
            <span class="badge badge-<?= status_class($o['estado']) ?>" style="font-weight:700">
              <?= e($o['estado'] ?: 'Recibido') ?>
            </span>
          </td>
          <td>
            <span style="font-weight:600;display:block;font-size:12px"><?= e(mb_strimwidth($o['diagnostico'], 0, 35, '...')) ?></span>
            <?php if (!empty($o['trabajos_realizados'])): ?>
              <small class="muted"><?= e(mb_strimwidth($o['trabajos_realizados'], 0, 30, '...')) ?></small>
            <?php endif; ?>
          </td>
          <td><b><?= money($o['costo']) ?></b></td>
          <td>
            <div class="actions-cell">
              <a class="btn btn-primary btn-sm" href="mantenimientos.php?editar=<?= (int)$o['id_manten'] ?>" title="Editar orden, repuestos y mano de obra">
                <span class="material-symbols-outlined">edit</span>Editar
              </a>
              <a class="btn btn-light btn-sm" href="imprimir_orden.php?id=<?= (int)$o['id_manten'] ?>" target="_blank" title="Imprimir ficha">
                <span class="material-symbols-outlined">print</span>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$activeOrders): ?>
        <tr>
          <td colspan="9" class="empty">No tienes órdenes activas en taller actualmente.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Historial de Trabajos Reparados -->
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Historial de Servicios Reparados / Entregados</h2>
      <p>Últimos 10 servicios completados por ti.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Bicicleta</th>
          <th>Cliente</th>
          <th>Fecha Ingreso</th>
          <th>Fecha Salida</th>
          <th>Estado</th>
          <th>Costo Facturado</th>
          <th>Comprobante</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td class="id">#<?= e($h['id_manten']) ?></td>
          <td><b><?= e($h['codigo'] ?: '—') ?></b> · <?= e($h['modelo'] ?: '') ?></td>
          <td><?= e($h['cliente'] ?: '—') ?></td>
          <td><?= format_date($h['fecha_ingreso']) ?></td>
          <td><?= format_date($h['fecha_salida']) ?></td>
          <td><span class="badge badge-success"><?= e($h['estado']) ?></span></td>
          <td><b><?= money($h['costo']) ?></b></td>
          <td>
            <a class="btn btn-light btn-sm" href="imprimir_orden.php?id=<?= (int)$h['id_manten'] ?>" target="_blank">
              <span class="material-symbols-outlined">receipt_long</span>Recibo
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?>
        <tr>
          <td colspan="8" class="empty">Aún no hay reparaciones completadas registradas en tu historial.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
