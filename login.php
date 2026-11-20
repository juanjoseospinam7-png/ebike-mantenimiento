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
        $usuario = post_string('usuario');
        $contrasena = (string)($_POST['contrasena'] ?? '');

        if ($usuario === '' || $contrasena === '') {
            $error = 'Ingresa tu usuario y contraseña.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, nombre, usuario, password, rol, id_empleado, activo, email, telefono FROM usuarios WHERE (usuario = ? OR email = ?) LIMIT 1");
            $stmt->execute([$usuario, $usuario]);
            $user = $stmt->fetch();

            if ($user && password_verify($contrasena, $user['password'])) {
                if ((int)$user['activo'] !== 1) {
                    $error = 'Tu cuenta se encuentra inactiva. Comunícate con el administrador.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['ebike_user'] = [
                        'id'          => (int)$user['id'],
                        'nombre'      => $user['nombre'],
                        'usuario'     => $user['usuario'],
                        'rol'         => $user['rol'] ?: 'cliente',
                        'id_empleado' => $user['id_empleado'] ? (int)$user['id_empleado'] : null,
                        'email'       => $user['email'] ?? '',
                        'telefono'    => $user['telefono'] ?? ''
                    ];

                    // Actualizar fecha de último acceso
                    $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([(int)$user['id']]);

                    if ($user['rol'] === 'admin') redirect('index.php');
                    if ($user['rol'] === 'cliente') redirect('cliente.php');
                    redirect('mecanico.php');
                }
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        }
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
<title>Iniciar sesión | eBike Maintenance System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
  <div class="login-shell">
    <section class="login-card" aria-label="Inicio de sesión eBike">
      <div class="login-brand">
        <div class="login-mark" aria-hidden="true">
          <span class="material-symbols-outlined" style="font-size:28px">electric_bolt</span>
        </div>
        <div>
          <div class="login-name">eBike</div>
          <div class="login-subtitle">Maintenance System (EMS)</div>
        </div>
      </div>

      <div class="login-heading">
        <span class="eyebrow">PORTAL DE ACCESO</span>
        <h1>Iniciar sesión</h1>
        <p>Ingresa tus credenciales para acceder a tu panel.</p>
      </div>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="login-error">
          <span class="material-symbols-outlined">error</span>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" class="login-form" autocomplete="on">
        <?= csrf_field() ?>
        <div class="login-field">
          <label for="usuario">Usuario o Correo</label>
          <div class="login-input-wrap">
            <span class="material-symbols-outlined">person</span>
            <input id="usuario" name="usuario" type="text" placeholder="Usuario o correo electrónico" autocomplete="username" required autofocus value="<?= e($_POST['usuario'] ?? '') ?>">
          </div>
        </div>

        <div class="login-field">
          <label for="contrasena">Contraseña</label>
          <div class="login-input-wrap">
            <span class="material-symbols-outlined">lock</span>
            <input id="contrasena" name="contrasena" type="password" placeholder="Ingresa tu contraseña" autocomplete="current-password" required>
            <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Mostrar contraseña">
              <span class="material-symbols-outlined" id="eyeIcon">visibility</span>
            </button>
          </div>
        </div>

        <button class="login-submit" type="submit">
          <span>Ingresar al sistema</span>
          <span class="material-symbols-outlined">arrow_forward</span>
        </button>
      </form>

      <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;text-align:center">
        <a class="btn btn-light" href="registro.php" style="justify-content:center">
          <span class="material-symbols-outlined">person_add</span>
          <span>¿Eres cliente nuevo? Regístrate aquí</span>
        </a>
        <a class="btn btn-light" href="seguimiento.php" style="justify-content:center;background:var(--green-50);border-color:var(--green-100);color:var(--green-800)">
          <span class="material-symbols-outlined">qr_code_scanner</span>
          <span>Consultar estado de reparación con código</span>
        </a>
      </div>

      <button class="support-link" type="button" onclick="openSupport()">
        <span class="material-symbols-outlined">help</span> Ayuda y credenciales iniciales
      </button>

      <div class="login-footer">eBike Maintenance System (EMS) · Sistema Profesional</div>
    </section>
  </div>

  <div class="login-support-modal" id="supportModal" role="dialog" aria-modal="true" aria-labelledby="supportTitle">
    <div class="support-box">
      <div class="support-icon" aria-hidden="true">
        <span class="material-symbols-outlined" style="font-size:32px">key</span>
      </div>
      <h2 id="supportTitle">Credenciales de Acceso</h2>
      <p style="text-align:left;font-size:12px;background:#f2fbf5;padding:12px;border-radius:10px;line-height:1.6">
        <b>Administrador inicial:</b><br>
        Usuario: <code>admin</code> | Clave: <code>admin123</code><br><br>
        <b>Mecánicos o Clientes:</b><br>
        Deben ser registrados por el Administrador desde el panel o a través del botón de registro de clientes.
      </p>
      <button class="support-close" type="button" onclick="closeSupport()">Cerrar</button>
    </div>
  </div>

<script>
function togglePassword(){
  const input=document.getElementById('contrasena');
  const icon=document.getElementById('eyeIcon');
  const showing=input.type==='text';
  input.type=showing?'password':'text';
  icon.textContent=showing?'visibility_off':'visibility';
}
function openSupport(){document.getElementById('supportModal').classList.add('show')}
function closeSupport(){document.getElementById('supportModal').classList.remove('show')}
document.getElementById('supportModal').addEventListener('click',function(e){if(e.target===this)closeSupport()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeSupport()});
</script>
</body>
</html>
