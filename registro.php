<?php
session_start();
require_once __DIR__ . '/config/helpers.php';

if (is_logged_in()) {
    $rol = current_user()['rol'] ?? 'mecanico';
    if ($rol === 'admin') redirect('index.php');
    if ($rol === 'cliente') redirect('cliente.php');
    redirect('mecanico.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $nombre = post_string('nombre');
        $usuario = post_string('usuario');
        $email = post_string('email');
        $telefono = post_string('telefono');
        $contrasena = (string)($_POST['contrasena'] ?? '');
        $confirmar = (string)($_POST['confirmar'] ?? '');

        if ($nombre === '' || $usuario === '' || $contrasena === '') {
            throw new RuntimeException('Completa los campos obligatorios.');
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $usuario)) {
            throw new RuntimeException('El nombre de usuario solo puede tener entre 3 y 60 caracteres (letras, números, punto o guion).');
        }

        if (strlen($contrasena) < 6) {
            throw new RuntimeException('La contraseña debe tener mínimo 6 caracteres.');
        }

        if ($contrasena !== $confirmar) {
            throw new RuntimeException('Las contraseñas no coinciden.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo electrónico no es válido.');
        }

        $pdo = db();
        $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? OR (email = ? AND email <> '') LIMIT 1");
        $check->execute([$usuario, $email]);
        if ($check->fetch()) {
            throw new RuntimeException('El nombre de usuario o correo ya se encuentra registrado.');
        }

        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, usuario, password, rol, email, telefono, activo, ultimo_login) VALUES (?, ?, ?, 'cliente', ?, ?, 1, NOW())");
        $stmt->execute([
            $nombre,
            $usuario,
            password_hash($contrasena, PASSWORD_DEFAULT),
            $email ?: null,
            $telefono ?: null
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Notificar a los administradores
        notify($pdo, 'Nuevo cliente registrado', "El cliente $nombre ($usuario) se ha registrado en el sistema.", 'admin', null, 'cuentas.php');

        // Iniciar sesión automáticamente
        session_regenerate_id(true);
        $_SESSION['ebike_user'] = [
            'id'          => $newId,
            'nombre'      => $nombre,
            'usuario'     => $usuario,
            'rol'         => 'cliente',
            'id_empleado' => null,
            'email'       => $email,
            'telefono'    => $telefono
        ];

        flash('success', '¡Bienvenido a eBike Taller! Tu cuenta ha sido creada exitosamente.');
        redirect('cliente.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registro de Cliente | eBike EMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
  <div class="login-shell" style="max-width:520px">
    <section class="login-card">
      <div class="login-brand">
        <div class="login-mark" aria-hidden="true">
          <span class="material-symbols-outlined" style="font-size:28px">electric_bike</span>
        </div>
        <div>
          <div class="login-name">eBike</div>
          <div class="login-subtitle">Portal de Clientes</div>
        </div>
      </div>

      <div class="login-heading">
        <span class="eyebrow">REGISTRO DE CLIENTE</span>
        <h1>Crear tu cuenta</h1>
        <p>Registra tu perfil para gestionar tus eBikes, solicitar mantenimientos y consultar diagnósticos y presupuestos en tiempo real.</p>
      </div>

      <?php if ($error): ?>
        <div class="login-error">
          <span class="material-symbols-outlined">error</span>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" class="login-form">
        <?= csrf_field() ?>
        <div class="form-grid" style="grid-template-columns:1fr;gap:12px">
          <div class="field">
            <label>Nombre y Apellidos <span class="required">*</span></label>
            <div class="login-input-wrap">
              <span class="material-symbols-outlined">badge</span>
              <input name="nombre" type="text" placeholder="Ej. Andrea Morales" required value="<?= e($_POST['nombre'] ?? '') ?>">
            </div>
          </div>

          <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:12px">
            <div class="field">
              <label>Usuario deseado <span class="required">*</span></label>
              <div class="login-input-wrap">
                <span class="material-symbols-outlined">person</span>
                <input name="usuario" type="text" placeholder="andrea.ebike" required value="<?= e($_POST['usuario'] ?? '') ?>">
              </div>
            </div>

            <div class="field">
              <label>Teléfono de contacto</label>
              <div class="login-input-wrap">
                <span class="material-symbols-outlined">call</span>
                <input name="telefono" type="tel" placeholder="3001234567" value="<?= e($_POST['telefono'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="field">
            <label>Correo electrónico</label>
            <div class="login-input-wrap">
              <span class="material-symbols-outlined">mail</span>
              <input name="email" type="email" placeholder="andrea@ejemplo.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
          </div>

          <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:12px">
            <div class="field">
              <label>Contraseña <span class="required">*</span></label>
              <div class="login-input-wrap">
                <span class="material-symbols-outlined">lock</span>
                <input name="contrasena" type="password" placeholder="Mínimo 6 caracteres" required>
              </div>
            </div>

            <div class="field">
              <label>Confirmar contraseña <span class="required">*</span></label>
              <div class="login-input-wrap">
                <span class="material-symbols-outlined">lock_clock</span>
                <input name="confirmar" type="password" placeholder="Repite tu contraseña" required>
              </div>
            </div>
          </div>
        </div>

        <button class="login-submit" type="submit" style="margin-top:12px">
          <span>Registrarme e Ingresar</span>
          <span class="material-symbols-outlined">check_circle</span>
        </button>
      </form>

      <div style="margin-top:18px;text-align:center">
        <a href="login.php" class="support-link" style="margin-top:0">
          <span class="material-symbols-outlined">login</span>
          <span>¿Ya tienes una cuenta? Inicia sesión</span>
        </a>
      </div>
    </section>
  </div>
</body>
</html>
