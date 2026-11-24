<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$autonomia = (int)($_GET['autonomia'] ?? 0);
$precioMax = (float)($_GET['precio'] ?? 0);
$caracteristica = trim((string)($_GET['caracteristica'] ?? ''));
$soloDisponibles = ($_GET['disp'] ?? '1') === '1';

$where = ['1=1'];
$params = [];
if ($q !== '') { $where[] = '(b.nombre LIKE ? OR b.modelo LIKE ? OR b.marca LIKE ? OR b.codigo LIKE ?)'; $v = "%$q%"; array_push($params, $v, $v, $v, $v); }
if ($tipo !== '') { $where[] = 'b.tipo_bicicleta = ?'; $params[] = $tipo; }
if ($autonomia > 0) { $where[] = 'b.autonomia_km >= ?'; $params[] = $autonomia; }
if ($precioMax > 0) { $where[] = 'b.precio_hora <= ?'; $params[] = $precioMax; }
if ($caracteristica !== '') { $where[] = 'b.caracteristicas LIKE ?'; $params[] = "%$caracteristica%"; }
if ($soloDisponibles) { $where[] = "b.activa = 1 AND b.estado_operativo IN ('disponible','reservada') AND b.nivel_bateria >= 15"; }

$stmt = $pdo->prepare('SELECT * FROM bicicletas b WHERE ' . implode(' AND ', $where) . ' ORDER BY b.estado_operativo, b.precio_hora ASC, b.id_bicicleta');
$stmt->execute($params);
$bikes = $stmt->fetchAll();

$tipos = $pdo->query("SELECT DISTINCT tipo_bicicleta FROM bicicletas WHERE tipo_bicicleta IS NOT NULL ORDER BY tipo_bicicleta")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = 'Explorar eBikes';
include __DIR__ . '/partials/header.php';
?>
<section class="shop-hero">
    <div class="shop-hero-copy">
        <span class="eyebrow">ALQUILER DE BICICLETAS ELÉCTRICAS</span>
        <h1>Tu próxima salida empieza aquí.</h1>
        <p>Explora nuestra flota, compara autonomía y precios, y reserva en minutos. Sin papeleo, sin complicaciones.</p>
    </div>
    <form class="shop-search" method="get" action="cliente.php">
        <span class="material-symbols-outlined">search</span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Busca por modelo, tipo o código (EBK-…)">
        <button class="btn btn-primary" type="submit">Buscar</button>
    </form>
</section>

<form class="filter-bar" method="get" action="cliente.php">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <label>Tipo
        <select class="input" name="tipo">
            <option value="">Todos los tipos</option>
            <?php foreach ($tipos as $t): ?>
                <option value="<?= e($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Autonomía mínima
        <select class="input" name="autonomia">
            <option value="0">Cualquiera</option>
            <option value="30" <?= $autonomia === 30 ? 'selected' : '' ?>>30+ km</option>
            <option value="50" <?= $autonomia === 50 ? 'selected' : '' ?>>50+ km</option>
            <option value="65" <?= $autonomia === 65 ? 'selected' : '' ?>>65+ km</option>
        </select>
    </label>
    <label>Precio hora hasta
        <select class="input" name="precio">
            <option value="0">Sin límite</option>
            <option value="15000" <?= $precioMax == 15000 ? 'selected' : '' ?>>$ 15.000</option>
            <option value="20000" <?= $precioMax == 20000 ? 'selected' : '' ?>>$ 20.000</option>
            <option value="25000" <?= $precioMax == 25000 ? 'selected' : '' ?>>$ 25.000</option>
        </select>
    </label>
    <label>Característica
        <input class="input" name="caracteristica" value="<?= e($caracteristica) ?>" placeholder="Ej. frenos, plegable, USB">
    </label>
    <label class="check-field"><input type="checkbox" name="disp" value="1" <?= $soloDisponibles ? 'checked' : '' ?>> Solo disponibles</label>
    <button class="btn btn-soft" type="submit"><span class="material-symbols-outlined">filter_alt</span>Filtrar</button>
    <a class="btn btn-ghost" href="cliente.php">Limpiar</a>
</form>

<div class="section-head">
    <div><h2>Nuestra flota</h2><p><?= count($bikes) ?> eBikes encontradas</p></div>
</div>

<div class="catalog-grid">
    <?php foreach ($bikes as $b): ?>
        <?php $rentable = (int)$b['activa'] === 1 && in_array($b['estado_operativo'], ['disponible', 'reservada'], true) && (int)$b['nivel_bateria'] >= 15; ?>
        <article class="product-card">
            <a class="product-photo <?= $b['foto'] ? '' : 'is-placeholder' ?>" href="bicicleta_detalle.php?id=<?= (int)$b['id_bicicleta'] ?>">
                <?php if ($b['foto'] && is_file(__DIR__ . '/' . $b['foto'])): ?>
                    <img src="<?= e($b['foto']) ?>" alt="<?= e($b['nombre'] ?: $b['modelo']) ?>">
                <?php else: ?>
                    <span class="material-symbols-outlined">electric_bike</span>
                <?php endif; ?>
                <span class="product-code"><?= e($b['codigo']) ?></span>
            </a>
            <div class="product-body">
                <div class="product-title-row">
                    <h3><?= e($b['nombre'] ?: $b['modelo']) ?></h3>
                    <span class="badge badge-<?= status_class($b['estado_operativo']) ?>"><?= e(bike_state_label($b['estado_operativo'])) ?></span>
                </div>
                <p class="product-model"><?= e($b['marca']) ?> · <?= e($b['modelo']) ?> · <?= e($b['tipo_bicicleta']) ?></p>
                <div class="product-chips">
                    <span><span class="material-symbols-outlined">route</span><?= (int)$b['autonomia_km'] ?> km</span>
                    <span><span class="material-symbols-outlined">battery_charging_full</span><?= (int)$b['nivel_bateria'] ?>%</span>
                    <span><span class="material-symbols-outlined">bolt</span><?= (int)$b['capacidad_bateria_wh'] ?> Wh</span>
                </div>
                <p class="product-features"><?= e(mb_strimwidth((string)($b['caracteristicas'] ?: $b['descripcion'] ?: 'Bicicleta eléctrica de la flota eBike'), 0, 90, '…')) ?></p>
                <div class="product-price">
                    <div><small>Por hora</small><strong><?= e(money($b['precio_hora'])) ?></strong></div>
                    <div><small>Por día</small><strong><?= e(money($b['precio_dia'])) ?></strong></div>
                </div>
                <a class="btn btn-primary btn-block" href="bicicleta_detalle.php?id=<?= (int)$b['id_bicicleta'] ?>">
                    <span class="material-symbols-outlined"><?= $rentable ? 'arrow_forward' : 'info' ?></span>
                    <?= $rentable ? 'Consultar y alquilar' : 'Ver detalles' ?>
                </a>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$bikes): ?>
        <div class="empty full"><span class="material-symbols-outlined">search_off</span><h3>No encontramos eBikes con esos filtros</h3><p>Prueba ampliar la búsqueda o quitar algún filtro.</p></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
