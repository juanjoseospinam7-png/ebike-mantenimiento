<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_admin();

$pageTitle = 'Usuarios & Personal';
$pdo = db();

$filtroRol = trim($_GET['rol'] ?? '');
$search = trim($_GET['buscar'] ?? '');

$where = [];
$params = [];

if ($filtroRol !== '') {
    $where[] = "u.rol = ?";
    $params[] = $filtroRol;
}
if ($search !== '') {
    $where[] = "(u.nombre LIKE ? OR u.usuario LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)";
    for ($i = 0; $i < 4; $i++) $params[] = "%$search%";
}

$sql = "
    SELECT u.id, u.nombre, u.usuario, u.rol, u.email, u.telefono, u.activo, u.ultimo_login, u.creado_en,
           e.id_empleado, e.cargo, e.especialidad
    FROM usuarios u
    LEFT JOIN empleados e ON e.id_empleado = u.id_empleado
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY (u.rol='admin') DESC, (u.rol='mecanico') DESC, u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Conteo por roles
$countAdmin = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='admin'")->fetchColumn();
$countMecanicos = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='mecanico'")->fetchColumn();
$countClientes = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='cliente'")->fetchColumn();

include __DIR__ . '/partials/header.php';
?>

<div class="hero">
  <div>
    <span class="eyebrow">ADMINISTRACIÓN · CONTROL DE ACCESO</span>
    <h1>Gestión de Usuarios y Personal</h1>
    <p>Crea y administra cuentas de Administradores, Mecánicos técnicos y Clientes con control de permisos y contraseñas.</p>
  </div>
  <button class="btn btn-primary" type="button" onclick="openNewUserModal()">
    <span class="material-symbols-outlined">person_add</span>Crear Nuevo Usuario
  </button>
</div>

<!-- KPIs Roles -->
<div class="kpis">
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Administradores</div>
        <div class="kpi-value"><?= $countAdmin ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">shield_person</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Mecánicos del Taller</div>
        <div class="kpi-value"><?= $countMecanicos ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">engineering</span></div>
    </div>
  </div>
  <div class="card kpi">
    <div class="kpi-top">
      <div>
        <div class="kpi-label">Clientes Registrados</div>
        <div class="kpi-value"><?= $countClientes ?></div>
      </div>
      <div class="kpi-icon"><span class="material-symbols-outlined">group</span></div>
    </div>
  </div>
</div>

