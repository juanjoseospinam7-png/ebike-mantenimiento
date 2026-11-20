<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();

$pageTitle = 'Contabilidad & Finanzas';
$pdo = db();

$periodo = trim($_GET['periodo'] ?? 'mes');
$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');

// Filtros temporales
$mWhere = ["m.estado <> 'Cancelado'"];
$gWhere = [];
$mParams = [];
$gParams = [];

if ($desde !== '' && $hasta !== '') {
    $periodo = 'custom';
    $mWhere[] = "DATE(m.fecha_ingreso) BETWEEN ? AND ?";
    $mParams[] = $desde;
    $mParams[] = $hasta;
    $gWhere[] = "g.fecha BETWEEN ? AND ?";
    $gParams[] = $desde;
    $gParams[] = $hasta;
} else {
    switch ($periodo) {
        case 'hoy':
            $mWhere[] = "DATE(m.fecha_ingreso) = CURDATE()";
            $gWhere[] = "g.fecha = CURDATE()";
            break;
        case 'semana':
            $mWhere[] = "m.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $gWhere[] = "g.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'anio':
            $mWhere[] = "YEAR(m.fecha_ingreso) = YEAR(CURDATE())";
            $gWhere[] = "YEAR(g.fecha) = YEAR(CURDATE())";
            break;
        case 'todos':
            // sin filtro de fecha
            break;
        case 'mes':
        default:
            $periodo = 'mes';
            $mWhere[] = "MONTH(m.fecha_ingreso) = MONTH(CURDATE()) AND YEAR(m.fecha_ingreso) = YEAR(CURDATE())";
            $gWhere[] = "MONTH(g.fecha) = MONTH(CURDATE()) AND YEAR(g.fecha) = YEAR(CURDATE())";
            break;
    }
}

// 1. Ingresos totales por servicios (excluyendo cancelados para no inflar artificialmente)
$sqlIngresos = "SELECT 
    COALESCE(SUM(m.costo), 0) AS total_ingresos,
    COALESCE(SUM(m.mano_obra), 0) AS total_mano_obra,
    COALESCE(SUM(m.total_repuestos), 0) AS total_repuestos,
    COALESCE(SUM(CASE WHEN m.estado_pago = 'pagado' THEN m.costo ELSE 0 END), 0) AS ingresos_cobrados,
    COALESCE(SUM(CASE WHEN m.estado_pago = 'pendiente' THEN m.costo ELSE 0 END), 0) AS ingresos_pendientes,
    COUNT(m.id_manten) AS total_servicios
    FROM mantenimientos m WHERE " . implode(' AND ', $mWhere);
$stmtIng = $pdo->prepare($sqlIngresos);
$stmtIng->execute($mParams);
$kpiIng = $stmtIng->fetch();

// 2. Gastos totales por compras y operación
$sqlGastos = "SELECT COALESCE(SUM(g.monto), 0) AS total_gastos, COUNT(g.id_gasto) AS total_num_gastos FROM gastos g";
if ($gWhere) $sqlGastos .= " WHERE " . implode(' AND ', $gWhere);
$stmtGas = $pdo->prepare($sqlGastos);
$stmtGas->execute($gParams);
$kpiGas = $stmtGas->fetch();

$totalIngresos = (float)$kpiIng['total_ingresos'];
$totalGastos = (float)$kpiGas['total_gastos'];
$utilidadNeta = $totalIngresos - $totalGastos; // Ingresos reales - Gastos

// KPIs rápidos Diarios y Mensuales para el header
$ingHoy = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM mantenimientos WHERE DATE(fecha_ingreso) = CURDATE() AND estado <> 'Cancelado'")->fetchColumn();
$gasHoy = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha = CURDATE()")->fetchColumn();
$ingMes = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM mantenimientos WHERE MONTH(fecha_ingreso)=MONTH(CURDATE()) AND YEAR(fecha_ingreso)=YEAR(CURDATE()) AND estado <> 'Cancelado'")->fetchColumn();
$gasMes = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())")->fetchColumn();
$utilidadMes = $ingMes - $gasMes;

