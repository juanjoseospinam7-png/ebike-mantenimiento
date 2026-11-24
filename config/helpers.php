<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/* Catálogos del negocio: alquiler de eBikes con mantenimiento interno */

const RENTAL_STATES = [
    'solicitud_recibida'      => 'Solicitud recibida',
    'confirmado'              => 'Alquiler confirmado',
    'asignado'                => 'Bicicleta asignada',
    'activo'                  => 'Alquiler activo',
    'finalizacion_solicitada' => 'Finalización solicitada',
    'finalizado'              => 'Alquiler finalizado',
    'cancelado'               => 'Alquiler cancelado',
];

const RENTAL_TIMELINE = [
    'solicitud_recibida', 'confirmado', 'asignado', 'activo',
    'finalizacion_solicitada', 'finalizado'
];

const RENTAL_OPEN_STATES = ['solicitud_recibida', 'confirmado', 'asignado', 'activo', 'finalizacion_solicitada'];

const REPORT_STATES = [
    'En espera', 'En investigación', 'En mantenimiento', 'En pruebas',
    'Terminado', 'Requiere nueva revisión', 'Cerrado', 'Cancelado'
];

const REPORT_ACTIVE_STATES = ['En espera', 'En investigación', 'En mantenimiento', 'En pruebas', 'Requiere nueva revisión'];

const BIKE_OPERATIVE_STATES = [
    'disponible' => 'Disponible',
    'reservada' => 'Reservada',
    'alquilada' => 'Alquilada',
    'en_mantenimiento' => 'En mantenimiento',
    'en_revision' => 'En revisión',
    'fuera_de_servicio' => 'Fuera de servicio',
];

const REPORT_CATEGORIES = [
    'Problema con la batería',
    'Problema con el motor',
    'Problema con los frenos',
    'Problema con las llantas',
    'Problema eléctrico',
    'Problema durante el recorrido',
    'Otro inconveniente',
];

const PROBLEM_CATEGORIES = REPORT_CATEGORIES;

// Compatibilidad con incidencias históricas
const LEGACY_CATEGORY_MAP = [
    'Batería' => 'Problema con la batería',
    'Motor eléctrico' => 'Problema con el motor',
    'Controlador' => 'Problema eléctrico',
    'Frenos' => 'Problema con los frenos',
    'Llantas' => 'Problema con las llantas',
    'Sistema eléctrico' => 'Problema eléctrico',
    'Pantalla' => 'Problema eléctrico',
    'Cargador' => 'Problema con la batería',
    'Transmisión' => 'Problema durante el recorrido',
    'Otros' => 'Otro inconveniente',
];

const MAINTENANCE_STATES = REPORT_STATES;

const BIKE_TYPES = [
    'Urbana', 'Montaña', 'Plegable', 'Carga', 'Ruta', 'Fat Bike', 'Scooter / Patineta', 'Otra'
];

const EXPENSE_CATEGORIES = [
    'Compra de repuestos', 'Compra de herramientas', 'Mantenimiento de equipos',
    'Gastos operativos', 'Otros gastos'
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

function time_ago(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    if (!$ts) return '—';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'hace un momento';
    if ($diff < 3600) return 'hace ' . intdiv($diff, 60) . ' min';
    if ($diff < 86400) return 'hace ' . intdiv($diff, 3600) . ' h';
    if ($diff < 604800) return 'hace ' . intdiv($diff, 86400) . ' d';
    return format_date($date);
}

function status_class(string $status): string
{
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'terminado', 'cerrado', 'finalizado', 'listo', 'pagado', 'confirmado', 'aprobado', 'disponible', 'success' => 'success',
        'en investigación', 'en investigacion', 'en mantenimiento', 'en pruebas', 'asignado', 'activo',
        'alquilada', 'reservada', 'en proceso', 'info' => 'info',
        'en espera', 'solicitud recibida', 'finalización solicitada', 'finalizacion solicitada',
        'pendiente', 'pendiente de revisión', 'pendiente de revision', 'requiere nueva revisión',
        'requiere nueva revision', 'stock bajo', 'warning' => 'warning',
        'cancelado', 'fuera de servicio', 'en revisión', 'en revision', 'inactiva', 'fallido', 'danger' => 'danger',
        default => 'neutral',
    };
}

