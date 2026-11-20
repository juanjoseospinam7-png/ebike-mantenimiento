<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();

$pageTitle = 'Configuración del Taller';
$pdo = db();
$cfg = get_workshop_config($pdo);

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">ADMINISTRACIÓN · SISTEMA EMS</span>
    <h1>Configuración General del Taller</h1>
    <p>Personaliza los datos comerciales, fiscales, políticas de garantía y genera copias de seguridad de la base de datos.</p>
  </div>
  <a class="btn btn-primary" href="api/backup.php">
    <span class="material-symbols-outlined">backup</span>Descargar Copia de Seguridad SQL
  </a>
</div>

<div class="grid-main" style="grid-template-columns:minmax(0,1fr) 360px">
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Datos Comerciales y Fiscales</h2>
        <p>Esta información se refleja en las órdenes de servicio, comprobantes y el portal de clientes.</p>
      </div>
    </div>
    <form method="post" action="api/configuracion.php" style="padding:22px">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field full">
          <label>Nombre Comercial del Taller <span class="required">*</span></label>
          <input class="input" name="nombre_taller" value="<?= e($cfg['nombre_taller'] ?? 'eBike Maintenance System (EMS)') ?>" required>
        </div>

        <div class="field">
          <label>NIT / Identificación Tributaria</label>
          <input class="input" name="nit" value="<?= e($cfg['nit'] ?? '901.845.123-4') ?>">
        </div>

        <div class="field">
          <label>Teléfono de Atención / WhatsApp</label>
          <input class="input" name="telefono" value="<?= e($cfg['telefono'] ?? '+57 310 987 6543') ?>">
        </div>

        <div class="field">
          <label>Correo Electrónico Oficial</label>
          <input class="input" type="email" name="email" value="<?= e($cfg['email'] ?? 'contacto@ebiketaller.com') ?>">
        </div>

        <div class="field">
          <label>Garantía Estándar en Mano de Obra (Días)</label>
          <input class="input" type="number" min="0" name="garantia_dias" value="<?= e($cfg['garantia_dias'] ?? '90') ?>">
        </div>

        <div class="field full">
          <label>Dirección Física del Taller</label>
          <input class="input" name="direccion" value="<?= e($cfg['direccion'] ?? 'Cra 45 # 128 - 20, Bogotá, Colombia') ?>">
        </div>
      </div>

      <div style="margin-top:20px;display:flex;justify-content:flex-end">
        <button class="btn btn-primary" type="submit">
          <span class="material-symbols-outlined">save</span>Guardar Configuración
        </button>
      </div>
    </form>
  </section>

  <div style="display:grid;gap:20px;align-content:start">
    <section class="panel">
      <div class="panel-head">
        <h3>Copia de Seguridad (Backup)</h3>
      </div>
      <div style="padding:20px;font-size:13px;line-height:1.6">
        <p style="margin-top:0">Exporta en un solo clic toda la estructura y datos de la base de datos <code>ebike_taller</code> en un archivo <code>.sql</code> listo para importar en phpMyAdmin o en otro servidor.</p>
        <a class="btn btn-primary" href="api/backup.php" style="width:100%;justify-content:center">
          <span class="material-symbols-outlined">download</span>Descargar Backup Ahora
        </a>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h3>Información del Entorno</h3>
      </div>
      <div style="padding:18px;font-size:12px;line-height:1.8">
        <div><b>Versión PHP:</b> <?= phpversion() ?></div>
        <div><b>Motor BD:</b> MySQL / MariaDB (PDO)</div>
        <div><b>Moneda del Sistema:</b> Pesos Colombianos (COP)</div>
        <div><b>Servidor Web:</b> <?= e($_SERVER['SERVER_SOFTWARE'] ?? 'Apache') ?></div>
      </div>
    </section>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