// Listado de servicios del período
$sqlServicios = "
    SELECT m.*, b.codigo AS b_codigo, b.modelo AS b_modelo, b.cliente AS b_cliente, e.nombre AS nombre_tecnico
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    WHERE " . implode(' AND ', $mWhere) . "
    ORDER BY m.id_manten DESC
";
$stmtServ = $pdo->prepare($sqlServicios);
$stmtServ->execute($mParams);
$servicios = $stmtServ->fetchAll();

// Listado de gastos del período
$sqlListaGastos = "
    SELECT g.*, u.nombre AS usuario_nombre
    FROM gastos g
    LEFT JOIN usuarios u ON u.id = g.id_usuario
";
if ($gWhere) $sqlListaGastos .= " WHERE " . implode(' AND ', $gWhere);
$sqlListaGastos .= " ORDER BY g.fecha DESC, g.id_gasto DESC";
$stmtListaGastos = $pdo->prepare($sqlListaGastos);
$stmtListaGastos->execute($gParams);
$gastos = $stmtListaGastos->fetchAll();

// Historial últimos 6 meses para gráfico
$monthlyChartData = [];
for ($i = 5; $i >= 0; $i--) {
    $targetDate = date('Y-m-01', strtotime("-$i months"));
    $m = date('n', strtotime($targetDate));
    $y = date('Y', strtotime($targetDate));
    $monthName = date('M Y', strtotime($targetDate));

    $mIng = (float)$pdo->query("SELECT COALESCE(SUM(costo),0) FROM mantenimientos WHERE MONTH(fecha_ingreso)=$m AND YEAR(fecha_ingreso)=$y AND estado<>'Cancelado'")->fetchColumn();
    $mGas = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE MONTH(fecha)=$m AND YEAR(fecha)=$y")->fetchColumn();
    $mUtil = $mIng - $mGas;

    $monthlyChartData[] = [
        'label' => $monthName,
        'ingresos' => $mIng,
        'gastos' => $mGas,
        'utilidad' => $mUtil
    ];
}

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">CONTROL FINANCIERO & FLUJO DE CAJA</span>
    <h1>Contabilidad y Finanzas del Taller</h1>
    <p>Administra con precisión los ingresos por mano de obra, venta de repuestos, compras y gastos operativos en Pesos Colombianos (COP).</p>
  </div>
  <div class="actions">
    <a class="btn btn-light" href="api/exportar_contabilidad.php?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>">
      <span class="material-symbols-outlined">file_download</span>Exportar Informe Excel
    </a>
    <button class="btn btn-primary" type="button" onclick="openModal('expenseModal')">
      <span class="material-symbols-outlined">add_card</span>Registrar Gasto
    </button>
  </div>
</div>

<!-- Barra de Filtros por Período -->
<section class="panel" style="margin-bottom:20px">
  <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn <?= $periodo==='hoy'?'btn-primary':'btn-light' ?> btn-sm" href="contabilidad.php?periodo=hoy">Hoy</a>
      <a class="btn <?= $periodo==='semana'?'btn-primary':'btn-light' ?> btn-sm" href="contabilidad.php?periodo=semana">Últimos 7 días</a>
      <a class="btn <?= $periodo==='mes'?'btn-primary':'btn-light' ?> btn-sm" href="contabilidad.php?periodo=mes">Este Mes</a>
      <a class="btn <?= $periodo==='anio'?'btn-primary':'btn-light' ?> btn-sm" href="contabilidad.php?periodo=anio">Este Año</a>
      <a class="btn <?= $periodo==='todos'?'btn-primary':'btn-light' ?> btn-sm" href="contabilidad.php?periodo=todos">Histórico Completo</a>
    </div>

    <!-- Filtro por rango de fechas personalizado -->
    <form method="get" action="contabilidad.php" style="display:flex;gap:8px;align-items:center">
      <input type="date" class="input" style="padding:6px 9px;font-size:12px" name="desde" value="<?= e($desde) ?>" required title="Fecha inicial">
      <span class="muted">hasta</span>
      <input type="date" class="input" style="padding:6px 9px;font-size:12px" name="hasta" value="<?= e($hasta) ?>" required title="Fecha final">
      <button class="btn btn-primary btn-sm" type="submit">
        <span class="material-symbols-outlined">filter_alt</span>Aplicar
      </button>
    </form>
  </div>