function rental_label(string $state): string
{
    return RENTAL_STATES[$state] ?? $state;
}

function bike_state_label(string $state): string
{
    return BIKE_OPERATIVE_STATES[$state] ?? $state;
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
    if (!array_key_exists($key, $_POST)) return $default;
    $value = filter_var($_POST[$key], FILTER_VALIDATE_INT);
    return $value === false ? $default : (int)$value;
}

function post_float(string $key, float $default = 0.0): float
{
    if (!array_key_exists($key, $_POST)) return $default;
    $value = filter_var($_POST[$key], FILTER_VALIDATE_FLOAT);
    return $value === false ? $default : (float)$value;
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
   AUDITORÍA, HISTORIAL Y NOTIFICACIONES
   ========================================================================= */
function audit(
    PDO $pdo,
    ?int $idManten,
    ?string $old,
    ?string $new,
    ?int $employeeId = null,
    string $accion = 'Cambio de estado',
    ?string $detalles = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO logs_auditoria
             (id_manten, entidad_tipo, entidad_id, estado_anterior, estado_nuevo, id_empleado, id_usuario, accion, detalles)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $idManten, $entityType, $entityId, $old, $new, $employeeId,
            $_SESSION['ebike_user']['id'] ?? null, $accion, $detalles
        ]);
    } catch (Throwable $ex) {
        error_log('Error en audit(): ' . $ex->getMessage());
    }
}

function state_history(PDO $pdo, string $tipo, int $id, ?string $anterior, string $nuevo, ?string $descripcion = null): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO historial_estados (entidad_tipo, entidad_id, estado_anterior, estado_nuevo, descripcion, id_usuario)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tipo, $id, $anterior, $nuevo, $descripcion, $_SESSION['ebike_user']['id'] ?? null]);
    } catch (Throwable $ex) {
        error_log('Error en state_history(): ' . $ex->getMessage());
    }
}

function unique_code(PDO $pdo, string $table, string $column, string $prefix): string
{
    do {
        $code = $prefix . '-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

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
    } catch (Throwable $ex) {
        error_log('Error en notify(): ' . $ex->getMessage());
    }
}

