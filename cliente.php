<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();

$pageTitle = 'Mi Panel de Cliente';
$pdo = db();
$userId = (int)current_user()['id'];
$userPhone = current_user()['telefono'] ?? '';
$userName = current_user()['nombre'] ?? '';

// Obtener bicicletas del cliente
$stmtBikes = $pdo->prepare("SELECT * FROM bicicletas WHERE id_usuario = ? OR (cliente = ? AND cliente IS NOT NULL AND cliente <> '') ORDER BY id_bicicleta DESC");
$stmtBikes->execute([$userId, $userName]);
$bikes = $stmtBikes->fetchAll();

// Obtener reportes y solicitudes de servicio del cliente
$stmtReports = $pdo->prepare("
    SELECT r.*, b.codigo, b.modelo, b.marca, b.tipo_bicicleta,
           e.nombre AS nombre_tecnico,
           m.id_manten, m.costo, m.mano_obra, m.total_repuestos, m.diagnostico AS diag_manten,
           m.trabajos_realizados, m.observaciones AS obs_manten, m.estado AS estado_manten,
           m.estado_pago, m.metodo_pago, m.fecha_salida
    FROM reportes_clientes r
    LEFT JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = r.id_tecnico
    LEFT JOIN mantenimientos m ON m.id_manten = r.id_mantenimiento
    WHERE r.id_cliente = ? OR r.nombre_cliente = ?
    ORDER BY r.id_reporte DESC
");
$stmtReports->execute([$userId, $userName]);
$reports = $stmtReports->fetchAll();

$totalBikes = count($bikes);
$activos = 0;
$listos = 0;
foreach ($reports as $rp) {
    $st = mb_strtolower($rp['estado_manten'] ?: $rp['estado']);
    if (in_array($st, ['reparado', 'entregado', 'completado', 'finalizado'])) {
        $listos++;
    } elseif ($st !== 'cancelado') {
        $activos++;
    }
}

include __DIR__ . '/partials/header.php';
?>

<div class="client-hero">
  <div>
    <span class="eyebrow" style="color:#b8f2d5">PORTAL DE SERVICIOS E-BIKE</span>
    <h1>¡Hola, <?= e($userName) ?>!</h1>
    <p>Gestiona tus bicicletas eléctricas, solicita mantenimientos y consulta presupuestos y avances en vivo.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn btn-primary" href="reportar.php" style="background:#fff;color:var(--green-900)">
      <span class="material-symbols-outlined">add_alert</span>Reportar Falla
    </a>
    <a class="btn btn-light" href="mis_bicicletas.php" style="background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.3)">
      <span class="material-symbols-outlined">pedal_bike</span>Mis Bicicletas
    </a>
  </div>
</div>

<div class="kpis">
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Mis Bicicletas</div>
        <div class="kpi-value"><?= $totalBikes ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">electric_bike</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">En Mantenimiento</div>
        <div class="kpi-value"><?= $activos ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">construction</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Listas / Reparadas</div>
        <div class="kpi-value"><?= $listos ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">task_alt</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Rastreo Rápido</div>
        <div class="kpi-value" style="font-size:16px;margin-top:12px">
          <a href="seguimiento.php" style="color:var(--green);display:flex;align-items:center;gap:4px">
            Consultar <span class="material-symbols-outlined" style="font-size:18px">arrow_forward</span>
          </a>
        </div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">qr_code_scanner</span></div>
    </div>
  </div>
</div>

<!-- Reportes y Solicitudes de Servicio -->
<section class="panel" style="margin-bottom:24px">
  <div class="panel-head">
    <div>
      <h2>Historial de Solicitudes y Mantenimientos</h2>
      <p>Revisa el estado actual, diagnóstico técnico, presupuesto y observaciones de tus eBikes.</p>
    </div>
    <a class="btn btn-primary btn-sm" href="reportar.php">
      <span class="material-symbols-outlined">add</span>Nueva solicitud
    </a>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Seguimiento</th>
          <th>Bicicleta</th>
          <th>Falla / Categoría</th>
          <th>Fecha Reporte</th>
          <th>Estado del Servicio</th>
          <th>Técnico Asignado</th>
          <th>Presupuesto (COP)</th>
          <th>Detalles</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($reports as $r): ?>
        <?php 
          $currentStatus = $r['estado_manten'] ?: $r['estado'];
          $isReady = in_array(mb_strtolower($currentStatus), ['reparado', 'completado', 'listo', 'entregado']);
        ?>
        <tr class="<?= $isReady ? 'ready-row' : '' ?>">
          <td>
            <a href="seguimiento.php?codigo=<?= urlencode($r['codigo_seguimiento'] ?: ('REP-'.$r['id_reporte'])) ?>" style="font-family:monospace;font-weight:700;color:var(--green-700);text-decoration:underline">
              <?= e($r['codigo_seguimiento'] ?: ('REP-'.$r['id_reporte'])) ?>
            </a>
          </td>
          <td>
            <strong><?= e($r['codigo'] ?: ($r['codigo_bici'] ?: 'eBike')) ?></strong><br>
            <span class="muted"><?= e($r['modelo'] ?: '') ?></span>
          </td>
          <td>
            <span class="badge badge-neutral"><?= e($r['categoria_problema'] ?: 'General') ?></span><br>
            <span style="font-size:12px;color:var(--ink)"><?= e(mb_strimwidth($r['descripcion'], 0, 45, '...')) ?></span>
          </td>
          <td><?= format_date($r['creado_en']) ?></td>
          <td>
            <span class="badge badge-<?= status_class($currentStatus) ?>">
              <?= e($currentStatus) ?>
            </span>
            <?php if ($isReady): ?>
              <br><small style="color:var(--green-700);font-weight:700">✓ ¡Lista para recoger!</small>
            <?php endif; ?>
          </td>
          <td><?= e($r['nombre_tecnico'] ?: 'Por asignar') ?></td>
          <td>
            <?php if (!empty($r['costo']) && (float)$r['costo'] > 0): ?>
              <b><?= money($r['costo']) ?></b>
              <br><span class="muted" style="font-size:10px"><?= ($r['estado_pago'] === 'pagado') ? 'Pagado' : 'Pendiente de pago' ?></span>
            <?php else: ?>
              <span class="muted">En cotización</span>
            <?php endif; ?>
          </td>
          <td>
            <a class="btn btn-light btn-sm" href="seguimiento.php?codigo=<?= urlencode($r['codigo_seguimiento'] ?: ('REP-'.$r['id_reporte'])) ?>">
              <span class="material-symbols-outlined">visibility</span>Ver
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$reports): ?>
        <tr>
          <td colspan="8" class="empty">
            <span class="material-symbols-outlined">build_circle</span><br>
            Aún no has registrado ninguna solicitud de mantenimiento.<br>
            <a class="btn btn-primary btn-sm" href="reportar.php" style="margin-top:10px">Reportar una falla ahora</a>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Mis Bicicletas Registradas -->
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Mis Bicicletas Eléctricas</h2>
      <p>Registra y mantén al día la información técnica de tus vehículos.</p>
    </div>
    <a class="btn btn-light btn-sm" href="mis_bicicletas.php">
      <span class="material-symbols-outlined">add</span>Registrar otra bicicleta
    </a>
  </div>

  <div style="padding:20px">
    <div class="bike-grid">
      <?php foreach ($bikes as $bk): ?>
        <div class="bike-card">
          <div>
            <div class="bike-card-head">
              <div>
                <strong style="font-size:16px;color:var(--ink)"><?= e($bk['codigo']) ?></strong>
                <div class="muted"><?= e($bk['marca'] . ' ' . $bk['modelo']) ?></div>
              </div>
              <span class="bike-card-badge"><?= e($bk['tipo_bicicleta'] ?: 'Urbana') ?></span>
            </div>
            <div class="bike-card-body">
              <p><b>Color:</b> <?= e($bk['color'] ?: 'No especificado') ?></p>
              <p><b>N° Serie / Chasis:</b> <?= e($bk['numero_serie'] ?: 'No registrado') ?></p>
              <p><b>Estado:</b> <span class="badge badge-<?= $bk['activa'] ? 'success' : 'danger' ?>"><?= $bk['activa'] ? 'Operativa' : 'Inactiva' ?></span></p>
            </div>
          </div>
          <div style="margin-top:14px;display:flex;gap:8px">
            <a class="btn btn-primary btn-sm" href="reportar.php?id_bicicleta=<?= (int)$bk['id_bicicleta'] ?>" style="flex:1;justify-content:center">
              <span class="material-symbols-outlined">build</span>Reportar falla
            </a>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$bikes): ?>
        <div class="empty" style="grid-column:1/-1;padding:30px">
          <p>No tienes bicicletas asociadas a tu cuenta aún.</p>
          <a class="btn btn-primary" href="mis_bicicletas.php">Registrar mi primera eBike</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
