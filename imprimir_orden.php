<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('error', 'ID de mantenimiento inválido.');
    redirect('index.php');
}

$stmt = $pdo->prepare("
    SELECT m.*, 
           b.codigo AS b_codigo, b.modelo AS b_modelo, b.marca AS b_marca, b.tipo_bicicleta AS b_tipo, 
           b.numero_serie AS b_serie, b.color AS b_color, b.cliente AS b_cliente, b.telefono_cliente AS b_tel,
           e.nombre AS nombre_tecnico, e.cargo AS cargo_tecnico,
           r.codigo_seguimiento, r.nombre_cliente, r.telefono_cliente, r.categoria_problema, r.descripcion AS desc_reporte,
           u.email AS cliente_email
    FROM mantenimientos m
    LEFT JOIN bicicletas b ON b.id_bicicleta = m.id_bicicleta
    LEFT JOIN empleados e ON e.id_empleado = m.id_tecnico
    LEFT JOIN reportes_clientes r ON r.id_reporte = m.id_reporte_cliente
    LEFT JOIN usuarios u ON u.id = b.id_usuario
    WHERE m.id_manten = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    die('Orden de mantenimiento no encontrada.');
}

// Si es cliente, verificar que le pertenezca
if (is_client()) {
    $myId = (int)$_SESSION['ebike_user']['id'];
    $myName = $_SESSION['ebike_user']['nombre'];
    if ($order['b_cliente'] !== $myName && $order['nombre_cliente'] !== $myName) {
        die('Acceso denegado a esta orden.');
    }
}