</section>

<!-- Tarjetas KPIs Financieras -->
<div class="fin-grid">
  <div class="fin-card">
    <div class="fin-label">Total Ingresos Facturados</div>
    <div class="fin-val green"><?= money($totalIngresos) ?></div>
    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      Mano obra: <?= money($kpiIng['total_mano_obra']) ?><br>
      Repuestos: <?= money($kpiIng['total_repuestos']) ?>
    </div>
  </div>

  <div class="fin-card">
    <div class="fin-label">Total Gastos Operativos</div>
    <div class="fin-val danger"><?= money($totalGastos) ?></div>
    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      <?= (int)$kpiGas['total_num_gastos'] ?> compra(s) y egreso(s) registrados
    </div>
  </div>

  <div class="fin-card" style="border:2px solid <?= $utilidadNeta >= 0 ? 'var(--green-500)' : 'var(--danger)' ?>;background:#fafdff">
    <div class="fin-label" style="color:var(--ink)">
      <b>Utilidad Neta del Período</b>
      <small style="display:block;font-size:10px;color:var(--muted)">(Ingresos Facturados - Gastos Totales)</small>
    </div>
    <div class="fin-val <?= $utilidadNeta >= 0 ? 'green' : 'danger' ?>" style="font-size:26px">
      <?= money($utilidadNeta) ?>
    </div>
    <div style="font-size:11px;font-weight:700;color:<?= $utilidadNeta >= 0 ? 'var(--green-700)' : 'var(--danger)' ?>;margin-top:6px">
      <?= $utilidadNeta >= 0 ? '✓ Saldo Operativo Positivo' : '⚠️ Saldo Operativo Negativo' ?>
    </div>
  </div>

  <div class="fin-card">
    <div class="fin-label">Cobros & Pendientes</div>
    <div class="fin-val primary"><?= money($kpiIng['ingresos_cobrados']) ?></div>
    <div style="font-size:11px;color:var(--warning);font-weight:700;margin-top:6px">
      Pendiente por cobrar: <?= money($kpiIng['ingresos_pendientes']) ?>
    </div>
  </div>
</div>

<!-- Gráficos Estadísticos Financieros -->
<div class="grid-main" style="margin-bottom:24px">
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Evolución Financiera Semestral</h2>
        <p>Comportamiento de Ingresos vs Gastos vs Utilidad Neta (COP).</p>
      </div>
    </div>
    <div style="padding:20px;height:310px">
      <canvas id="financeTrendChart"></canvas>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Composición de Ingresos</h2>
        <p>Mano de obra vs Venta de repuestos.</p>
      </div>
    </div>
    <div style="padding:20px;height:310px;display:flex;align-items:center;justify-content:center">
      <canvas id="revenuePieChart"></canvas>
    </div>
  </section>
</div>

<!-- Registro de Servicios e Ingresos -->
<section class="panel" style="margin-bottom:24px">
  <div class="panel-head">
    <div>
      <h2>Detalle de Ingresos por Mantenimientos (<?= count($servicios) ?>)</h2>
      <p>Servicios registrados en el período seleccionado.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Servicio #</th>
          <th>Fecha Ingreso</th>
          <th>Bicicleta / Cliente</th>
          <th>Técnico</th>
          <th>Mano Obra</th>
          <th>Repuestos</th>
          <th>Descuento</th>
          <th>Total Facturado</th>
          <th>Estado Pago</th>
          <th>Método</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($servicios as $s): ?>
        <tr>
          <td class="id">#<?= e($s['id_manten']) ?></td>
          <td><?= format_date($s['fecha_ingreso']) ?></td>
          <td>
            <strong><?= e($s['b_codigo'] ?: '—') ?></strong><br>
            <span class="muted"><?= e($s['b_cliente'] ?: 'Taller') ?></span>
          </td>
          <td><?= e($s['nombre_tecnico'] ?: 'ID '.$s['id_tecnico']) ?></td>
          <td><?= money($s['mano_obra']) ?></td>
          <td><?= money($s['total_repuestos']) ?></td>
          <td><?= ((float)$s['descuento'] > 0) ? '- '.money($s['descuento']) : '—' ?></td>
          <td><b><?= money($s['costo']) ?></b></td>
          <td>
            <span class="badge badge-<?= ($s['estado_pago'] === 'pagado') ? 'success' : 'warning' ?>">
              <?= ucfirst(e($s['estado_pago'] ?: 'Pendiente')) ?>
            </span>
          </td>
          <td><?= e($s['metodo_pago'] ?: 'Efectivo') ?></td>
          <td>
            <div class="actions-cell">
              <?php if ($s['estado_pago'] !== 'pagado'): ?>
                <button class="btn btn-primary btn-sm" type="button" title="Marcar como Pagado" onclick="openPayModal(<?= (int)$s['id_manten'] ?>, <?= json_encode(money($s['costo'])) ?>)">
                  <span class="material-symbols-outlined">payments</span>Cobrar
                </button>
              <?php else: ?>
                <a class="btn btn-light btn-sm" href="imprimir_orden.php?id=<?= (int)$s['id_manten'] ?>" target="_blank" title="Imprimir Comprobante">
                  <span class="material-symbols-outlined">receipt</span>Recibo
                </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$servicios): ?>
        <tr><td colspan="11" class="empty">No hay servicios registrados en este período.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Registro de Gastos y Compras -->
