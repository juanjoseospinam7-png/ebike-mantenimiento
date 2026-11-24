-- ====================================================================
-- eBike · Plataforma de Alquiler de Bicicletas Eléctricas
-- ESQUEMA UNIFICADO PARA INSTALACIÓN LIMPIA (XAMPP / MySQL 8+ / MariaDB)
-- Base de datos: ebike_taller (se conserva el nombre para no romper despliegues)
-- No incluye alquileres ni reportes de demostración.
-- Para bases existentes ejecutar además: database/migracion_alquiler.sql
-- ====================================================================

CREATE DATABASE IF NOT EXISTS ebike_taller
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ebike_taller;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Personal técnico (mantenimiento interno)
CREATE TABLE IF NOT EXISTS empleados (
    id_empleado INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    cargo VARCHAR(80) NOT NULL DEFAULT 'Mecánico',
    telefono VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    especialidad VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Usuarios (admin / mecanico / cliente)
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('admin','mecanico','cliente') NOT NULL DEFAULT 'cliente',
    id_empleado INT NULL,
    email VARCHAR(120) NULL,
    telefono VARCHAR(30) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_login DATETIME NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_empleado (id_empleado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Flota de alquiler
CREATE TABLE IF NOT EXISTS bicicletas (
    id_bicicleta INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    modelo VARCHAR(120) NOT NULL,
    nombre VARCHAR(120) NULL,
    foto VARCHAR(255) NULL,
    descripcion TEXT NULL,
    caracteristicas TEXT NULL,
    autonomia_km INT NOT NULL DEFAULT 0,
    capacidad_bateria_wh INT NOT NULL DEFAULT 0,
    nivel_bateria TINYINT NOT NULL DEFAULT 100,
    precio_hora DECIMAL(10,2) NOT NULL DEFAULT 15000,
    precio_dia DECIMAL(10,2) NOT NULL DEFAULT 90000,
    estado_operativo ENUM('disponible','reservada','alquilada','en_mantenimiento','en_revision','fuera_de_servicio')
        NOT NULL DEFAULT 'disponible',
    fecha_incorporacion DATETIME NULL,
    marca VARCHAR(100) DEFAULT 'eBike',
    cliente VARCHAR(120) NULL,
    telefono_cliente VARCHAR(30) NULL,
    id_usuario INT NULL,
    tipo_bicicleta VARCHAR(80) DEFAULT 'Urbana',
    numero_serie VARCHAR(100) NULL,
    color VARCHAR(50) NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_bicicleta_codigo (codigo),
    INDEX idx_bicicletas_usuario (id_usuario),
    INDEX idx_bici_estado (estado_operativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Alquileres
CREATE TABLE IF NOT EXISTS alquileres (
    id_alquiler INT AUTO_INCREMENT PRIMARY KEY,
    codigo_alquiler VARCHAR(30) NOT NULL UNIQUE,
    id_cliente INT NOT NULL,
    id_bicicleta INT NOT NULL,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin_estimada DATETIME NOT NULL,
    fecha_fin_real DATETIME NULL,
    duracion_horas INT NOT NULL,
    precio_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado ENUM('solicitud_recibida','confirmado','asignado','activo','finalizacion_solicitada','finalizado','cancelado')
        NOT NULL DEFAULT 'solicitud_recibida',
    estado_pago ENUM('pendiente','confirmado','fallido') NOT NULL DEFAULT 'pendiente',
    metodo_pago VARCHAR(50) NULL,
    notas TEXT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_alq_cliente (id_cliente),
    INDEX idx_alq_bici (id_bicicleta),
    INDEX idx_alq_estado (estado),
    INDEX idx_alq_rango (fecha_inicio, fecha_fin_estimada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tickets (snapshot inmutable del documento entregado al cliente)
CREATE TABLE IF NOT EXISTS tickets_alquiler (
    id_ticket INT AUTO_INCREMENT PRIMARY KEY,
    codigo_ticket VARCHAR(30) NOT NULL UNIQUE,
    id_alquiler INT NOT NULL UNIQUE,
    id_cliente INT NOT NULL,
    id_bicicleta INT NOT NULL,
    cliente_snapshot VARCHAR(120) NOT NULL,
    bicicleta_snapshot VARCHAR(180) NOT NULL,
    codigo_bici_snapshot VARCHAR(50) NOT NULL,
    precio_snapshot DECIMAL(10,2) NOT NULL,
    emitido_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alquiler) REFERENCES alquileres(id_alquiler) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_cliente) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_ticket_cliente (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Línea de tiempo del alquiler
CREATE TABLE IF NOT EXISTS alquiler_historial (
    id_historial INT AUTO_INCREMENT PRIMARY KEY,
    id_alquiler INT NOT NULL,
    estado VARCHAR(50) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    id_usuario INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alquiler) REFERENCES alquileres(id_alquiler) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_alq_hist_alquiler (id_alquiler)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Reportes de incidencias durante el alquiler (mantenimiento interno)
CREATE TABLE IF NOT EXISTS reportes_clientes (
    id_reporte INT AUTO_INCREMENT PRIMARY KEY,
    codigo_seguimiento VARCHAR(40) UNIQUE NOT NULL,
    id_bicicleta INT NOT NULL,
    id_alquiler INT NULL,
    id_ticket INT NULL,
    id_cliente INT NULL,
    nombre_cliente VARCHAR(120) NOT NULL,
    telefono_cliente VARCHAR(30) DEFAULT NULL,
    marca VARCHAR(100) NULL,
    modelo VARCHAR(120) NULL,
    codigo_bici VARCHAR(50) NULL,
    tipo_bicicleta VARCHAR(80) NULL,
    categoria_problema VARCHAR(80) NOT NULL DEFAULT 'Otro inconveniente',
    prioridad ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
    descripcion TEXT NOT NULL,
    observaciones TEXT NULL,
    foto VARCHAR(255) NULL,
    estado VARCHAR(100) NOT NULL DEFAULT 'En espera',
    id_tecnico INT NULL,
    id_mantenimiento INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    atendido_en DATETIME NULL,
    cerrado_en DATETIME NULL,
    validado_por INT NULL,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_alquiler) REFERENCES alquileres(id_alquiler) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_ticket) REFERENCES tickets_alquiler(id_ticket) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_cliente) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_tecnico) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (validado_por) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_reportes_estado (estado),
    INDEX idx_reportes_codigo (codigo_seguimiento),
    INDEX idx_reportes_bicicleta (id_bicicleta),
    INDEX idx_reportes_alquiler (id_alquiler)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Fotografías múltiples de cada incidencia
CREATE TABLE IF NOT EXISTS reporte_fotos (
    id_foto INT AUTO_INCREMENT PRIMARY KEY,
    id_reporte INT NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_reporte) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_fotos_reporte (id_reporte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Inventario interno de mantenimiento (se crea antes porque mantenimientos la referencia)
CREATE TABLE IF NOT EXISTS inventario_costos_arreglos (
    id_repuesto INT AUTO_INCREMENT PRIMARY KEY,
    codigo_rep VARCHAR(50) NULL,
    nombre_rep VARCHAR(100) NOT NULL,
    descripcion TEXT NULL,
    categoria VARCHAR(80) DEFAULT 'Repuestos Generales',
    cantidad_disponible INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 5,
    precio_actual DECIMAL(10,2) NOT NULL,
    costo_repuestos DECIMAL(10,2) NOT NULL,
    proveedor VARCHAR(100),
    costo_mano_obra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    costo_total DECIMAL(10,2) AS (costo_repuestos + costo_mano_obra) STORED,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repuesto_codigo (codigo_rep),
    INDEX idx_repuesto_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Orden de trabajo interna por incidencia
CREATE TABLE IF NOT EXISTS mantenimientos (
    id_manten INT PRIMARY KEY AUTO_INCREMENT,
    fecha_ingreso DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_tecnico INT NOT NULL,
    diagnostico VARCHAR(255) NOT NULL,
    trabajos_realizados TEXT NULL,
    observaciones TEXT NULL,
    tipo_manten VARCHAR(255) NOT NULL,
    estado VARCHAR(255) NOT NULL DEFAULT 'En espera',
    prioridad ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
    id_repuesto INT NULL,
    costo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mano_obra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_repuestos DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    descuento DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado_pago ENUM('pendiente','pagado','cancelado') NOT NULL DEFAULT 'pendiente',
    metodo_pago VARCHAR(50) NULL,
    fecha_pago DATETIME NULL,
    fecha_salida DATETIME NULL,
    cerrado_en DATETIME NULL,
    id_bicicleta INT NULL,
    id_reporte_cliente INT NULL,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_tecnico) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (id_reporte_cliente) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_manten_estado (estado),
    INDEX idx_manten_estado_pago (estado_pago),
    INDEX idx_manten_fecha (fecha_ingreso),
    INDEX idx_manten_tecnico (id_tecnico),
    INDEX idx_manten_reporte (id_reporte_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Repuestos usados en mantenimiento interno
CREATE TABLE IF NOT EXISTS mantenimiento_repuestos (
    id_manten INT NOT NULL,
    id_repuesto INT NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id_manten, id_repuesto),
    FOREIGN KEY (id_manten) REFERENCES mantenimientos(id_manten) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Mecánicos participantes en cada reporte (colaboración)
CREATE TABLE IF NOT EXISTS participantes_reporte (
    id_reporte INT NOT NULL,
    id_empleado INT NOT NULL,
    rol_participacion VARCHAR(60) NOT NULL DEFAULT 'Mecánico participante',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_reporte, id_empleado),
    FOREIGN KEY (id_reporte) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Intervenciones individuales (historial compartido, inmutable)
CREATE TABLE IF NOT EXISTS intervenciones_mantenimiento (
    id_intervencion INT AUTO_INCREMENT PRIMARY KEY,
    id_reporte INT NOT NULL,
    id_mecanico INT NOT NULL,
    id_manten INT NULL,
    diagnostico TEXT NOT NULL,
    trabajos_realizados TEXT NOT NULL,
    observaciones TEXT NULL,
    estado_reporte VARCHAR(60) NOT NULL DEFAULT 'En investigación',
    id_repuesto INT NULL,
    cantidad_repuesto INT NOT NULL DEFAULT 0,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_reporte) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_mecanico) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (id_manten) REFERENCES mantenimientos(id_manten) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_interv_reporte (id_reporte),
    INDEX idx_interv_mecanico (id_mecanico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Historial universal de estados
CREATE TABLE IF NOT EXISTS historial_estados (
    id_historial INT AUTO_INCREMENT PRIMARY KEY,
    entidad_tipo ENUM('alquiler','reporte','mantenimiento','bicicleta') NOT NULL,
    entidad_id INT NOT NULL,
    estado_anterior VARCHAR(60) NULL,
    estado_nuevo VARCHAR(60) NOT NULL,
    descripcion TEXT NULL,
    id_usuario INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_hist_entidad (entidad_tipo, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Control de batería
CREATE TABLE IF NOT EXISTS bateria_revisiones (
    id_revision INT AUTO_INCREMENT PRIMARY KEY,
    id_bicicleta INT NOT NULL,
    nivel_bateria TINYINT NOT NULL,
    observaciones VARCHAR(255) NULL,
    id_usuario INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_bateria_bici (id_bicicleta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Pagos (registro interno; NO es una pasarela de pago)
CREATE TABLE IF NOT EXISTS pagos (
    id_pago INT AUTO_INCREMENT PRIMARY KEY,
    id_alquiler INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    metodo VARCHAR(50) NOT NULL DEFAULT 'Pendiente',
    referencia VARCHAR(100) NULL,
    estado ENUM('pendiente','confirmado','fallido') NOT NULL DEFAULT 'pendiente',
    registrado_por INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alquiler) REFERENCES alquileres(id_alquiler) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (registrado_por) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_pagos_alquiler (id_alquiler)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Auditoría
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_manten INT NULL,
    entidad_tipo VARCHAR(40) NULL,
    entidad_id INT NULL,
    estado_anterior TEXT NULL,
    estado_nuevo TEXT NULL,
    id_empleado INT NULL,
    id_usuario INT NULL,
    accion VARCHAR(100) DEFAULT 'Cambio de estado',
    detalles TEXT NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_manten) REFERENCES mantenimientos(id_manten) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_logs_manten (id_manten),
    INDEX idx_logs_fecha (fecha_hora),
    INDEX idx_logs_entidad (entidad_tipo, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Gastos de la operación
CREATE TABLE IF NOT EXISTS gastos (
    id_gasto INT AUTO_INCREMENT PRIMARY KEY,
    categoria ENUM('Compra de repuestos','Compra de herramientas','Mantenimiento de equipos','Gastos operativos','Otros gastos') NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    fecha DATE NOT NULL,
    metodo_pago VARCHAR(50) NOT NULL DEFAULT 'Efectivo',
    comprobante VARCHAR(100) NULL,
    id_usuario INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_gastos_fecha (fecha),
    INDEX idx_gastos_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Kardex de inventario
CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    id_repuesto INT NOT NULL,
    tipo ENUM('entrada','salida','ajuste') NOT NULL,
    cantidad INT NOT NULL,
    costo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    motivo VARCHAR(255) NOT NULL,
    id_manten INT NULL,
    id_usuario INT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (id_manten) REFERENCES mantenimientos(id_manten) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_mov_repuesto (id_repuesto),
    INDEX idx_mov_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Notificaciones
CREATE TABLE IF NOT EXISTS notificaciones (
    id_notificacion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NULL,
    rol_destino ENUM('admin','mecanico','cliente','todos') NOT NULL DEFAULT 'todos',
    titulo VARCHAR(150) NOT NULL,
    mensaje TEXT NOT NULL,
    enlace VARCHAR(255) NULL,
    leida TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notif_usuario (id_usuario),
    INDEX idx_notif_leida (leida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Configuración de la empresa
CREATE TABLE IF NOT EXISTS configuracion_taller (
    clave VARCHAR(50) PRIMARY KEY,
    valor TEXT NOT NULL,
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW configuracion_empresa AS
SELECT clave, valor, descripcion FROM configuracion_taller;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------------------
-- DATOS BASE (sin alquileres ni incidencias ficticias)
-- Contraseñas de prueba: admin123 / mecanico123 / cliente123
-- --------------------------------------------------------------------

INSERT INTO empleados (id_empleado, nombre, cargo, especialidad) VALUES
(1, 'Carlos Gómez', 'Mecánico Senior', 'Motores y Baterías'),
(2, 'María López', 'Mecánica Especialista', 'Sistemas Eléctricos y Controladores'),
(3, 'Pedro Ramírez', 'Mecánico de Flota', 'Frenos y Mecánica General')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cargo = VALUES(cargo);

INSERT INTO usuarios (id, nombre, usuario, password, rol, id_empleado, email, telefono) VALUES
(1, 'Administrador eBike', 'admin', '$2y$10$3cTyx4c9s43zx/yUutEbRu7HS4oyaOq.CFX1qi0Ta4h8SI40FP4S2', 'admin', NULL, 'admin@ebike.local', NULL),
(2, 'Carlos Gómez', 'carlos.gomez', '$2y$10$g6C6s1Tuvdnr7ZBy9m.LMuwTM6B2XTUanT0Viv2q/vqPGDvK2SnCK', 'mecanico', 1, 'carlos@ebike.local', NULL),
(3, 'María López', 'maria.lopez', '$2y$10$g6C6s1Tuvdnr7ZBy9m.LMuwTM6B2XTUanT0Viv2q/vqPGDvK2SnCK', 'mecanico', 2, 'maria@ebike.local', NULL),
(4, 'Cliente Demo', 'cliente.demo', '$2y$10$JtjrTUDhltOTd97BLgJAgOJnpD5YRzWcibcqh68Mvp/fchoU9iWkC', 'cliente', NULL, 'cliente@demo.com', '3000000001')
ON DUPLICATE KEY UPDATE password = VALUES(password), rol = VALUES(rol), activo = 1;

INSERT INTO bicicletas (id_bicicleta, codigo, modelo, nombre, descripcion, caracteristicas, autonomia_km, capacidad_bateria_wh, nivel_bateria, precio_hora, precio_dia, estado_operativo, fecha_incorporacion, marca, tipo_bicicleta, color, activa) VALUES
(1, 'EBK-00001', 'City Lite 250W', 'City Lite', 'Urbana ligera para recorridos diarios.', 'Motor 250W · Asistencia 5 niveles · Frenos de disco', 50, 504, 100, 18000, 95000, 'disponible', NOW(), 'eBike', 'Urbana', 'Blanco', 1),
(2, 'EBK-00002', 'Trail Pro 500W', 'Trail Pro', 'eBike de montaña para rutas exigentes.', 'Motor 500W · Suspensión delantera · 9 velocidades', 65, 720, 100, 22000, 120000, 'disponible', NOW(), 'eBike', 'Montaña', 'Negro/Verde', 1),
(3, 'EBK-00003', 'Urban Fold 350W', 'Urban Fold', 'Plegable para movilidad multimodal.', 'Motor 350W · Plegado rápido · Ruedas 20"', 35, 360, 100, 14000, 80000, 'disponible', NOW(), 'eBike', 'Plegable', 'Azul', 1),
(4, 'EBK-00004', 'Cargo Max 750W', 'Cargo Max', 'Bicicleta de carga para entregas urbanas.', 'Motor 750W · Canasta reforzada · Frenos hidráulicos', 45, 720, 100, 25000, 135000, 'disponible', NOW(), 'eBike', 'Carga', 'Gris', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), estado_operativo = VALUES(estado_operativo);

INSERT INTO inventario_costos_arreglos
(codigo_rep, nombre_rep, descripcion, categoria, cantidad_disponible, stock_minimo, precio_actual, costo_repuestos, proveedor, costo_mano_obra) VALUES
('REP-0001', 'Pastillas de freno cerámicas', 'Frenos de disco hidráulicos', 'Frenos', 25, 5, 45000, 30000, 'Shimano', 20000),
('REP-0002', 'Cadena e-bike 9V reforzada', 'Alto torque', 'Transmisión', 18, 5, 60000, 42000, 'KMC', 20000),
('REP-0003', 'Batería Li-Ion 48V 15Ah', 'Celdas Samsung', 'Batería', 6, 2, 1450000, 1100000, 'Samsung SDI', 80000),
('REP-0004', 'Controlador Brushless 48V 22A', 'Onda sinusoidal', 'Controlador', 8, 3, 280000, 195000, 'Bafang', 60000),
('REP-0005', 'Display LCD Color con USB', 'Pantalla multifunción', 'Pantalla', 12, 3, 190000, 130000, 'Bafang', 40000),
('REP-0006', 'Cargador Rápido 48V 3A', 'Corte automático', 'Cargador', 15, 4, 150000, 95000, 'Dpower', 15000),
('REP-0007', 'Llanta Antipinchazo 27.5x2.35', 'Refuerzo kevlar', 'Llantas', 20, 5, 120000, 80000, 'Maxxis', 25000),
('REP-0008', 'Sensor de Pedaleo Asistido (PAS)', 'Sensor magnético dual', 'Sistema eléctrico', 14, 4, 65000, 40000, 'Bafang', 30000)
ON DUPLICATE KEY UPDATE nombre_rep = VALUES(nombre_rep);

INSERT INTO configuracion_taller (clave, valor, descripcion) VALUES
('nombre_empresa', 'eBike · Movilidad Eléctrica', 'Razón comercial de la empresa de alquiler'),
('nombre_taller', 'eBike · Movilidad Eléctrica', 'Nombre comercial'),
('nit', '901.845.123-4', 'NIT / Identificación fiscal'),
('telefono', '+57 310 987 6543', 'Teléfono de atención al cliente'),
('email', 'contacto@ebike.local', 'Correo de atención al cliente'),
('direccion', 'Cra 45 # 128 - 20, Bogotá, Colombia', 'Dirección de la empresa'),
('moneda', 'COP', 'Moneda oficial'),
('simbolo_moneda', '$', 'Símbolo monetario'),
('horario_atencion', 'Lun a Dom · 8:00 a 20:00', 'Horario de atención'),
('politica_alquiler', 'Cancelación gratuita hasta 2 horas antes del inicio.', 'Política comercial'),
('deposito_garantia', '0', 'Depósito de garantía (0 = no se cobra)'),
('garantia_dias', '90', 'Días de garantía de intervenciones internas')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