// Repuestos
$stmtRep = $pdo->prepare("
    SELECT mr.*, p.nombre_rep, p.codigo_rep
    FROM mantenimiento_repuestos mr
    INNER JOIN inventario_costos_arreglos p ON p.id_repuesto = mr.id_repuesto
    WHERE mr.id_manten = ?
");
$stmtRep->execute([$id]);
$parts = $stmtRep->fetchAll();

$cfg = get_workshop_config($pdo);
$nombreCliente = $order['nombre_cliente'] ?: ($order['b_cliente'] ?: 'Cliente de Taller');
$telCliente = $order['telefono_cliente'] ?: ($order['b_tel'] ?: 'Sin teléfono');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Orden de Servicio #<?= $id ?> | <?= e($cfg['nombre_taller'] ?? 'eBike EMS') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:24px;background:#f6faf7;font-family:Inter,Arial,sans-serif;color:#13231d;font-size:13px}
  .order-paper{max-width:820px;margin:auto;background:#fff;border:1px solid #dce8e1;border-radius:14px;padding:34px;box-shadow:0 8px 30px rgba(15,55,40,.06)}
  .order-top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #075c40;padding-bottom:18px;margin-bottom:20px}
  .brand-title{font:800 24px 'Plus Jakarta Sans';color:#073b2a;margin:0 0 4px}
  .brand-info{font-size:11px;color:#557065;line-height:1.4}
  .order-badge-box{text-align:right}
  .order-num{font:800 22px monospace;color:#087a50;margin:0}
  .order-tracking{font:700 12px monospace;color:#66766f;margin-top:4px}
  .box-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
  .info-box{background:#f9fcf9;border:1px solid #e1eee6;border-radius:10px;padding:12px 14px}
  .info-box-title{font:700 11px 'Plus Jakarta Sans';text-transform:uppercase;color:#087a50;margin-bottom:8px;letter-spacing:.05em}
  .info-line{margin:4px 0;font-size:12px}
  .info-line b{color:#2a3b34}
  .table-order{width:100%;border-collapse:collapse;margin:16px 0}
  .table-order th{background:#f0f7f3;color:#446154;font-size:11px;text-transform:uppercase;padding:9px 12px;text-align:left;border-bottom:1px solid #dce8e1}
  .table-order td{padding:10px 12px;border-bottom:1px solid #edf4f0;font-size:12px}
  .totals-wrap{display:flex;justify-content:flex-end;margin:18px 0}
  .totals-box{width:320px;background:#f9fcf9;border:1px solid #dce8e1;border-radius:10px;padding:14px}
  .tot-line{display:flex;justify-content:space-between;padding:4px 0;font-size:12px}
  .tot-line.grand{border-top:1px dashed #cfded6;margin-top:6px;padding-top:8px;font-size:16px;font-weight:800;color:#073b2a}
  .signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:46px;padding-top:18px}
  .sig-block{border-top:1px solid #5a7569;text-align:center;padding-top:8px;font-size:12px;color:#557065}
  .sig-block b{color:#13231d;display:block;margin-bottom:2px}
  .print-actions{max-width:820px;margin:0 auto 16px;display:flex;justify-content:space-between;align-items:center}
  .btn{padding:9px 14px;border-radius:8px;border:0;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:13px;text-decoration:none}
  .btn-primary{background:#16a05d;color:#fff}
  .btn-light{background:#fff;border:1px solid #dce8e1;color:#13231d}
  @media print{
    body{padding:0;background:#fff}
    .print-actions{display:none!important}
    .order-paper{border:0;box-shadow:none;padding:0;max-width:100%}
  }
</style>
</head>
<body>

<div class="print-actions">
  <a class="btn btn-light" href="mantenimientos.php">
    <span class="material-symbols-outlined">arrow_back</span>Volver
  </a>
  <button class="btn btn-primary" onclick="window.print()">
    <span class="material-symbols-outlined">print</span>Imprimir Orden de Servicio (PDF)
  </button>
</div>

<article class="order-paper">
  <div class="order-top">
    <div>
      <h1 class="brand-title"><?= e($cfg['nombre_taller'] ?? 'eBike Maintenance System (EMS)') ?></h1>
      <div class="brand-info">
        NIT: <?= e($cfg['nit'] ?? '901.845.123-4') ?><br>
        Dirección: <?= e($cfg['direccion'] ?? 'Cra 45 # 128 - 20, Bogotá') ?><br>
        Tel: <?= e($cfg['telefono'] ?? '+57 310 987 6543') ?> · Email: <?= e($cfg['email'] ?? 'contacto@ebiketaller.com') ?>
      </div>
    </div>
    <div class="order-badge-box">
      <div class="order-num">ORDEN #<?= str_pad((string)$order['id_manten'], 5, '0', STR_PAD_LEFT) ?></div>
      <div class="order-tracking">Seguimiento: <?= e($order['codigo_seguimiento'] ?: 'S/N') ?></div>
      <div style="font-size:11px;color:#557065;margin-top:4px">
        Estado: <b><?= e($order['estado']) ?></b><br>
        Ingreso: <?= format_date($order['fecha_ingreso']) ?>
      </div>
    </div>
  </div>

  <div class="box-grid">
    <!-- Datos del Cliente -->
    <div class="info-box">
      <div class="info-box-title">Datos del Cliente</div>
      <div class="info-line"><b>Nombre:</b> <?= e($nombreCliente) ?></div>
      <div class="info-line"><b>Teléfono:</b> <?= e($telCliente) ?></div>
      <div class="info-line"><b>Correo:</b> <?= e($order['cliente_email'] ?: 'No registrado') ?></div>
    </div>

    <!-- Datos de la Bicicleta -->
    <div class="info-box">
      <div class="info-box-title">Identificación del Vehículo</div>
      <div class="info-line"><b>Código / Placa:</b> <?= e($order['b_codigo'] ?: '—') ?></div>
      <div class="info-line"><b>Marca & Modelo:</b> <?= e($order['b_marca']) ?> <?= e($order['b_modelo']) ?></div>
      <div class="info-line"><b>Tipo & Color:</b> <?= e($order['b_tipo'] ?: 'Urbana') ?> · <?= e($order['b_color'] ?: 'Color estándar') ?></div>
      <div class="info-line"><b>N° Serie / Chasis:</b> <?= e($order['b_serie'] ?: 'No registrado') ?></div>
    </div>
  </div>

  <div class="info-box" style="margin-bottom:18px">
    <div class="info-box-title">Diagnóstico y Trabajos Técnicos</div>
    <div class="info-line"><b>Técnico Responsable:</b> <?= e($order['nombre_tecnico'] ?: 'Taller Central') ?> (<?= e($order['cargo_tecnico'] ?: 'Técnico Especialista') ?>)</div>
    <div class="info-line"><b>Tipo de Servicio:</b> <?= e($order['tipo_manten']) ?> · Prioridad: <?= ucfirst(e($order['prioridad'])) ?></div>
    <div class="info-line" style="margin-top:6px"><b>Diagnóstico Oficial:</b> <?= e($order['diagnostico']) ?></div>
    <?php if (!empty($order['trabajos_realizados'])): ?>
      <div class="info-line"><b>Trabajos Realizados:</b> <?= nl2br(e($order['trabajos_realizados'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($order['observaciones'])): ?>
      <div class="info-line"><b>Recomendaciones:</b> <?= nl2br(e($order['observaciones'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- Repuestos -->
  <table class="table-order">
    <thead>
      <tr>
        <th>Código</th>
        <th>Repuesto / Componente</th>
        <th style="text-align:center">Cantidad</th>
        <th style="text-align:right">Precio Unitario</th>
        <th style="text-align:right">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($parts as $p): ?>
        <tr>
          <td><code><?= e($p['codigo_rep'] ?: ('REP-'.$p['id_repuesto'])) ?></code></td>
          <td><b><?= e($p['nombre_rep']) ?></b></td>
          <td style="text-align:center"><?= (int)$p['cantidad'] ?></td>
          <td style="text-align:right"><?= money($p['precio_unitario']) ?></td>
          <td style="text-align:right"><b><?= money($p['cantidad'] * $p['precio_unitario']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$parts): ?>
        <tr><td colspan="5" style="text-align:center;color:#66766f;padding:14px">Sin repuestos facturados en esta orden.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Liquidación de Totales -->
  <div class="totals-wrap">
    <div class="totals-box">
      <div class="tot-line">
        <span>Mano de Obra Calificada:</span>
        <strong><?= money($order['mano_obra']) ?></strong>
      </div>
      <div class="tot-line">
        <span>Total Repuestos:</span>
        <strong><?= money($order['total_repuestos']) ?></strong>
      </div>
      <?php if ((float)$order['descuento'] > 0): ?>
        <div class="tot-line" style="color:#087a50">
          <span>Descuento Especial:</span>
          <strong>- <?= money($order['descuento']) ?></strong>
        </div>
      <?php endif; ?>
      <div class="tot-line grand">
        <span>TOTAL FACTURADO:</span>
        <span><?= money($order['costo']) ?></span>
      </div>
      <div style="margin-top:10px;padding:6px 8px;border-radius:6px;background:#eef8f2;font-size:11px;display:flex;justify-content:space-between">
        <span><b>ESTADO PAGO:</b> <?= strtoupper(e($order['estado_pago'])) ?></span>
        <span><?= e($order['metodo_pago'] ?: 'Efectivo') ?></span>
      </div>
    </div>
  </div>

  <div style="font-size:11px;color:#557065;border:1px dashed #dce8e1;border-radius:8px;padding:10px;line-height:1.5">
    <b>Términos de Garantía:</b> Este servicio cuenta con <?= e($cfg['garantia_dias'] ?? '90') ?> días de garantía en mano de obra sobre los componentes intervenidos. La garantía no cubre daños por golpes, inmersión en agua ni modificaciones no autorizadas al sistema eléctrico.
  </div>

  <div class="signatures">
    <div class="sig-block">
      <b><?= e($order['nombre_tecnico'] ?: 'Firma Mecánico') ?></b>
      Técnico Especialista Responsable
    </div>
    <div class="sig-block">
      <b><?= e($nombreCliente) ?></b>
      Recibido a Conformidad por el Cliente
    </div>
  </div>
</article>

</body>
</html>