function mark_notification_read(PDO $pdo, int $id): void
{
    $user = current_user();
    if (!$user) return;
    $stmt = $pdo->prepare(
        'UPDATE notificaciones SET leida = 1 WHERE id_notificacion = ?
         AND (id_usuario = ? OR rol_destino IN (?, \'todos\'))'
    );
    $stmt->execute([$id, (int)$user['id'], (string)($user['rol'] ?? '')]);
}

function get_unread_notifications_count(PDO $pdo): int
{
    if (!is_logged_in()) return 0;
    $user = current_user();
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE leida=0 AND (id_usuario=? OR rol_destino IN (?, 'todos'))");
        $stmt->execute([(int)($user['id'] ?? 0), (string)($user['rol'] ?? '')]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function get_recent_notifications(PDO $pdo, int $limit = 6): array
{
    if (!is_logged_in()) return [];
    $user = current_user();
    try {
        $stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE (id_usuario=? OR rol_destino IN (?, 'todos')) ORDER BY id_notificacion DESC LIMIT ?");
        $stmt->bindValue(1, (int)($user['id'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(2, (string)($user['rol'] ?? ''), PDO::PARAM_STR);
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
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $login = str_contains($base, '/api') ? '../login.php' : 'login.php';
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

function home_for_role(): string
{
    if (is_admin()) return 'index.php';
    if (is_mechanic()) return 'mecanico.php';
    if (is_client()) return 'cliente.php';
    return 'login.php';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('error', 'Acceso denegado: sección exclusiva de administradores.');
        redirect(home_for_role());
    }
}

function require_mechanic(): void
{
    require_login();
    if (!is_mechanic()) {
        flash('error', 'Acceso denegado: sección exclusiva del departamento técnico.');
        redirect(home_for_role());
    }
}

function require_client(): void
{
    require_login();
    if (!is_client()) {
        flash('error', 'Acceso denegado: sección exclusiva de clientes.');
        redirect(home_for_role());
    }
}

function require_mechanic_or_admin(): void
{
    require_login();
    if (!is_admin() && !is_mechanic()) {
        flash('error', 'Rol no autorizado para operaciones técnicas.');
        redirect(home_for_role());
    }
}

function require_roles(string ...$roles): void
{
    require_login();
    if (!in_array((string)(current_user()['rol'] ?? ''), $roles, true)) {
        flash('error', 'No tienes permisos para realizar esta acción.');
        redirect(home_for_role());
    }
}

/* =========================================================================
   DISPONIBILIDAD Y ESTADO OPERATIVO DE LA FLOTA
   ========================================================================= */
function available_for_rental(PDO $pdo, int $idBicicleta, string $inicio, string $fin, ?int $excluirAlquiler = null): array
{
    $stmt = $pdo->prepare('SELECT codigo, estado_operativo, nivel_bateria, activa FROM bicicletas WHERE id_bicicleta = ?');
    $stmt->execute([$idBicicleta]);
    $bike = $stmt->fetch();
    if (!$bike) return [false, 'La bicicleta no existe.'];
    if ((int)$bike['activa'] !== 1 || !in_array($bike['estado_operativo'], ['disponible', 'reservada'], true)) {
        return [false, 'La bicicleta no está disponible para alquiler en este momento (' . bike_state_label($bike['estado_operativo']) . ').'];
    }
    if ((int)$bike['nivel_bateria'] < 15) {
        return [false, 'La bicicleta requiere carga antes de ser alquilada.'];
    }
    $sql = "SELECT codigo_alquiler FROM alquileres
            WHERE id_bicicleta = ?
              AND estado NOT IN ('cancelado', 'finalizado')
              AND fecha_inicio < ? AND fecha_fin_estimada > ?";
    $params = [$idBicicleta, $fin, $inicio];
    if ($excluirAlquiler !== null) {
        $sql .= ' AND id_alquiler <> ?';
        $params[] = $excluirAlquiler;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        return [false, 'La bicicleta ya está reservada/alquilada en el horario solicitado.'];
    }
    return [true, 'Disponible'];
}

function refresh_bike_state(PDO $pdo, int $idBicicleta): void
{
    $stmt = $pdo->prepare('SELECT estado_operativo, activa FROM bicicletas WHERE id_bicicleta = ?');
    $stmt->execute([$idBicicleta]);
    $bike = $stmt->fetch();
    if (!$bike) return;
    // Los bloqueos técnicos nunca se liberan automáticamente.
    if (in_array($bike['estado_operativo'], ['en_mantenimiento', 'en_revision', 'fuera_de_servicio'], true)
        || (int)$bike['activa'] === 0) {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT
           MAX(CASE WHEN estado IN ('activo','asignado') THEN 1 ELSE 0 END) AS en_uso,
           MAX(CASE WHEN estado IN ('confirmado','solicitud_recibida') AND fecha_inicio > NOW() THEN 1 ELSE 0 END) AS reservada
         FROM alquileres
         WHERE id_bicicleta = ? AND estado IN ('solicitud_recibida','confirmado','asignado','activo')"
    );
    $stmt->execute([$idBicicleta]);
    $row = $stmt->fetch();
    $new = ((int)($row['en_uso'] ?? 0) === 1) ? 'alquilada'
        : (((int)($row['reservada'] ?? 0) === 1) ? 'reservada' : 'disponible');
    if ($new !== $bike['estado_operativo']) {
        $pdo->prepare('UPDATE bicicletas SET estado_operativo = ? WHERE id_bicicleta = ?')
            ->execute([$new, $idBicicleta]);
        state_history($pdo, 'bicicleta', $idBicicleta, $bike['estado_operativo'], $new, 'Actualización automática por alquiler');
    }
}

function redirect_back(string $default): never
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $parts = parse_url($ref);
        $path = (string)($parts['path'] ?? '');
        if (str_starts_with($path, '/') && !str_contains($path, '//')) {
            $name = basename($path);
            if (preg_match('/^[a-z0-9_]+\.php$/i', $name)) {
                $query = (string)($parts['query'] ?? '');
                redirect($name . ($query !== '' ? '?' . $query : ''));
            }
        }
    }
    redirect($default);
}

function rental_price(array $bike, int $horas): float
{
    if ($horas >= 24) {
        return round((int)ceil($horas / 24) * (float)($bike['precio_dia'] ?? 0), 2);
    }
    return round($horas * (float)($bike['precio_hora'] ?? 0), 2);
}

/* =========================================================================
   SUBIDA SEGURA DE ARCHIVOS
   ========================================================================= */
function handle_safe_upload(array $file, string $targetSubdir = 'uploads'): ?string
{
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('El archivo excede el tamaño máximo permitido de 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato de imagen inválido. Solo se admiten JPG, PNG y WEBP.');
    }
    $uploadDir = dirname(__DIR__) . '/assets/' . $targetSubdir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir . '/.htaccess', "SetHandler default-handler\nphp_flag engine off\nOptions -Indexes\n");
    }
    $fileName = 'ebike_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('No se pudo guardar la fotografía en el servidor.');
    }
    return 'assets/' . $targetSubdir . '/' . $fileName;
}

function handle_safe_uploads(array $files, int $max = 4): array
{
    $paths = [];
    $list = $files['name'] ?? null;
    if (!is_array($list)) {
        $path = handle_safe_upload($files);
        return $path ? [$path] : [];
    }
    for ($i = 0; $i < min($max, count($list)); $i++) {
        $file = [
            'name' => $files['name'][$i], 'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
        $path = handle_safe_upload($file);
        if ($path) $paths[] = $path;
    }
    return $paths;
}

/* =========================================================================
   CONFIGURACIÓN DE LA EMPRESA
   ========================================================================= */
function get_workshop_config(PDO $pdo): array
{
    static $config = null;
    if ($config !== null) return $config;
    $defaults = [
        'nombre_empresa' => 'eBike · Movilidad Eléctrica',
        'nombre_taller' => 'eBike · Movilidad Eléctrica',
        'nit' => '901.845.123-4',
        'telefono' => '+57 310 987 6543',
        'email' => 'contacto@ebike.local',
        'direccion' => 'Bogotá, Colombia',
        'moneda' => 'COP',
        'simbolo_moneda' => '$',
        'horario_atencion' => 'Lun a Dom · 8:00 a 20:00',
        'politica_alquiler' => 'Cancelación gratuita hasta 2 horas antes del inicio.',
        'deposito_garantia' => '0',
        'garantia_dias' => '90',
    ];
    try {
        $rows = $pdo->query('SELECT clave, valor FROM configuracion_taller')->fetchAll();
        foreach ($rows as $row) $defaults[$row['clave']] = $row['valor'];
    } catch (Throwable) {
        // Se usan los valores por defecto si la tabla aún no existe.
    }
    $config = $defaults;
    return $config;
}

function company_name(PDO $pdo): string
{
    $cfg = get_workshop_config($pdo);
    return (string)($cfg['nombre_empresa'] ?? $cfg['nombre_taller'] ?? 'eBike');
}
