<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/helpers.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT enlace FROM notificaciones WHERE id_notificacion = ? AND (id_usuario = ? OR rol_destino IN (?, \'todos\'))');
$stmt->execute([$id, (int)(current_user()['id'] ?? 0), (string)(current_user()['rol'] ?? '')]);
$enlace = $stmt->fetchColumn();
if ($enlace) {
    mark_notification_read($pdo, $id);
    redirect($enlace);
}
flash('error', 'La notificación no existe o no tienes acceso.');
redirect(home_for_role());
