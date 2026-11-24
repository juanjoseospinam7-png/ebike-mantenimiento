<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_client();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM bicicletas WHERE id_bicicleta = ?');
$stmt->execute([$id]);
$bike = $stmt->fetch();
if (!$bike) { flash('error', 'La bicicleta solicitada no existe.'); redirect('cliente.php'); }

$hoy = date('Y-m-d');
$pageTitle = 'Detalle de ' . ($bike['nombre'] ?: $bike['modelo']);
include __DIR__ . '/partials/header.php';
?>
<a class="back-link" href="cliente.php"><span class="material-symbols-outlined">arrow_back</span>Volver al catálogo</a>

<div class="detail-layout">
    <section class="detail-media">
        <div class="detail-photo <?= $bike['foto'] ? '' : 'is-placeholder' ?>">
            <?php if ($bike['foto'] && is_file(__DIR__ . '/' . $bike['foto'])): ?>
                <img src="<?= e($bike['foto']) ?>" alt="<?= e($bike['nombre'] ?: $bike['modelo']) ?>">
            <?php else: ?>
                <span class="material-symbols-outlined">electric_bike</span>
                <small>Fotografía pendiente de carga por el administrador de flota</small>
            <?php endif; ?>
        </div>
        <div class="detail-specs">
            <h3>Ficha técnica</h3>
            <div class="spec-grid">
                <div><span>Código único</span><strong><?= e($bike['codigo']) ?></strong></div>
                <div><span>Marca / modelo</span><strong><?= e($bike['marca']) ?> <?= e($bike['modelo']) ?></strong></div>
                <div><span>Tipo</span><strong><?= e($bike['tipo_bicicleta']) ?></strong></div>
                <div><span>Autonomía estimada</span><strong><?= (int)$bike['autonomia_km'] ?> km</strong></div>
                <div><span>Capacidad de batería</span><strong><?= (int)$bike['capacidad_bateria_wh'] ?> Wh</strong></div>
                <div><span>Nivel actual</span><strong><?= (int)$bike['nivel_bateria'] ?>%</strong></div>
                <div><span>Número de serie</span><strong><?= e($bike['numero_serie'] ?: 'Registrado internamente') ?></strong></div>
                <div><span>Incorporación</span><strong><?= e(format_date($bike['fecha_incorporacion'] ?: $bike['creado_en'])) ?></strong></div>
            </div>
            <h3>Características</h3>
            <p class="detail-copy"><?= e($bike['caracteristicas'] ?: $bike['descripcion'] ?: 'Bicicleta eléctrica de la flota eBike.') ?></p>
        </div>
    </section>

    <aside class="detail-book">
        <span class="badge badge-<?= status_class($bike['estado_operativo']) ?>"><?= e(bike_state_label($bike['estado_operativo'])) ?></span>
        <h1><?= e($bike['nombre'] ?: $bike['modelo']) ?></h1>
        <p class="muted"><?= e($bike['descripcion'] ?: 'Lista para acompañarte en tu próximo recorrido.') ?></p>
        <div class="price-list">
            <div><span>Tarifa por hora</span><strong><?= e(money($bike['precio_hora'])) ?></strong></div>
            <div><span>Tarifa por día</span><strong><?= e(money($bike['precio_dia'])) ?></strong></div>
        </div>
        <form method="post" action="api/alquiler.php" class="book-form">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="id_bicicleta" value="<?= (int)$bike['id_bicicleta'] ?>">
            <label>Fecha y hora de inicio
                <input class="input" type="datetime-local" name="fecha_inicio" id="fechaInicio"
                       min="<?= e(date('Y-m-d\\TH:i', strtotime('+30 minutes'))) ?>"
                       value="<?= e(date('Y-m-d\\TH:i', strtotime('+1 hour'))) ?>" required>
            </label>
            <label>Duración
                <select class="input" name="duracion_horas" id="duracion">
                    <option value="1">1 hora</option>
                    <option value="2" selected>2 horas</option>
                    <option value="4">4 horas</option>
                    <option value="8">8 horas</option>
                    <option value="24">1 día</option>
                    <option value="48">2 días</option>
                    <option value="72">3 días</option>
                </select>
            </label>
            <div class="estimate">
                <span>Fin estimado <strong id="finEstimado">—</strong></span>
                <span>Total <strong id="precioEstimado"><?= e(money(rental_price($bike, 2))) ?></strong></span>
            </div>
            <label>Notas para la entrega (opcional)
                <textarea class="input" name="notas" rows="2" maxlength="300" placeholder="Ej. entregar en la oficina de la Cra 45"></textarea>
            </label>
            <button class="btn btn-primary btn-block btn-lg" type="submit">
                <span class="material-symbols-outlined">event_available</span>Confirmar solicitud
            </button>
            <p class="fine-print">Se verificará la disponibilidad real antes de confirmar. El pago se registra como pendiente; no se realizan cobros en línea.</p>
        </form>
    </aside>
</div>
<script>
(function () {
    const inicio = document.getElementById('fechaInicio');
    const duracion = document.getElementById('duracion');
    const fin = document.getElementById('finEstimado');
    const precio = document.getElementById('precioEstimado');
    const tarifaHora = <?= (float)$bike['precio_hora'] ?>;
    const tarifaDia = <?= (float)$bike['precio_dia'] ?>;
    const fmt = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
    function update() {
        const horas = parseInt(duracion.value, 10);
        const start = inicio.value ? new Date(inicio.value) : null;
        if (start && !isNaN(start.getTime())) {
            const end = new Date(start.getTime() + horas * 3600000);
            fin.textContent = end.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
        }
        const total = horas >= 24 ? Math.ceil(horas / 24) * tarifaDia : horas * tarifaHora;
        precio.textContent = fmt.format(total);
    }
    inicio.addEventListener('change', update);
    duracion.addEventListener('change', update);
    update();
})();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
