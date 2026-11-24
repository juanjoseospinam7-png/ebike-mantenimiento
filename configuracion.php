<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();
$pdo = db();
$cfg = get_workshop_config($pdo);
$pageTitle = 'Configuración de la empresa';
include __DIR__ . '/partials/header.php';
?>
<section class="page-intro"><span class="eyebrow">ADMINISTRACIÓN · IDENTIDAD Y POLÍTICAS</span><h1>Configuración de la empresa</h1><p>Estos datos aparecen en tickets, órdenes internas, portales y políticas de alquiler.</p></section>
<div class="grid-main">
<section class="panel">
  <div class="panel-head"><h2>Datos generales</h2></div>
  <form method="post" action="api/configuracion.php" class="stack-form" style="padding:20px">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label>Nombre comercial *<input class="input" name="nombre_empresa" value="<?= e($cfg['nombre_empresa'] ?? '') ?>" required></label>
      <label>NIT / Identificación<input class="input" name="nit" value="<?= e($cfg['nit'] ?? '') ?>"></label>
      <label>Teléfono / WhatsApp<input class="input" name="telefono" value="<?= e($cfg['telefono'] ?? '') ?>"></label>
      <label>Correo oficial<input class="input" type="email" name="email" value="<?= e($cfg['email'] ?? '') ?>"></label>
      <label class="field full">Dirección<input class="input" name="direccion" value="<?= e($cfg['direccion'] ?? '') ?>"></label>
      <label>Horario de atención<input class="input" name="horario_atencion" value="<?= e($cfg['horario_atencion'] ?? '') ?>"></label>
      <label>Depósito de garantía (COP)<input class="input" type="number" min="0" name="deposito_garantia" value="<?= e($cfg['deposito_garantia'] ?? '0') ?>"></label>
      <label>Garantía de mano de obra (días)<input class="input" type="number" min="0" name="garantia_dias" value="<?= e($cfg['garantia_dias'] ?? '90') ?>"></label>
      <label class="field full">Política de alquiler<textarea class="input" name="politica_alquiler" rows="3"><?= e($cfg['politica_alquiler'] ?? '') ?></textarea></label>
    </div>
    <div class="modal-foot"><button class="btn btn-primary" type="submit">Guardar configuración</button></div>
  </form>
</section>
<div class="side-stack">
  <section class="panel"><div class="panel-head"><h2>Copia de seguridad</h2></div><div class="panel-body">
    <p class="muted">Exporta la estructura y los datos de <code>ebike_taller</code> en un archivo SQL para importar en phpMyAdmin.</p>
    <a class="btn btn-primary btn-block" href="api/backup.php"><span class="material-symbols-outlined">backup</span>Descargar backup</a>
  </div></section>
  <section class="panel"><div class="panel-head"><h2>Entorno</h2></div><div class="panel-body env-info">
    <div><span>PHP</span><strong><?= e(phpversion()) ?></strong></div>
    <div><span>Motor</span><strong>MySQL / MariaDB (PDO)</strong></div>
    <div><span>Moneda</span><strong>COP</strong></div>
    <div><span>Servidor</span><strong><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'Apache') ?></strong></div>
  </div></section>
</div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
