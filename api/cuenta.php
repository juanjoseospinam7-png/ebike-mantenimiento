<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../cuentas.php');
}

$pdo = db();
$currentUserId = (int)($_SESSION['ebike_user']['id'] ?? 0);

try {
    verify_csrf();
    $accion = post_string('accion', 'crear');

    if ($accion === 'crear') {
        $nombre = post_string('nombre');
        $usuario = post_string('usuario');
        $email = post_string('email');
        $telefono = post_string('telefono');
        $contrasena = (string)($_POST['contrasena'] ?? '');
        $confirmar = (string)($_POST['confirmar'] ?? '');
        $rol = post_string('rol', 'mecanico');
        $cargo = post_string('cargo', 'Técnico');
        $especialidad = post_string('especialidad');

        if ($nombre === '' || $usuario === '' || $contrasena === '') {
            throw new RuntimeException('Completa los campos obligatorios (*).');
        }

        if (!in_array($rol, ['admin', 'mecanico', 'cliente'], true)) {
            throw new RuntimeException('Rol no válido.');
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $usuario)) {
            throw new RuntimeException('El usuario solo puede contener letras, números, punto, guion y guion bajo.');
        }

        if (strlen($contrasena) < 6) {
            throw new RuntimeException('La contraseña debe tener al menos 6 caracteres.');
        }

        if ($contrasena !== $confirmar) {
            throw new RuntimeException('Las contraseñas no coinciden.');
        }

        $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
        $check->execute([$usuario]);
        if ($check->fetch()) {
            throw new RuntimeException("El nombre de usuario '$usuario' ya está en uso.");
        }

        $pdo->beginTransaction();

        $idEmpleado = null;
        if ($rol === 'mecanico') {
            $emp = $pdo->prepare("INSERT INTO empleados (nombre, cargo, telefono, email, especialidad, activo) VALUES (?, ?, ?, ?, ?, 1)");
            $emp->execute([$nombre, $cargo ?: 'Técnico', $telefono ?: null, $email ?: null, $especialidad ?: null]);
            $idEmpleado = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, usuario, password, rol, id_empleado, email, telefono, activo, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $nombre,
            $usuario,
            password_hash($contrasena, PASSWORD_DEFAULT),
            $rol,
            $idEmpleado,
            $email ?: null,
            $telefono ?: null
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        audit($pdo, null, null, null, null, 'Creación de Usuario', "Usuario $usuario con rol $rol creado por Admin #$currentUserId");
        $pdo->commit();

        flash('success', "Cuenta de usuario '$usuario' ($rol) creada exitosamente.");
    } elseif ($accion === 'editar') {
        $idUser = post_int('id_usuario');
        if (!$idUser) throw new RuntimeException('ID de usuario inválido.');

        $nombre = post_string('nombre');
        $email = post_string('email');
        $telefono = post_string('telefono');
        $rol = post_string('rol');
        $cargo = post_string('cargo');
        $especialidad = post_string('especialidad');

        if ($nombre === '') throw new RuntimeException('El nombre es obligatorio.');

        // Evitar que el admin principal se quite el rol de admin a sí mismo
        if ($idUser === $currentUserId && $rol !== 'admin') {
            throw new RuntimeException('No puedes cambiar tu propio rol de Administrador.');
        }

        $pdo->beginTransaction();

        // Obtener datos actuales del usuario
        $uStmt = $pdo->prepare("SELECT id_empleado, rol FROM usuarios WHERE id = ?");
        $uStmt->execute([$idUser]);
        $currUser = $uStmt->fetch();
        if (!$currUser) throw new RuntimeException('Usuario no encontrado.');

        $idEmpleado = $currUser['id_empleado'];

        if ($rol === 'mecanico') {
            if ($idEmpleado) {
                $pdo->prepare("UPDATE empleados SET nombre = ?, cargo = ?, telefono = ?, email = ?, especialidad = ? WHERE id_empleado = ?")
                    ->execute([$nombre, $cargo ?: 'Técnico', $telefono ?: null, $email ?: null, $especialidad ?: null, $idEmpleado]);
            } else {
                $emp = $pdo->prepare("INSERT INTO empleados (nombre, cargo, telefono, email, especialidad, activo) VALUES (?, ?, ?, ?, ?, 1)");
                $emp->execute([$nombre, $cargo ?: 'Técnico', $telefono ?: null, $email ?: null, $especialidad ?: null]);
                $idEmpleado = (int)$pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET nombre = ?, email = ?, telefono = ?, rol = ?, id_empleado = ?
            WHERE id = ?
        ");
        $stmt->execute([$nombre, $email ?: null, $telefono ?: null, $rol, $idEmpleado, $idUser]);

        audit($pdo, null, null, null, null, 'Edición de Usuario', "Usuario #$idUser actualizado");
        $pdo->commit();

        flash('success', "Información del usuario #$idUser actualizada correctamente.");
    } elseif ($accion === 'toggle_activo') {
        $idUser = post_int('id_usuario');
        if (!$idUser) throw new RuntimeException('ID de usuario inválido.');

        if ($idUser === $currentUserId) {
            throw new RuntimeException('No puedes desactivar tu propia cuenta activa de administrador.');
        }

        $pdo->prepare("UPDATE usuarios SET activo = IF(activo=1, 0, 1) WHERE id = ?")->execute([$idUser]);
        audit($pdo, null, null, null, null, 'Cambio de Estado de Usuario', "Estado activo alternado para usuario #$idUser");

        flash('success', 'Estado de la cuenta actualizado exitosamente.');
    } elseif ($accion === 'restablecer_password') {
        $idUser = post_int('id_usuario');
        $nuevaClave = (string)($_POST['nueva_contrasena'] ?? '');
        $confirmar = (string)($_POST['confirmar_contrasena'] ?? '');

        if (!$idUser) throw new RuntimeException('ID de usuario inválido.');
        if (strlen($nuevaClave) < 6) throw new RuntimeException('La nueva contraseña debe tener mínimo 6 caracteres.');
        if ($nuevaClave !== $confirmar) throw new RuntimeException('Las contraseñas no coinciden.');

        $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
            ->execute([password_hash($nuevaClave, PASSWORD_DEFAULT), $idUser]);

        audit($pdo, null, null, null, null, 'Restablecimiento de Contraseña', "Contraseña restablecida para usuario #$idUser por Admin #$currentUserId");

        flash('success', 'Contraseña restablecida exitosamente.');
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}

redirect('../cuentas.php');
