<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_mechanic_or_admin();
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(b.codigo LIKE ? OR b.modelo LIKE ? OR b.nombre LIKE ? OR b.numero_serie LIKE ?)'; $v = "%$q%"; array_push($params, $v, $v, $v, $v); }
if ($estado !== '') { $where[] = 'b.estado_operativo = ?'; $params[] = $estado; }
$stmt = $pdo->prepare(
    "SELECT b.*,
            (SELECT COUNT(*) FROM alquileres a WHERE a.id_bicicleta=b.id_bicicleta) AS alquileres,
            (SELECT COUNT(*) FROM reportes_clientes r WHERE r.id_bicicleta=b.id_bicicleta) AS reportes
     FROM bicicletas b WHERE " . implode(' AND ', $where) . " ORDER BY b.codigo"
);
$stmt->execute($params); $bicis = $stmt->fetchAll();

$historial = null;
if (isset($_GET['historial'])) {
    $idHist = (int)$_GET['historial'];
    $stmt = $pdo->prepare('SELECT * FROM bicicletas WHERE id_bicicleta=?'); $stmt->execute([$idHist]); $historial = $stmt->fetch();
    if ($historial) {
        $stmt = $pdo->prepare('SELECT a.codigo_alquiler, a.fecha_inicio, a.estado, u.nombre FROM alquileres a JOIN usuarios u ON u.id=a.id_cliente WHERE a.id_bicicleta=? ORDER BY a.creado_en DESC LIMIT 20');
        $stmt->execute([$idHist]); $histAlq = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT i.estado_reporte, i.diagnostico, i.creado_en, e.nombre FROM intervenciones_mantenimiento i JOIN empleados e ON e.id_empleado=i.id_mecanico WHERE i.id_reporte IN (SELECT id_reporte FROM reportes_clientes WHERE id_bicicleta=?) ORDER BY i.creado_en DESC LIMIT 20');
        $stmt->execute([$idHist]); $histMant = $stmt->fetchAll();
    }
}
$pageTitle = 'Gestión de flota';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro"><span class="eyebrow">FLOTA DE ALQUILER</span><h1>Gestión de flota</h1><p>Cada eBike tiene un código único vitalicio, estado operativo, tarifas e historial completo.</p></section>

