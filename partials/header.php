<?php
if (!isset($pageTitle)) $pageTitle = 'eBike Maintenance System';
$currentPage = basename($_SERVER['PHP_SELF']);
$user = current_user();
$pdoHeader = db();
$notifCount = get_unread_notifications_count($pdoHeader);
$recentNotifs = get_recent_notifications($pdoHeader, 5);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | eBike Maintenance System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="<?= is_client() ? 'cliente.php' : (is_mechanic() ? 'mecanico.php' : 'index.php') ?>">
        <div class="brand-mark"><span class="material-symbols-outlined">pedal_bike</span></div>
        <div><strong>eBike EMS</strong><small>Taller & Mantenimiento</small></div>
    </a>

    <div class="nav-label">
        <?= is_admin() ? 'ADMINISTRACIÓN' : (is_mechanic() ? 'TALLER & OPERACIONES' : 'PORTAL CLIENTE') ?>
    </div>
    <nav>
        <?php if (is_admin()): ?>
            <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">
                <span class="material-symbols-outlined">dashboard</span>Dashboard General
            </a>
            <a class="<?= $currentPage === 'reportes_admin.php' ? 'active' : '' ?>" href="reportes_admin.php">
                <span class="material-symbols-outlined">assignment_late</span>Recepción & Reportes
            </a>
            <a class="<?= $currentPage === 'mantenimientos.php' ? 'active' : '' ?>" href="mantenimientos.php">
                <span class="material-symbols-outlined">build</span>Mantenimientos
            </a>
            <a class="<?= $currentPage === 'inventario.php' ? 'active' : '' ?>" href="inventario.php">
                <span class="material-symbols-outlined">inventory_2</span>Inventario & Repuestos
            </a>
            <a class="<?= $currentPage === 'contabilidad.php' ? 'active' : '' ?>" href="contabilidad.php">
                <span class="material-symbols-outlined">account_balance_wallet</span>Contabilidad & Finanzas
            </a>
            <a class="<?= $currentPage === 'bicicletas.php' ? 'active' : '' ?>" href="bicicletas.php">
                <span class="material-symbols-outlined">electric_bike</span>Flota de Bicicletas
            </a>
            <a class="<?= $currentPage === 'cuentas.php' ? 'active' : '' ?>" href="cuentas.php">
                <span class="material-symbols-outlined">manage_accounts</span>Usuarios & Personal
            </a>
            <a class="<?= $currentPage === 'auditoria.php' ? 'active' : '' ?>" href="auditoria.php">
                <span class="material-symbols-outlined">verified_user</span>Historial & Auditoría
            </a>
            <a class="<?= $currentPage === 'configuracion.php' ? 'active' : '' ?>" href="configuracion.php">
                <span class="material-symbols-outlined">settings</span>Configuración Taller
            </a>
        <?php elseif (is_mechanic()): ?>
            <a class="<?= $currentPage === 'mecanico.php' ? 'active' : '' ?>" href="mecanico.php">
                <span class="material-symbols-outlined">construction</span>Panel del Mecánico
            </a>
            <a class="<?= $currentPage === 'mantenimientos.php' ? 'active' : '' ?>" href="mantenimientos.php">
                <span class="material-symbols-outlined">build</span>Mis Órdenes de Servicio
            </a>
            <a class="<?= $currentPage === 'inventario.php' ? 'active' : '' ?>" href="inventario.php">
                <span class="material-symbols-outlined">inventory</span>Catálogo de Repuestos
            </a>
            <a class="<?= $currentPage === 'bicicletas.php' ? 'active' : '' ?>" href="bicicletas.php">
                <span class="material-symbols-outlined">electric_bike</span>Consulta de Bicicletas
            </a>
        <?php elseif (is_client()): ?>
            <a class="<?= $currentPage === 'cliente.php' ? 'active' : '' ?>" href="cliente.php">
                <span class="material-symbols-outlined">home</span>Mi Panel de Cliente
            </a>
            <a class="<?= $currentPage === 'reportar.php' ? 'active' : '' ?>" href="reportar.php">
                <span class="material-symbols-outlined">report_problem</span>Reportar Falla / Taller
            </a>
            <a class="<?= $currentPage === 'mis_bicicletas.php' ? 'active' : '' ?>" href="mis_bicicletas.php">
                <span class="material-symbols-outlined">pedal_bike</span>Mis Bicicletas
            </a>
            <a class="<?= $currentPage === 'seguimiento.php' ? 'active' : '' ?>" href="seguimiento.php">
                <span class="material-symbols-outlined">search_check</span>Seguimiento en Vivo
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="online"><i></i> Base de datos activa</div>
        <small>eBike Maintenance System (EMS)</small>
        <div style="margin-top:8px;font-size:11px;color:#a3c5b5">COP · Pesos Colombianos</div>
    </div>
</aside>

<div class="app">
<header class="topbar">
    <div class="topbar-title">
        <button class="mobile-menu" type="button" onclick="toggleSidebar()"><span class="material-symbols-outlined">menu</span></button>
        <div>
            <span class="eyebrow"><?= is_admin() ? 'MÓDULO ADMINISTRATIVO' : (is_mechanic() ? 'MÓDULO TALLER' : 'ÁREA DE CLIENTES') ?></span>
            <strong><?= e($pageTitle) ?></strong>
        </div>
    </div>
    <div class="top-actions">
        <!-- Notificaciones -->
        <div class="notif-wrapper" style="position:relative">
            <button class="icon-btn notif-btn" type="button" onclick="toggleNotifs()" title="Notificaciones" aria-label="Notificaciones">
                <span class="material-symbols-outlined">notifications</span>
                <?php if ($notifCount > 0): ?>
                    <span class="notif-badge"><?= $notifCount ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <strong>Notificaciones</strong>
                    <small><?= $notifCount ?> pendiente(s)</small>
                </div>
                <div class="notif-list">
                    <?php foreach ($recentNotifs as $nt): ?>
                        <div class="notif-item <?= $nt['leida'] ? '' : 'unread' ?>">
                            <div class="notif-title"><?= e($nt['titulo']) ?></div>
                            <div class="notif-msg"><?= e($nt['mensaje']) ?></div>
                            <div class="notif-time"><?= format_date($nt['creado_en']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$recentNotifs): ?>
                        <div style="padding:15px;text-align:center;color:var(--muted);font-size:12px">No hay notificaciones recientes.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <span class="user-pill">
            <span class="material-symbols-outlined">account_circle</span>
            <strong><?= e($user['nombre'] ?? 'Usuario') ?></strong>
            <small style="opacity:0.85;margin-left:4px">(<?= ucfirst($user['rol'] ?? 'Mecánico') ?>)</small>
        </span>

        <a class="logout-link" href="logout.php" title="Cerrar sesión">
            <span class="material-symbols-outlined">logout</span>
        </a>

        <?php if (is_admin()): ?>
            <a class="btn btn-primary" href="mantenimientos.php?nuevo=1">
                <span class="material-symbols-outlined">add</span>Nuevo mantenimiento
            </a>
        <?php elseif (is_client()): ?>
            <a class="btn btn-primary" href="reportar.php">
                <span class="material-symbols-outlined">add_alert</span>Reportar falla
            </a>
        <?php endif; ?>
    </div>
</header>

<main class="content">
<?php if ($flash = get_flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
