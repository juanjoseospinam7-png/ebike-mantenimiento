<?php
if (!isset($pageTitle)) $pageTitle = 'eBike';
$currentPage = basename($_SERVER['PHP_SELF']);
$user = current_user();
$pdoHeader = db();
$notifCount = get_unread_notifications_count($pdoHeader);
$recentNotifs = get_recent_notifications($pdoHeader, 6);
$nav = [];

if (is_admin()) {
    $nav = [
        ['index.php', 'dashboard', 'Centro de operaciones'],
        ['alquileres_admin.php', 'receipt_long', 'Alquileres'],
        ['reportes_admin.php', 'view_kanban', 'Incidencias'],
        ['bicicletas.php', 'electric_bike', 'Flota'],
        ['mantenimientos.php', 'build', 'Mantenimiento interno'],
        ['inventario.php', 'inventory_2', 'Inventario técnico'],
        ['contabilidad.php', 'payments', 'Finanzas'],
        ['cuentas.php', 'group', 'Usuarios'],
        ['auditoria.php', 'history', 'Auditoría'],
        ['configuracion.php', 'settings', 'Empresa'],
    ];
} elseif (is_mechanic()) {
    $nav = [
        ['mecanico.php', 'groups', 'Bandeja compartida'],
        ['mantenimientos.php', 'build', 'Órdenes internas'],
        ['bicicletas.php', 'electric_bike', 'Flota'],
        ['inventario.php', 'inventory_2', 'Componentes'],
    ];
} elseif (is_client()) {
    $nav = [
        ['cliente.php', 'explore', 'Explorar eBikes'],
        ['mis_alquileres.php', 'receipt_long', 'Mis alquileres'],
        ['seguimiento.php', 'route', 'Seguimiento'],
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a5c46">
    <title><?= e($pageTitle) ?> · eBike</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(home_for_role()) ?>">
        <div class="brand-mark"><span class="material-symbols-outlined">electric_bike</span></div>
        <div><strong>eBike</strong><small>Movilidad Eléctrica</small></div>
    </a>
    <div class="nav-label">
        <?= is_admin() ? 'OPERACIONES' : (is_mechanic() ? 'DEPARTAMENTO TÉCNICO' : 'EXPLORA Y ALQUILA') ?>
    </div>
    <nav>
        <?php foreach ($nav as [$href, $icon, $label]): ?>
            <a class="<?= $currentPage === $href ? 'active' : '' ?>" href="<?= e($href) ?>">
                <span class="material-symbols-outlined"><?= e($icon) ?></span><?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <div class="online"><i></i> Sistema operativo</div>
        <small>eBike · Alquiler de bicicletas eléctricas</small>
    </div>
</aside>

<div class="app">
<header class="topbar">
    <div class="topbar-title">
        <button class="mobile-menu" type="button" onclick="toggleSidebar()" aria-label="Abrir menú">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <div>
            <span class="eyebrow"><?= is_admin() ? 'CENTRO DE CONTROL' : (is_mechanic() ? 'MANTENIMIENTO INTERNO' : 'EXPERIENCIA DE ALQUILER') ?></span>
            <strong><?= e($pageTitle) ?></strong>
        </div>
    </div>
    <div class="top-actions">
        <div class="notif-wrapper">
            <button class="icon-btn notif-btn" type="button" onclick="toggleNotifs()" title="Notificaciones" aria-label="Notificaciones">
                <span class="material-symbols-outlined">notifications</span>
                <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header"><strong>Notificaciones</strong><small><?= $notifCount ?> sin leer</small></div>
                <div class="notif-list">
                    <?php foreach ($recentNotifs as $nt): ?>
                        <a class="notif-item <?= $nt['leida'] ? '' : 'unread' ?>" href="notificacion.php?id=<?= (int)$nt['id_notificacion'] ?>">
                            <div class="notif-title"><?= e($nt['titulo']) ?></div>
                            <div class="notif-msg"><?= e($nt['mensaje']) ?></div>
                            <div class="notif-time"><?= time_ago($nt['creado_en']) ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$recentNotifs): ?>
                        <div class="notif-empty">Sin notificaciones por ahora.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <span class="user-pill">
            <span class="material-symbols-outlined">account_circle</span>
            <strong><?= e($user['nombre'] ?? 'Usuario') ?></strong>
            <small><?= e(ucfirst((string)($user['rol'] ?? ''))) ?></small>
        </span>
        <a class="logout-link" href="logout.php" title="Cerrar sesión"><span class="material-symbols-outlined">logout</span></a>
        <?php if (is_client()): ?>
            <a class="btn btn-primary" href="cliente.php"><span class="material-symbols-outlined">explore</span>Explorar flota</a>
        <?php elseif (is_admin()): ?>
            <a class="btn btn-primary" href="reportes_admin.php"><span class="material-symbols-outlined">fact_check</span>Supervisar casos</a>
        <?php endif; ?>
    </div>
</header>

<main class="content">
<?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