<section class="panel">
  <!-- Filtros -->
  <form class="filters" method="get" style="grid-template-columns:2fr 1fr auto">
    <input class="input" name="buscar" value="<?= e($search) ?>" placeholder="Buscar por nombre, usuario, correo o teléfono...">
    <select class="select" name="rol">
      <option value="">Todos los roles</option>
      <option value="admin" <?= ($filtroRol === 'admin') ? 'selected' : '' ?>>Administradores</option>
      <option value="mecanico" <?= ($filtroRol === 'mecanico') ? 'selected' : '' ?>>Mecánicos</option>
      <option value="cliente" <?= ($filtroRol === 'cliente') ? 'selected' : '' ?>>Clientes</option>
    </select>
    <button class="btn btn-primary" type="submit">
      <span class="material-symbols-outlined">filter_alt</span>Filtrar
    </button>
  </form>

  <div class="panel-head">
    <div>
      <h2>Usuarios Registrados en el Sistema</h2>
      <p><?= count($rows) ?> cuenta(s) listada(s).</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Nombre Completo</th>
          <th>Usuario</th>
          <th>Rol</th>
          <th>Contacto (Email / Tel)</th>
          <th>Cargo / Especialidad</th>
          <th>Estado</th>
          <th>Último Acceso</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?= e($r['nombre']) ?></b></td>
          <td><code><?= e($r['usuario']) ?></code></td>
          <td>
            <?php
              $badgeClass = match($r['rol']) {
                  'admin' => 'info',
                  'mecanico' => 'warning',
                  'cliente' => 'success',
                  default => 'neutral'
              };
            ?>
            <span class="badge badge-<?= $badgeClass ?>" style="font-weight:700">
              <?= ucfirst(e($r['rol'])) ?>
            </span>
          </td>
          <td>
            <?= e($r['email'] ?: 'Sin email') ?><br>
            <span class="muted"><?= e($r['telefono'] ?: 'Sin teléfono') ?></span>
          </td>
          <td>
            <?= e($r['cargo'] ?: ($r['rol'] === 'admin' ? 'Administrador General' : 'Cliente Particular')) ?>
            <?php if (!empty($r['especialidad'])): ?>
              <br><small class="muted"><?= e($r['especialidad']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= $r['activo'] ? 'success' : 'danger' ?>">
              <?= $r['activo'] ? 'Activo' : 'Inactivo' ?>
            </span>
          </td>
          <td><?= format_date($r['ultimo_login']) ?></td>
          <td>
            <div class="actions-cell">
              <!-- Editar -->
              <button class="icon-btn" title="Editar Información" onclick='editUser(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                <span class="material-symbols-outlined">edit</span>
              </button>

              <!-- Restablecer Contraseña -->
              <button class="icon-btn" title="Restablecer Contraseña" onclick='openResetModal(<?= (int)$r['id'] ?>, <?= json_encode($r['usuario']) ?>)'>
                <span class="material-symbols-outlined">lock_reset</span>
              </button>

              <!-- Toggle Activo -->
              <?php if ((int)$r['id'] !== (int)$_SESSION['ebike_user']['id']): ?>
                <form method="post" action="api/cuenta.php" style="display:inline" onsubmit="return confirm('¿Deseas cambiar el estado de activación de este usuario?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="toggle_activo">
                  <input type="hidden" name="id_usuario" value="<?= (int)$r['id'] ?>">
                  <button class="icon-btn" type="submit" title="<?= $r['activo'] ? 'Desactivar cuenta' : 'Activar cuenta' ?>" style="color:<?= $r['activo'] ? 'var(--danger)' : 'var(--green)' ?>">
                    <span class="material-symbols-outlined"><?= $r['activo'] ? 'block' : 'check_circle' ?></span>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty">No se encontraron usuarios.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- MODAL CREACIÓN DE USUARIO -->
<div class="modal-backdrop" id="userModal">
  <div class="modal">
    <div class="modal-head">
      <h2>Crear Nueva Cuenta de Usuario</h2>
      <button class="icon-btn" type="button" onclick="closeModal('userModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/cuenta.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="crear">

      <div class="modal-body">
        <div class="form-grid">
          <div class="field full">
            <label>Rol de Usuario <span class="required">*</span></label>
            <select class="select" name="rol" id="newUserRol" onchange="toggleRolFields()" required>
              <option value="mecanico">Mecánico de Taller (Acceso a Reparaciones y Diagnósticos)</option>
              <option value="admin">Administrador (Acceso Total: Cuentas, Contabilidad, Todo)</option>
              <option value="cliente">Cliente (Portal para Solicitar Servicios y Ver Bicicletas)</option>
            </select>
            <small class="muted">Solamente un administrador autorizado puede crear cuentas.</small>
          </div>

          <div class="field">
            <label>Nombre y Apellidos <span class="required">*</span></label>
            <input class="input" name="nombre" required placeholder="Ej. Javier Mendoza" maxlength="120">
          </div>

          <div class="field">
            <label>Nombre de Usuario <span class="required">*</span></label>
            <input class="input" name="usuario" required placeholder="javier.mendoza" maxlength="60" autocomplete="off">
          </div>

          <div class="field">
            <label>Correo Electrónico</label>
            <input class="input" name="email" type="email" placeholder="javier@ebiketaller.com">
          </div>

          <div class="field">
            <label>Teléfono de Contacto</label>
            <input class="input" name="telefono" placeholder="300 123 4567">
          </div>

          <div class="field">
            <label>Contraseña <span class="required">*</span></label>
            <input class="input" type="password" name="contrasena" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
          </div>

          <div class="field">
            <label>Confirmar Contraseña <span class="required">*</span></label>
            <input class="input" type="password" name="confirmar" required minlength="6" autocomplete="new-password">
          </div>

          <!-- Campos específicos para Mecánico -->
          <div class="field" id="wrapCargo">
            <label>Cargo del Empleado</label>
            <input class="input" name="cargo" value="Técnico Especialista" placeholder="Ej. Técnico Senior">
          </div>

          <div class="field" id="wrapEspecialidad">
            <label>Especialidad Técnica</label>
            <input class="input" name="especialidad" placeholder="Ej. Baterías, Motores Centrales, Frenos">
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('userModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Crear Usuario</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL EDICIÓN DE USUARIO -->
<div class="modal-backdrop" id="editUserModal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="editUserTitle">Editar Usuario</h2>
      <button class="icon-btn" type="button" onclick="closeModal('editUserModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/cuenta.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id_usuario" id="editUserId">

      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label>Nombre y Apellidos <span class="required">*</span></label>
            <input class="input" name="nombre" id="editNombre" required maxlength="120">
          </div>

          <div class="field">
            <label>Rol en el Sistema <span class="required">*</span></label>
            <select class="select" name="rol" id="editRol" onchange="toggleEditRolFields()" required>
              <option value="admin">Administrador</option>
              <option value="mecanico">Mecánico</option>
              <option value="cliente">Cliente</option>
            </select>
          </div>

          <div class="field">
            <label>Correo Electrónico</label>
            <input class="input" name="email" id="editEmail" type="email">
          </div>

          <div class="field">
            <label>Teléfono de Contacto</label>
            <input class="input" name="telefono" id="editTelefono">
          </div>

          <div class="field" id="wrapEditCargo">
            <label>Cargo</label>
            <input class="input" name="cargo" id="editCargo">
          </div>

          <div class="field" id="wrapEditEspecialidad">
            <label>Especialidad</label>
            <input class="input" name="especialidad" id="editEspecialidad">
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('editUserModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar Cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL RESTABLECER CONTRASEÑA -->
<div class="modal-backdrop" id="resetModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head">
      <h2>Restablecer Contraseña</h2>
      <button class="icon-btn" type="button" onclick="closeModal('resetModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="post" action="api/cuenta.php">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="restablecer_password">
      <input type="hidden" name="id_usuario" id="resetUserId">

      <div class="modal-body">
        <p style="font-size:13px;margin-top:0">
          Asignar una nueva clave de acceso para el usuario: <strong id="resetUserTxt"></strong>
        </p>

        <div class="field" style="margin-bottom:12px">
          <label>Nueva Contraseña <span class="required">*</span></label>
          <input class="input" type="password" name="nueva_contrasena" required minlength="6" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
        </div>

        <div class="field">
          <label>Confirmar Nueva Contraseña <span class="required">*</span></label>
          <input class="input" type="password" name="confirmar_contrasena" required minlength="6" autocomplete="new-password">
        </div>
      </div>

      <div class="modal-foot">
        <button class="btn btn-light" type="button" onclick="closeModal('resetModal')">Cancelar</button>
        <button class="btn btn-primary" type="submit">Actualizar Contraseña</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleRolFields(){
  const rol = document.getElementById('newUserRol').value;
  const isMec = (rol === 'mecanico');
  document.getElementById('wrapCargo').style.display = isMec ? 'block' : 'none';
  document.getElementById('wrapEspecialidad').style.display = isMec ? 'block' : 'none';
}

function toggleEditRolFields(){
  const rol = document.getElementById('editRol').value;
  const isMec = (rol === 'mecanico');
  document.getElementById('wrapEditCargo').style.display = isMec ? 'block' : 'none';
  document.getElementById('wrapEditEspecialidad').style.display = isMec ? 'block' : 'none';
}

function openNewUserModal(){
  toggleRolFields();
  openModal('userModal');
}

function editUser(u){
  document.getElementById('editUserTitle').textContent = 'Editar Usuario: ' + u.usuario;
  document.getElementById('editUserId').value = u.id;
  document.getElementById('editNombre').value = u.nombre || '';
  document.getElementById('editRol').value = u.rol || 'mecanico';
  document.getElementById('editEmail').value = u.email || '';
  document.getElementById('editTelefono').value = u.telefono || '';
  document.getElementById('editCargo').value = u.cargo || '';
  document.getElementById('editEspecialidad').value = u.especialidad || '';
  syncSearchableSelect('editRol');
  toggleEditRolFields();
  openModal('editUserModal');
}

function openResetModal(id, usuario){
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetUserTxt').textContent = usuario;
  openModal('resetModal');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
