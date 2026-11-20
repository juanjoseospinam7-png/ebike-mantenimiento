<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_login();

// Si el usuario logueado es cliente, redirigir a su portal
if (is_client()) redirect('cliente.php');
if (is_mechanic()) redirect('mecanico.php');

$pageTitle = 'Dashboard General';
$pdo = db();

// Estadísticas Reales de MySQL
$totalBikes = (int)$pdo->query("SELECT COUNT(*) FROM bicicletas")->fetchColumn();
$reportesRecibidos = (int)$pdo->query("SELECT COUNT(*) FROM reportes_clientes")->fetchColumn();
$reportesPendientes = (int)$pdo->query("SELECT COUNT(*) FROM reportes_clientes WHERE estado IN ('Recibido','Pendiente de revisión','pendiente')")->fetchColumn();

$enReparacion = (int)$pdo->query("SELECT COUNT(*) FROM mantenimientos WHERE LOWER(COALESCE(estado,'')) IN ('en reparación','en reparacion','en diagnóstico','en diagnostico','en pruebas','en proceso')")->fetchColumn();
$reparadas = (int)$pdo->query("SELECT COUNT(*) FROM mantenimientos WHERE LOWER(COALESCE(estado,'')) IN ('reparado','listo','completado','finalizado')")->fetchColumn();
$entregadas = (int)$pdo->query("SELECT COUNT(*) FROM mantenimientos WHERE LOWER(COALESCE(estado,'')) IN ('entregado')")->fetchColumn();

$mecanicosActivos = (int)$pdo->query("SELECT COUNT(*) FROM empleados WHERE activo=1")->fetchColumn();
$clientesRegistrados = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='cliente'")->fetchColumn();

$ingresosDia = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM mantenimientos WHERE DATE(fecha_ingreso)=CURDATE() AND estado<>'Cancelado'")->fetchColumn();
$ingresosMes = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM mantenimientos WHERE MONTH(fecha_ingreso)=MONTH(CURDATE()) AND YEAR(fecha_ingreso)=YEAR(CURDATE()) AND estado<>'Cancelado'")->fetchColumn();
$gastosMes = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())")->fetchColumn();
$utilidadMes = $ingresosMes - $gastosMes;

$stockBajo = (int)$pdo->query("SELECT COUNT(*) FROM inventario_costos_arreglos WHERE cantidad_disponible <= stock_minimo AND activo=1")->fetchColumn();