<section class="panel">
  <div class="panel-head">
    <div>
      <h2>Detalle de Gastos Operativos y Compras (<?= count($gastos) ?>)</h2>
      <p>Egresos registrados para el taller (repuestos, insumos, herramientas, servicios).</p>
    </div>
    <button class="btn btn-primary btn-sm" type="button" onclick="openModal('expenseModal')">
      <span class="material-symbols-outlined">add</span>Nuevo Gasto
    </button>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Categoría</th>
          <th>Descripción</th>
          <th>Comprobante / Factura</th>
          <th>Método de Pago</th>
          <th>Registrado Por</th>
          <th>Monto Gasto (COP)</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($gastos as $g): ?>
        <tr>
          <td class="id">#<?= e($g['id_gasto']) ?></td>
          <td><?= e($g['fecha']) ?></td>
          <td><span class="badge badge-neutral"><?= e($g['categoria']) ?></span></td>
          <td><b><?= e($g['descripcion']) ?></b></td>
          <td><?= e($g['comprobante'] ?: '—') ?></td>
          <td><?= e($g['metodo_pago']) ?></td>
          <td><?= e($g['usuario_nombre'] ?: 'Admin') ?></td>
          <td><b style="color:var(--danger)">- <?= money($g['monto']) ?></b></td>
          <td>
            <form method="post" action="api/gasto.php" onsubmit="return confirm('¿Seguro que deseas eliminar este registro de gasto?')" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id_gasto" value="<?= (int)$g['id_gasto'] ?>">
              <button class="icon-btn" type="submit" title="Eliminar gasto" style="color:var(--danger)">
                <span class="material-symbols-outlined">delete</span>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$gastos): ?>
        <tr><td colspan="9" class="empty">No se registraron gastos en este período.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- MODAL REGISTRO DE GASTO -->
