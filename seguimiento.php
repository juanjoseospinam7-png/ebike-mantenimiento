<?php
session_start();
require_once __DIR__ . '/config/helpers.php';

$pageTitle = 'Seguimiento de Reparación en Vivo';
$pdo = db();

$codigo = trim($_GET['codigo'] ?? '');
$reporte = null;
$repuestos = [];
$logs = [];

if ($codigo !== '') {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               b.codigo AS b_codigo, b.modelo AS b_modelo, b.marca AS b_marca, b.tipo_bicicleta AS b_tipo, b.numero_serie AS b_serie,
               e.nombre AS nombre_tecnico, e.cargo AS cargo_tecnico,
               m.id_manten, m.costo, m.mano_obra, m.total_repuestos, m.descuento,
               m.diagnostico AS diag_manten, m.trabajos_realizados, m.observaciones AS obs_manten,
               m.estado AS estado_manten, m.estado_pago, m.metodo_pago, m.fecha_salida
        FROM reportes_clientes r
        LEFT JOIN bicicletas b ON b.id_bicicleta = r.id_bicicleta
        LEFT JOIN empleados e ON e.id_empleado = r.id_tecnico
        LEFT JOIN mantenimientos m ON m.id_manten = r.id_mantenimiento
        WHERE r.codigo_seguimiento = ? 
           OR b.codigo = ? 
           OR CAST(r.id_reporte AS CHAR) = ?
        ORDER BY r.id_reporte DESC
        LIMIT 1
    ");
    $stmt->execute([$codigo, $codigo, $codigo]);
    $reporte = $stmt->fetch();

    if ($reporte && !empty($reporte['id_mantenimiento'])) {
        $stmtRep = $pdo->prepare("
            SELECT mr.*, p.nombre_rep, p.codigo_rep
            FROM mantenimiento_repuestos mr
            INNER JOIN inventario_costos_arreglos p ON p.id_repuesto = mr.id_repuesto
            WHERE mr.id_manten = ?
        ");
        $stmtRep->execute([(int)$reporte['id_mantenimiento']]);
        $repuestos = $stmtRep->fetchAll();

        $stmtLogs = $pdo->prepare("
            SELECT l.*, e.nombre AS nombre_empleado
            FROM logs_auditoria l
            LEFT JOIN empleados e ON e.id_empleado = l.id_empleado
            WHERE l.id_manten = ?
            ORDER BY l.id_log ASC
        ");
        $stmtLogs->execute([(int)$reporte['id_mantenimiento']]);
        $logs = $stmtLogs->fetchAll();
    }
}

$workshop = get_workshop_config($pdo);

if (is_logged_in()) {
    include __DIR__ . '/partials/header.php';
} else {
    // Si no ha iniciado sesión, renderizar encabezado público limpio
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Seguimiento de Reparación | eBike EMS</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body style="background:var(--bg)">
    <div style="background:#fff;border-bottom:1px solid var(--line);padding:16px 28px;display:flex;justify-content:space-between;align-items:center">
        <a class="brand" href="login.php" style="border:0;padding:0;color:var(--ink)">
            <div class="brand-mark"><span class="material-symbols-outlined">pedal_bike</span></div>
            <div><strong>eBike EMS</strong><small>Portal Público de Seguimiento</small></div>
        </a>
        <a class="btn btn-primary" href="login.php">
            <span class="material-symbols-outlined">login</span>Iniciar Sesión
        </a>
    </div>
    <main class="content" style="max-width:1100px;margin:24px auto;padding:0 20px">
    <?php
}
?>

<div class="hero">
  <div>
    <span class="eyebrow">CONSULTA EN TIEMPO REAL</span>
    <h1>Seguimiento de Orden de Servicio</h1>
    <p>Ingresa tu código único de reporte (ej. REP-2026-XXXXX) o el código de tu eBike para conocer el estado técnico.</p>
  </div>
</div>

<!-- Buscador de Seguimiento -->
<section class="panel" style="margin-bottom:24px">
  <form method="get" action="seguimiento.php" style="padding:20px;display:flex;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <input class="input" name="codigo" value="<?= e($codigo) ?>" placeholder="Ingresa código de seguimiento (ej. REP-2026-...) o código de tu eBike" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">search</span>Consultar Estado
    </button>
    <?php if (is_client()): ?>
      <a class="btn btn-light" href="cliente.php">Mis solicitudes</a>
    <?php endif; ?>
  </form>
</section>

<?php if ($codigo !== '' && !$reporte): ?>
  <div class="alert alert-warning">
    <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px">warning</span>
    No encontramos ningún reporte ni orden asociada al código "<b><?= e($codigo) ?></b>". Verifica los caracteres o comunícate con el taller.
  </div>
<?php endif; ?>

<?php if ($reporte): ?>
  <?php
    $estadoActual = $reporte['estado_manten'] ?: $reporte['estado'];
    $isDone = in_array(mb_strtolower($estadoActual), ['reparado', 'entregado', 'completado', 'finalizado', 'listo']);
    $isDelivered = in_array(mb_strtolower($estadoActual), ['entregado']);
    
    // Lista canónica de los 9 estados normativos
    $estadosTimeline = [
        'Recibido',
        'Pendiente de revisión',
        'En diagnóstico',
        'Esperando repuestos',
        'En reparación',
        'En pruebas',
        'Reparado',
        'Entregado'
    ];
    $idxActual = array_search($estadoActual, $estadosTimeline);
    if ($idxActual === false) {
        if ($estadoActual === 'Cancelado') {
            $idxActual = -1;
        } else {
            $idxActual = 2; // fallback diagnóstico
        }
    }
  ?>

  <!-- Banner de Estado -->
  <div class="card" style="padding:24px;margin-bottom:24px;border-left:5px solid <?= $isDone ? 'var(--green)' : 'var(--info)' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px">
      <div>
        <span class="eyebrow">CÓDIGO DE SEGUIMIENTO</span>
        <h2 style="font:700 28px monospace;margin:4px 0 6px;color:var(--green-900)"><?= e($reporte['codigo_seguimiento'] ?: ('REP-'.$reporte['id_reporte'])) ?></h2>
        <div style="font-size:14px;color:var(--muted)">
          Vehículo: <b><?= e($reporte['b_codigo'] ?: $reporte['codigo_bici']) ?></b> · <?= e($reporte['b_marca'] ?: $reporte['marca']) ?> <?= e($reporte['b_modelo'] ?: $reporte['modelo']) ?>
          (<?= e($reporte['b_tipo'] ?: ($reporte['tipo_bicicleta'] ?: 'eBike')) ?>)
        </div>
      </div>
      <div style="text-align:right">
        <span class="badge badge-<?= status_class($estadoActual) ?>" style="font-size:14px;padding:8px 14px">
          <?= e($estadoActual) ?>
        </span>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">Fecha de ingreso: <?= format_date($reporte['creado_en']) ?></div>
      </div>
    </div>

    <?php if ($isDone && !$isDelivered): ?>
      <div style="background:#eaf8ef;border:1px solid #c7ebd4;border-radius:10px;padding:14px;margin-top:16px;display:flex;align-items:center;gap:12px">
        <span class="material-symbols-outlined" style="color:var(--green);font-size:32px">task_alt</span>
        <div>
          <strong style="color:var(--green-900);font-size:15px">¡Tu eBike ya está reparada y lista para entrega!</strong>
          <p style="margin:2px 0 0;font-size:13px;color:var(--muted)">Puedes acercarte al taller dentro del horario de atención para retirar tu vehículo.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid-main" style="grid-template-columns:minmax(0,1fr) 360px">
    <!-- Columna Principal -->
    <div style="display:grid;gap:20px">
      <!-- Línea de tiempo visual de los 9 estados -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Progreso de la Reparación</h2>
            <p>Evolución técnica desde la recepción hasta la entrega al cliente.</p>
          </div>
        </div>
        <div style="padding:24px">
          <?php if ($estadoActual === 'Cancelado'): ?>
            <div class="alert alert-error">Este servicio fue cancelado.</div>
          <?php else: ?>
            <div class="tracking-timeline">
              <?php foreach ($estadosTimeline as $i => $estName): ?>
                <?php
                  $isStepDone = ($idxActual > $i) || ($isDone && $i <= $idxActual);
                  $isStepActive = ($idxActual === $i);
                ?>
                <div class="tracking-step <?= $isStepDone ? 'done' : ($isStepActive ? 'active' : '') ?>">
                  <div class="tracking-dot">
                    <?php if ($isStepDone): ?>
                      <span class="material-symbols-outlined" style="font-size:14px">check</span>
                    <?php else: ?>
                      <?= $i + 1 ?>
                    <?php endif; ?>
                  </div>
                  <div class="tracking-step-title"><?= e($estName) ?></div>
                  <div class="tracking-step-desc">
                    <?php if ($isStepActive): ?>
                      <span style="color:var(--info);font-weight:700">▶ En este estado actualmente</span>
                    <?php elseif ($isStepDone): ?>
                      <span style="color:var(--green-700)">Completado</span>
                    <?php else: ?>
                      Pendiente
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Falla Reportada y Diagnóstico Técnico -->
      <section class="panel">
        <div class="panel-head">
          <div>
            <h2>Diagnóstico & Trabajos Realizados</h2>
            <p>Comunicación transparente del equipo mecánico sobre tu bicicleta.</p>
          </div>
        </div>
        <div style="padding:20px;display:grid;gap:16px">
          <div style="background:var(--green-50);padding:14px;border-radius:10px">
            <span class="eyebrow">PROBLEMA INICIAL REPORTADO (Categoría: <?= e($reporte['categoria_problema']) ?>)</span>
            <p style="margin:6px 0 0;font-size:13px;line-height:1.5;color:var(--ink)"><?= nl2br(e($reporte['descripcion'])) ?></p>
            <?php if (!empty($reporte['observaciones'])): ?>
              <small class="muted" style="display:block;margin-top:6px">Observaciones del cliente: <?= e($reporte['observaciones']) ?></small>
            <?php endif; ?>
          </div>

          <?php if (!empty($reporte['foto'])): ?>
            <div>
              <span class="eyebrow">FOTOGRAFÍA DEL REPORTE</span>
              <div style="margin-top:6px">
                <img src="<?= e($reporte['foto']) ?>" alt="Foto reporte" style="max-width:100%;max-height:260px;border-radius:10px;border:1px solid var(--line);object-fit:cover">
              </div>
            </div>
          <?php endif; ?>

          <div>
            <span class="eyebrow">DIAGNÓSTICO TÉCNICO OFICIAL</span>
            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-top:6px;padding:12px;border:1px solid var(--line);border-radius:10px;background:#fff">
              <?= e($reporte['diag_manten'] ?: 'El técnico se encuentra realizando las pruebas diagnósticas.') ?>
            </div>
          </div>

          <?php if (!empty($reporte['trabajos_realizados'])): ?>
            <div>
              <span class="eyebrow">TRABAJOS Y AJUSTES REALIZADOS</span>
              <div style="font-size:13px;color:var(--ink);margin-top:6px;padding:12px;border:1px solid var(--line);border-radius:10px;background:#fff;line-height:1.5">
                <?= nl2br(e($reporte['trabajos_realizados'])) ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($reporte['obs_manten'])): ?>
            <div>
              <span class="eyebrow">RECOMENDACIONES DEL MECÁNICO</span>
              <div style="font-size:13px;color:#5a6e64;margin-top:4px;font-style:italic">
                "<?= e($reporte['obs_manten']) ?>"
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Detalle de Repuestos Utilizados -->
      <?php if ($repuestos): ?>
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2>Repuestos y Componentes Instalados</h2>
              <p>Desglose con garantía de piezas originales.</p>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Código</th>
                  <th>Repuesto</th>
                  <th>Cantidad</th>
                  <th>Precio Unitario</th>
                  <th>Subtotal</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($repuestos as $rp): ?>
                  <tr>
                    <td><code><?= e($rp['codigo_rep'] ?: ('REP-'.$rp['id_repuesto'])) ?></code></td>
                    <td><b><?= e($rp['nombre_rep']) ?></b></td>
                    <td><?= (int)$rp['cantidad'] ?> ud(s)</td>
                    <td><?= money($rp['precio_unitario']) ?></td>
                    <td><b><?= money($rp['cantidad'] * $rp['precio_unitario']) ?></b></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <!-- Columna Lateral: Presupuesto & Contacto -->
    <div style="display:grid;gap:20px;align-content:start">
      <section class="panel">
        <div class="panel-head" style="border-bottom:1px solid var(--line)">
          <h3>Presupuesto y Liquidación</h3>
        </div>
        <div style="padding:20px">
          <div class="stat-line">
            <span>Mano de Obra Calificada:</span>
            <strong><?= money($reporte['mano_obra'] ?? 0) ?></strong>
          </div>
          <div class="stat-line">
            <span>Repuestos y Componentes:</span>
            <strong><?= money($reporte['total_repuestos'] ?? 0) ?></strong>
          </div>
          <?php if (!empty($reporte['descuento']) && (float)$reporte['descuento'] > 0): ?>
            <div class="stat-line" style="color:var(--green)">
              <span>Descuento aplicado:</span>
              <strong>- <?= money($reporte['descuento']) ?></strong>
            </div>
          <?php endif; ?>
          <hr style="border:0;border-top:1px dashed var(--line);margin:12px 0">
          <div class="stat-line" style="font-size:15px">
            <strong>TOTAL DEL SERVICIO:</strong>
            <strong style="color:var(--green-900);font-size:18px"><?= money($reporte['costo'] ?? 0) ?></strong>
          </div>
          <div style="margin-top:12px;padding:8px 12px;border-radius:8px;background:var(--green-50);display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:11px;font-weight:700;color:var(--green-800)">ESTADO DEL PAGO:</span>
            <span class="badge badge-<?= ($reporte['estado_pago'] === 'pagado') ? 'success' : 'warning' ?>">
              <?= ucfirst($reporte['estado_pago'] ?: 'Pendiente') ?>
            </span>
          </div>

          <?php if (!empty($reporte['id_mantenimiento'])): ?>
            <div style="margin-top:16px">
              <a class="btn btn-light" href="imprimir_orden.php?id=<?= (int)$reporte['id_mantenimiento'] ?>" target="_blank" style="width:100%;justify-content:center">
                <span class="material-symbols-outlined">print</span>Imprimir Ficha Técnica / Recibo
              </a>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Ficha del Taller y Asistencia -->
      <section class="panel">
        <div class="panel-head">
          <h3>Información del Taller</h3>
        </div>
        <div style="padding:18px;font-size:12px;line-height:1.6;color:var(--ink)">
          <p style="margin:0 0 6px"><strong><?= e($workshop['nombre_taller'] ?? 'eBike Maintenance System') ?></strong></p>
          <p style="margin:0 0 4px">📍 <?= e($workshop['direccion'] ?? 'Cra 45 # 128 - 20, Bogotá') ?></p>
          <p style="margin:0 0 4px">📞 Tel: <?= e($workshop['telefono'] ?? '+57 310 987 6543') ?></p>
          <p style="margin:0 0 8px">✉️ <?= e($workshop['email'] ?? 'contacto@ebiketaller.com') ?></p>
          <div style="background:var(--green-50);padding:8px 10px;border-radius:8px;color:var(--green-800);font-size:11px;font-weight:600">
            Garantía estándar: <?= e($workshop['garantia_dias'] ?? '90') ?> días en mano de obra.
          </div>
        </div>
      </section>
    </div>
  </div>
<?php endif; ?>

<?php
if (is_logged_in()) {
    include __DIR__ . '/partials/footer.php';
} else {
    ?>
    </main>
    <footer style="text-align:center;padding:24px;color:var(--muted);font-size:12px;border-top:1px solid var(--line);margin-top:40px">
        eBike Maintenance System (EMS) · Sistema Profesional de Taller de Bicicletas Eléctricas
    </footer>
    </body>
    </html>
    <?php
}
?>