<div class="toolbar">
    <form class="filters" method="get" action="bicicletas.php">
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar código, modelo, serie…">
        <select class="input" name="estado"><option value="">Todos los estados</option>
            <?php foreach (BIKE_OPERATIVE_STATES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $estado === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-soft" type="submit">Filtrar</button>
    </form>
    <?php if (is_admin()): ?><button class="btn btn-primary" type="button" onclick="openModal('modalBici')"><span class="material-symbols-outlined">add</span>Incorporar eBike</button><?php endif; ?>
</div>

<div class="panel"><div class="table-wrap"><table class="table">
    <thead><tr><th>Código</th><th>eBike</th><th>Tipo / autonomía</th><th>Batería</th><th>Tarifas</th><th>Estado operativo</th><th>Historial</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($bicis as $b): ?>
        <tr>
            <td><span class="code-chip"><?= e($b['codigo']) ?></span></td>
            <td><strong><?= e($b['nombre'] ?: $b['modelo']) ?></strong><br><small><?= e($b['marca']) ?> <?= e($b['modelo']) ?></small></td>
            <td><?= e($b['tipo_bicicleta']) ?><br><small><?= (int)$b['autonomia_km'] ?> km · <?= (int)$b['capacidad_bateria_wh'] ?> Wh</small></td>
            <td><div class="battery-bar"><i style="width:<?= (int)$b['nivel_bateria'] ?>%"></i></div><small><?= (int)$b['nivel_bateria'] ?>%</small></td>
            <td><?= e(money($b['precio_hora'])) ?>/h<br><small><?= e(money($b['precio_dia'])) ?>/día</small></td>
            <td><span class="badge badge-<?= status_class($b['estado_operativo']) ?>"><?= e(bike_state_label($b['estado_operativo'])) ?></span></td>
            <td><a class="link" href="bicicletas.php?historial=<?= (int)$b['id_bicicleta'] ?>"><?= (int)$b['alquileres'] ?> alq. · <?= (int)$b['reportes'] ?> rep.</a></td>
            <td class="actions-cell">
                <?php if (is_admin()): ?>
                    <button class="icon-btn" type="button" onclick="editarBici(<?= (int)$b['id_bicicleta'] ?>)" title="Editar"><span class="material-symbols-outlined">edit</span></button>
                    <form method="post" action="api/bicicleta.php" class="inline-form"><?= csrf_field() ?>
                        <input type="hidden" name="accion" value="estado"><input type="hidden" name="id_bicicleta" value="<?= (int)$b['id_bicicleta'] ?>">
                        <select class="input input-sm" name="estado_operativo" onchange="this.form.submit()">
                            <?php foreach (BIKE_OPERATIVE_STATES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $b['estado_operativo'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?><span class="small muted">Solo lectura</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$bicis): ?><tr><td colspan="8" class="center">No hay bicicletas que coincidan.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>

<?php if (is_admin()): ?>
<div class="modal-backdrop" id="modalBici">
    <div class="modal modal-wide">
        <div class="modal-head"><h2 id="tituloModal">Incorporar eBike a la flota</h2><button class="icon-btn" onclick="closeModal('modalBici')" type="button"><span class="material-symbols-outlined">close</span></button></div>
        <form method="post" action="api/bicicleta.php" enctype="multipart/form-data" class="modal-body stack-form" id="formBici">
            <?= csrf_field() ?><input type="hidden" name="accion" value="crear" id="accionBici"><input type="hidden" name="id_bicicleta" value="0" id="idBici">
            <div class="form-grid">
                <label>Código único *<input class="input" name="codigo" id="bCodigo" required placeholder="EBK-00005"></label>
                <label>Modelo *<input class="input" name="modelo" id="bModelo" required></label>
                <label>Nombre comercial<input class="input" name="nombre" id="bNombre"></label>
                <label>Marca<input class="input" name="marca" id="bMarca" value="eBike"></label>
                <label>Tipo<select class="input" name="tipo_bicicleta" id="bTipo"><?php foreach (BIKE_TYPES as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></label>
                <label>Número de serie<input class="input" name="numero_serie" id="bSerie"></label>
                <label>Autonomía (km)<input class="input" type="number" name="autonomia_km" id="bAuto" min="0" value="50"></label>
                <label>Capacidad batería (Wh)<input class="input" type="number" name="capacidad_bateria_wh" id="bWh" min="0" value="504"></label>
                <label>Nivel de batería (%)<input class="input" type="number" name="nivel_bateria" id="bNivel" min="0" max="100" value="100"></label>
                <label>Precio por hora (COP)<input class="input" type="number" name="precio_hora" id="bHora" min="0" step="1000" value="18000"></label>
                <label>Precio por día (COP)<input class="input" type="number" name="precio_dia" id="bDia" min="0" step="1000" value="95000"></label>
                <label>Fecha de incorporación<input class="input" type="datetime-local" name="fecha_incorporacion" id="bFecha"></label>
                <label>Color<input class="input" name="color" id="bColor"></label>
                <label>Fotografía<input class="input" type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
            </div>
            <label>Características<textarea class="input" name="caracteristicas" id="bCarac" rows="2"></textarea></label>
            <label>Descripción<textarea class="input" name="descripcion" id="bDesc" rows="2"></textarea></label>
            <div class="modal-foot"><button class="btn btn-primary" type="submit">Guardar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($historial): ?>
<div class="modal-backdrop show">
    <div class="modal modal-wide">
        <div class="modal-head"><h2>Historial de <?= e($historial['codigo']) ?></h2><a class="icon-btn" href="bicicletas.php">✕</a></div>
        <div class="modal-body">
            <h3>Alquileres</h3>
            <?php foreach ($histAlq as $a): ?><div class="history-line"><strong><?= e($a['codigo_alquiler']) ?> · <?= e(rental_label($a['estado'])) ?></strong><p><?= e($a['nombre']) ?></p><small><?= e(format_date($a['fecha_inicio'])) ?></small></div><?php endforeach; ?>
            <?php if (!$histAlq): ?><p class="muted">Sin alquileres registrados.</p><?php endif; ?>
            <h3>Intervenciones de mantenimiento</h3>
            <?php foreach ($histMant as $m): ?><div class="history-line"><strong><?= e($m['estado_reporte']) ?> · <?= e($m['nombre']) ?></strong><p><?= e($m['diagnostico']) ?></p><small><?= e(format_date($m['creado_en'])) ?></small></div><?php endforeach; ?>
            <?php if (!$histMant): ?><p class="muted">Sin intervenciones registradas.</p><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const bikes = <?= json_encode($bicis, JSON_UNESCAPED_UNICODE) ?>;
function editarBici(id) {
    const b = bikes.find(x => Number(x.id_bicicleta) === id);
    if (!b) return;
    document.getElementById('tituloModal').textContent = 'Editar ' + b.codigo;
    document.getElementById('accionBici').value = 'editar';
    document.getElementById('idBici').value = id;
    document.getElementById('bCodigo').value = b.codigo;
    document.getElementById('bCodigo').readOnly = true;
    ['modelo','nombre','marca','tipo_bicicleta','numero_serie','autonomia_km','capacidad_bateria_wh','nivel_bateria','precio_hora','precio_dia','color','caracteristicas','descripcion']
        .forEach(k => { const el = document.getElementById('b' + ({
            modelo:'Modelo', nombre:'Nombre', marca:'Marca', tipo_bicicleta:'Tipo', numero_serie:'Serie',
            autonomia_km:'Auto', capacidad_bateria_wh:'Wh', nivel_bateria:'Nivel', precio_hora:'Hora',
            precio_dia:'Dia', color:'Color', caracteristicas:'Carac', descripcion:'Desc'
        }[k])); if (el) el.value = b[k] ?? ''; });
    document.getElementById('bFecha').value = b.fecha_incorporacion ? b.fecha_incorporacion.slice(0,16) : '';
    openModal('modalBici');
}
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