<div class="modal-backdrop" id="expenseModal">
  <div class="modal">
    <div class="modal-head">
      <h2>Registrar Nuevo Gasto del Taller</h2>
      <button class="icon-btn" type="button" onclick="closeModal('expenseModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/gasto.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="crear">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label>Categoría del Gasto <span class="required">*</span></label>
            <select class="select" name="categoria" required>
              <?php foreach (EXPENSE_CATEGORIES as $ec): ?>
                <option value="<?= e($ec) ?>"><?= e($ec) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Monto en Pesos Colombianos (COP) <span class="required">*</span></label>
            <input class="input" type="number" step="100" min="1" name="monto" required placeholder="Ej. 150000">
          </div>

          <div class="field full">
            <label>Descripción detallada <span class="required">*</span></label>
            <input class="input" name="descripcion" required placeholder="Ej. Compra de 5 juegos de llaves Allen y extractor de bielas">
          </div>

          <div class="field">
            <label>Fecha del Gasto <span class="required">*</span></label>
            <input class="input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="field">
            <label>Método de Pago</label>
            <select class="select" name="metodo_pago">
              <option value="Efectivo">Efectivo</option>
              <option value="Transferencia Bancaria">Transferencia Bancaria</option>
              <option value="Nequi / Daviplata">Nequi / Daviplata</option>
              <option value="Tarjeta de Crédito / Débito">Tarjeta de Crédito / Débito</option>
            </select>
          </div>

          <div class="field full">
            <label>N° Comprobante / Factura / Proveedor (Opcional)</label>
            <input class="input" name="comprobante" placeholder="Ej. Factura #4928 - Ferretería Central">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('expenseModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Gasto</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL REGISTRO DE COBRO -->
<div class="modal-backdrop" id="payModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head">
      <h2>Registrar Pago de Servicio</h2>
      <button class="icon-btn" type="button" onclick="closeModal('payModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/gasto.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="marcar_pago">
      <input type="hidden" name="id_manten" id="payMantenId">
      <div class="modal-body">
        <p style="font-size:14px;margin-top:0">
          Registrar cobro para la Orden <strong id="payOrderTxt"></strong><br>
          Monto a liquidar: <strong id="payMontoTxt" style="color:var(--green);font-size:16px"></strong>
        </p>
        <div class="field">
          <label>Método de Pago Recibido <span class="required">*</span></label>
          <select class="select" name="metodo_pago" required>
            <option value="Efectivo">Efectivo</option>
            <option value="Transferencia Bancaria">Transferencia Bancaria</option>
            <option value="Nequi / Daviplata">Nequi / Daviplata</option>
            <option value="Tarjeta de Crédito / Débito">Tarjeta de Crédito / Débito</option>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('payModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Confirmar Pago</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPayModal(id, monto){
  document.getElementById('payMantenId').value = id;
  document.getElementById('payOrderTxt').textContent = '#' + id;
  document.getElementById('payMontoTxt').textContent = monto;
  openModal('payModal');
}

// Configuración de Gráficos Chart.js
window.addEventListener('DOMContentLoaded', () => {
  const chartData = <?= json_encode($monthlyChartData) ?>;

  // Gráfico de Tendencia
  const ctxTrend = document.getElementById('financeTrendChart');
  if(ctxTrend){
    new Chart(ctxTrend, {
      type: 'bar',
      data: {
        labels: chartData.map(d => d.label),
        datasets: [
          {
            label: 'Ingresos Facturados (COP)',
            data: chartData.map(d => d.ingresos),
            backgroundColor: 'rgba(22, 160, 93, 0.75)',
            borderColor: '#16a05d',
            borderWidth: 1
          },
          {
            label: 'Gastos Operativos (COP)',
            data: chartData.map(d => d.gastos),
            backgroundColor: 'rgba(199, 61, 61, 0.75)',
            borderColor: '#c73d3d',
            borderWidth: 1
          },
          {
            type: 'line',
            label: 'Utilidad Neta (COP)',
            data: chartData.map(d => d.utilidad),
            borderColor: '#075c40',
            backgroundColor: 'rgba(7, 92, 64, 0.2)',
            tension: 0.3,
            fill: false,
            borderWidth: 2.5
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label: function(ctx){
                return ctx.dataset.label + ': $' + Number(ctx.raw).toLocaleString('es-CO') + ' COP';
              }
            }
          }
        },
        scales: {
          y: {
            ticks: {
              callback: function(v){ return '$' + (v >= 1000000 ? (v/1000000) + 'M' : (v/1000) + 'k'); }
            }
          }
        }
      }
    });
  }

  // Gráfico de Pastel Composición
  const ctxPie = document.getElementById('revenuePieChart');
  if(ctxPie){
    const totalLabor = <?= (float)$kpiIng['total_mano_obra'] ?>;
    const totalParts = <?= (float)$kpiIng['total_repuestos'] ?>;
    new Chart(ctxPie, {
      type: 'doughnut',
      data: {
        labels: ['Mano de Obra', 'Venta de Repuestos'],
        datasets: [{
          data: [totalLabor, totalParts],
          backgroundColor: ['#16a05d', '#35b96f'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function(ctx){
                return ctx.label + ': $' + Number(ctx.raw).toLocaleString('es-CO') + ' COP';
              }
            }
          }
        }
      }
    });
  }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