// Últimos reportes recibidos
$recentReports = $pdo->query("
    SELECT r.*, b.codigo, b.modelo, e.nombre AS tecnico
    FROM reportes_clientes r
    LEFT JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = r.id_tecnico
    ORDER BY r.id_reporte DESC
    LIMIT 6
")->fetchAll();

// Últimos mantenimientos
$recent = $pdo->query("
    SELECT m.*, b.codigo, b.modelo, e.nombre AS tecnico, r.codigo_seguimiento
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    LEFT JOIN reportes_clientes r ON r.id_reporte = m.id_reporte_cliente
    ORDER BY m.id_manten DESC
    LIMIT 7
")->fetchAll();

// Datos para Gráfico de Distribución de Estados
$statusCounts = $pdo->query("
    SELECT estado, COUNT(*) as cnt 
    FROM mantenimientos 
    WHERE estado IS NOT NULL AND estado <> ''
    GROUP BY estado
")->fetchAll();

$chartLabels = [];
$chartCounts = [];
foreach ($statusCounts as $sc) {
    $chartLabels[] = $sc['estado'];
    $chartCounts[] = (int)$sc['cnt'];
}
if (!$chartLabels) {
    $chartLabels = ['Sin mantenimientos'];
    $chartCounts = [0];
}

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">PANEL ADMINISTRATIVO · E-BIKE MAINTENANCE SYSTEM</span>
    <h1>Control Maestro del Taller</h1>
    <p>Supervisión en tiempo real de operaciones técnicas, recepción de reportes, inventario y métricas financieras.</p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="reportes_admin.php">
      <span class="material-symbols-outlined">assignment_late</span>Ver Reportes
      <?php if ($reportesPendientes > 0): ?>
        <span class="badge badge-warning" style="margin-left:4px"><?= $reportesPendientes ?></span>
      <?php endif; ?>
    </a>
    <a class="btn btn-light" href="contabilidad.php">
      <span class="material-symbols-outlined">account_balance_wallet</span>Contabilidad
    </a>
    <a class="btn btn-primary" href="mantenimientos.php?nuevo=1">
      <span class="material-symbols-outlined">add</span>Nueva orden
    </a>
  </div>
</div>

<!-- Cuadrícula Principal de KPIs Reales -->
<div class="kpis" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Ingresos del Mes</div>
        <div class="kpi-value" style="font-size:22px;color:var(--green-800)"><?= money($ingresosMes) ?></div>
        <small class="muted">Hoy: <?= money($ingresosDia) ?></small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">payments</span></div>
    </div>
  </div>

  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Utilidad Neta Mes</div>
        <div class="kpi-value" style="font-size:22px;color:<?= $utilidadMes >= 0 ? 'var(--green-700)' : 'var(--danger)' ?>"><?= money($utilidadMes) ?></div>
        <small class="muted">Gastos mes: <?= money($gastosMes) ?></small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">account_balance</span></div>
    </div>
  </div>

  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">En Reparación / Taller</div>
        <div class="kpi-value" style="color:var(--info)"><?= $enReparacion ?></div>
        <small class="muted">Reparadas: <?= $reparadas ?> · Entregadas: <?= $entregadas ?></small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">construction</span></div>
    </div>
  </div>

  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Reportes de Clientes</div>
        <div class="kpi-value" style="color:<?= $reportesPendientes > 0 ? 'var(--warning)' : 'inherit' ?>"><?= $reportesRecibidos ?></div>
        <small class="muted"><?= $reportesPendientes ?> pendiente(s) de asignación</small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">support_agent</span></div>
    </div>
  </div>

  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Flota de Bicicletas</div>
        <div class="kpi-value"><?= $totalBikes ?></div>
        <small class="muted">Clientes registrados: <?= $clientesRegistrados ?></small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">electric_bike</span></div>
    </div>
  </div>

  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Mecánicos Activos</div>
        <div class="kpi-value"><?= $mecanicosActivos ?></div>
        <small class="muted">Alertas stock: <b style="color:<?= $stockBajo > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $stockBajo ?></b></small>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">engineering</span></div>
    </div>
  </div>
</div>

<!-- Gráficos y Tablas Principales -->
<div class="grid-main" style="grid-template-columns:minmax(0,1fr) 350px;margin-bottom:24px">
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Últimas Órdenes de Mantenimiento</h2>
        <p>Órdenes registradas en la tabla <b>mantenimientos</b>.</p>
      </div>
      <a class="btn btn-light btn-sm" href="mantenimientos.php">Ver todas</a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Bicicleta</th>
            <th>Técnico</th>
            <th>Fecha Ingreso</th>
            <th>Estado</th>
            <th>Total Facturado</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
          <tr>
            <td class="id">#<?= e($row['id_manten']) ?></td>
            <td>
              <span class="bike-name"><?= e($row['codigo'] ?: 'Sin bici') ?></span><br>
              <span class="muted"><?= e($row['modelo'] ?: '') ?></span>
            </td>
            <td><?= e($row['tecnico'] ?: 'ID '.$row['id_tecnico']) ?></td>
            <td><?= format_date($row['fecha_ingreso']) ?></td>
            <td>
              <span class="badge badge-<?= status_class($row['estado']) ?>">
                <?= e($row['estado'] ?: 'Recibido') ?>
              </span>
            </td>
            <td><b><?= money($row['costo']) ?></b></td>
            <td>
              <a class="btn btn-light btn-sm" href="imprimir_orden.php?id=<?= (int)$row['id_manten'] ?>" target="_blank" title="Imprimir Orden">
                <span class="material-symbols-outlined">print</span>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?>
          <tr><td colspan="7" class="empty">No hay órdenes registradas aún.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Gráfico de Estados y Resumen Operativo -->
  <div class="side-stack">
    <div class="panel">
      <div class="panel-head">
        <h3>Distribución por Estados</h3>
      </div>
      <div style="padding:16px;height:240px;display:flex;align-items:center;justify-content:center">
        <canvas id="statusChart"></canvas>
      </div>
    </div>

    <div class="panel quick">
      <div class="panel-head" style="padding:0 0 10px;border:0">
        <h3>Acciones Rápidas</h3>
      </div>
      <a class="quick-item" href="reportes_admin.php">
        <div class="quick-icon"><span class="material-symbols-outlined">assignment_late</span></div>
        <div>
          <strong>Recepción de Bicicletas</strong>
          <span>Asignar solicitudes de clientes</span>
        </div>
      </a>
      <a class="quick-item" href="mantenimientos.php?nuevo=1">
        <div class="quick-icon"><span class="material-symbols-outlined">add</span></div>
        <div>
          <strong>Nueva Orden de Trabajo</strong>
          <span>Registrar mantenimiento manual</span>
        </div>
      </a>
      <a class="quick-item" href="contabilidad.php">
        <div class="quick-icon"><span class="material-symbols-outlined">account_balance_wallet</span></div>
        <div>
          <strong>Módulo de Contabilidad</strong>
          <span>Ingresos, gastos y utilidades</span>
        </div>
      </a>
      <a class="quick-item" href="cuentas.php">
        <div class="quick-icon"><span class="material-symbols-outlined">manage_accounts</span></div>
        <div>
          <strong>Gestión de Personal</strong>
          <span>Crear administradores y mecánicos</span>
        </div>
      </a>
    </div>
  </div>
</div>

<!-- Últimos Reportes Recibidos desde Clientes -->
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Últimas Solicitudes Recibidas de Clientes</h2>
      <p>Revisión y asignación inmediata para agilizar la operación del taller.</p>
    </div>
    <a class="btn btn-light btn-sm" href="reportes_admin.php">Ver panel de recepción</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Código Seguimiento</th>
          <th>Bicicleta</th>
          <th>Cliente</th>
          <th>Categoría Falla</th>
          <th>Fecha Reporte</th>
          <th>Estado</th>
          <th>Mecánico Asignado</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recentReports as $rp): ?>
        <tr>
          <td><code style="font-weight:700;color:var(--green-700)"><?= e($rp['codigo_seguimiento'] ?: ('REP-'.$rp['id_reporte'])) ?></code></td>
          <td><b><?= e($rp['codigo'] ?: $rp['codigo_bici']) ?></b> <?= e($rp['modelo']) ?></td>
          <td><?= e($rp['nombre_cliente']) ?> · <?= e($rp['telefono_cliente'] ?: '') ?></td>
          <td><span class="badge badge-neutral"><?= e($rp['categoria_problema']) ?></span></td>
          <td><?= format_date($rp['creado_en']) ?></td>
          <td>
            <span class="badge badge-<?= status_class($rp['estado']) ?>">
              <?= e($rp['estado']) ?>
            </span>
          </td>
          <td><?= e($rp['tecnico'] ?: 'Sin asignar') ?></td>
          <td>
            <a class="btn btn-primary btn-sm" href="reportes_admin.php">
              <span class="material-symbols-outlined">assignment</span>Gestionar
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentReports): ?>
        <tr><td colspan="8" class="empty">No hay solicitudes de clientes registradas.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
window.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('statusChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
          data: <?= json_encode($chartCounts) ?>,
          backgroundColor: [
            '#16a05d', '#087a50', '#35b96f', '#073b2a', '#126e9b', '#9a6a00', '#c73d3d', '#557065'
          ],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
        }
      }
    });
  }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
