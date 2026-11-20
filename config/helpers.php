<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const MAINTENANCE_STATES = [
    'Recibido',
    'Pendiente de revisión',
    'En diagnóstico',
    'Esperando repuestos',
    'En reparación',
    'En pruebas',
    'Reparado',
    'Entregado',
    'Cancelado'
];

const PROBLEM_CATEGORIES = [
    'Batería',
    'Motor eléctrico',
    'Controlador',
    'Frenos',
    'Llantas',
    'Sistema eléctrico',
    'Pantalla',
    'Cargador',
    'Transmisión',
    'Otros'
];

const BIKE_TYPES = [
    'Urbana',
    'Montaña',
    'Plegable',
    'Carga',
    'Ruta',
    'Fat Bike',
    'Scooter / Patineta',
    'Otra'
];

const EXPENSE_CATEGORIES = [
    'Compra de repuestos',
    'Compra de herramientas',
    'Mantenimiento de equipos',
    'Gastos operativos',
    'Otros gastos'
];

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function money(float|int|string|null $value): string
{
    return '$ ' . number_format((float)($value ?? 0), 0, ',', '.') . ' COP';
}

function format_date(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y H:i', $ts) : e($date);
}

function status_class(string $status): string
{
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'reparado', 'entregado', 'completado', 'finalizado', 'listo', 'pagado' => 'success',
        'en diagnóstico', 'en diagnostico', 'en reparación', 'en reparacion', 'en pruebas', 'en proceso', 'asignado' => 'info',
        'recibido', 'pendiente de revisión', 'pendiente de revision', 'esperando repuestos', 'esperando repuesto', 'pendiente' => 'warning',
        'cancelado', 'inactiva', 'stock bajo' => 'danger',
        default => 'neutral',
    };
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts ?: [], 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out ?: '—';
}

function post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_int(string $key, ?int $default = null): ?int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function post_float(string $key, float $default = 0.0): float
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT);
    return $value === false || $value === null ? $default : (float)$value;
}

/* =========================================================================
   PROTECCIÓN CSRF
   ========================================================================= */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        throw new RuntimeException('Token de seguridad CSRF inválido o expirado. Por favor, recarga el formulario.');
    }
}

/* =========================================================================
   SISTEMA DE AUDITORÍA Y TRAZABILIDAD
   ========================================================================= */
function audit(
    PDO $pdo,
    ?int $idManten,
    ?string $old,
    ?string $new,
    ?int $employeeId = null,
    string $accion = 'Cambio de estado',
    ?string $detalles = null
): void {
    try {
        $userId = $_SESSION['ebike_user']['id'] ?? null;
        $stmt = $pdo->prepare(
            'INSERT INTO logs_auditoria (id_manten, estado_anterior, estado_nuevo, id_empleado, id_usuario, accion, detalles)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$idManten, $old, $new, $employeeId, $userId, $accion, $detalles]);
    } catch (Throwable $e) {
        error_log('Error en audit(): ' . $e->getMessage());
    }
}

/* =========================================================================
   NOTIFICACIONES INTERNAS
   ========================================================================= */
function notify(
    PDO $pdo,
    string $titulo,
    string $mensaje,
    string $rolDestino = 'todos',
    ?int $idUsuario = null,
    ?string $enlace = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notificaciones (id_usuario, rol_destino, titulo, mensaje, enlace, leida)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$idUsuario, $rolDestino, $titulo, $mensaje, $enlace]);
    } catch (Throwable $e) {
        error_log('Error en notify(): ' . $e->getMessage());
    }
}

function get_unread_notifications_count(PDO $pdo): int
{
    if (!is_logged_in()) return 0;
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    $rol = (string)($user['rol'] ?? 'mecanico');
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE leida=0 AND (id_usuario=? OR rol_destino IN (?, 'todos'))");
        $stmt->execute([$userId, $rol]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function get_recent_notifications(PDO $pdo, int $limit = 6): array
{
    if (!is_logged_in()) return [];
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    $rol = (string)($user['rol'] ?? 'mecanico');
    try {
        $stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE (id_usuario=? OR rol_destino IN (?, 'todos')) ORDER BY id_notificacion DESC LIMIT ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $rol, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/* =========================================================================
   SESIÓN Y CONTROL DE ACCESO (RBAC)
   ========================================================================= */
function require_login(): void
{
    if (empty($_SESSION['ebike_user'])) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $login = (strpos($base, '/api') !== false) ? '../login.php' : 'login.php';
        redirect($login);
    }
}

function current_user(): array
{
    return $_SESSION['ebike_user'] ?? [];
}

function is_logged_in(): bool
{
    return !empty($_SESSION['ebike_user']);
}

function is_admin(): bool
{
    return is_logged_in() && (current_user()['rol'] ?? '') === 'admin';
}

function is_mechanic(): bool
{
    return is_logged_in() && (current_user()['rol'] ?? '') === 'mecanico';
}

function is_client(): bool
{
    return is_logged_in() && (current_user()['rol'] ?? '') === 'cliente';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('error', 'Acceso denegado: Se requieren permisos de Administrador.');
        if (is_client()) redirect('cliente.php');
        if (is_mechanic()) redirect('mecanico.php');
        redirect('login.php');
    }
}

function require_mechanic(): void
{
    require_login();
    if (!is_mechanic()) {
        flash('error', 'Acceso denegado: Se requieren permisos de Mecánico.');
        redirect('index.php');
    }
}

function require_client(): void
{
    require_login();
    if (!is_client()) {
        flash('error', 'Acceso denegado: Sección reservada para Clientes.');
        redirect('index.php');
    }
}

function require_mechanic_or_admin(): void
{
    require_login();
    if (!is_admin() && !is_mechanic()) {
        flash('error', 'Rol de usuario no autorizado para operaciones técnicas.');
        if (is_client()) redirect('cliente.php');
        redirect('login.php');
    }
}

/* =========================================================================
   SUBIDA SEGURA DE ARCHIVOS (FOTOGRAFÍAS DE REPORTES)
   ========================================================================= */
function handle_safe_upload(array $file, string $targetSubdir = 'uploads'): ?string
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('El archivo excede el tamaño máximo permitido de 5 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mime, $allowedMimes)) {
        throw new RuntimeException('Formato de imagen inválido. Solo se admiten archivos JPG, PNG y WEBP.');
    }

    $ext = $allowedMimes[$mime];
    $uploadDir = dirname(__DIR__) . '/assets/' . $targetSubdir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        // Crear .htaccess en uploads para impedir ejecución directa de scripts
        file_put_contents($uploadDir . '/.htaccess', "SetHandler default-handler\nphp_flag engine off\n");
    }

    $fileName = 'bici_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destination = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('No se pudo guardar la fotografía en el servidor.');
    }

    return 'assets/' . $targetSubdir . '/' . $fileName;
}

/* =========================================================================
   CONFIGURACIÓN GENERAL
   ========================================================================= */
function get_workshop_config(PDO $pdo): array
{
    static $config = null;
    if ($config !== null) return $config;
    try {
        $rows = $pdo->query("SELECT clave, valor FROM configuracion_taller")->fetchAll();
        $config = [];
        foreach ($rows as $r) {
            $config[$r['clave']] = $r['valor'];
        }
        return $config;
    } catch (Throwable) {
        return [];
    }
}
