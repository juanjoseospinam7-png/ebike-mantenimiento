-- ====================================================================
-- E-BIKE MAINTENANCE SYSTEM (EMS)
-- BASE DE DATOS COMPLETA E INSTALACIÓN LIMPIA
-- ====================================================================

CREATE DATABASE IF NOT EXISTS ebike_taller
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ebike_taller;

-- 1. Tabla de Empleados / Técnicos
CREATE TABLE IF NOT EXISTS empleados (
    id_empleado INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    cargo VARCHAR(80) NOT NULL DEFAULT 'Técnico',
    telefono VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    especialidad VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Usuarios (Admin, Mecánico, Cliente)
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    usuario VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('admin','mecanico','cliente') NOT NULL DEFAULT 'mecanico',
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

-- Usuario Administrador Inicial (admin / admin123)
INSERT INTO usuarios (nombre, usuario, password, rol, activo)
VALUES ('Administrador eBike', 'admin', '$2y$12$gpjidw/rRmmWMfJ8lKSYR.ubVTaaty709G0ow5wzJLrFpsbzgr82W', 'admin', 1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), password=VALUES(password), rol='admin', activo=1;

-- Empleados iniciales
INSERT INTO empleados (nombre, cargo, especialidad) VALUES
('Carlos Gómez', 'Técnico Senior', 'Motores y Baterías'),
('María López', 'Técnica Especialista', 'Sistemas Eléctricos y Controladores'),
('Pedro Ramírez', 'Técnico de Taller', 'Frenos y Mecánica General')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

-- Cuentas de prueba iniciales para Mecánico y Cliente
INSERT INTO usuarios (nombre, usuario, password, rol, id_empleado, activo)
VALUES ('Carlos Gómez', 'carlos.gomez', '$2y$10$w09u4UeH/z6d2a7nCq9NweP7fN5vLzV3qJg4d3C7eW8s1H5y9o0xO', 'mecanico', 1, 1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

INSERT INTO usuarios (nombre, usuario, password, rol, email, telefono, activo)
VALUES ('Cliente Demo', 'cliente.demo', '$2y$10$w09u4UeH/z6d2a7nCq9NweP7fN5vLzV3qJg4d3C7eW8s1H5y9o0xO', 'cliente', 'cliente@demo.com', '3000000001', 1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

-- 3. Tabla de Bicicletas Eléctricas
CREATE TABLE IF NOT EXISTS bicicletas (
    id_bicicleta INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    modelo VARCHAR(120) NOT NULL,
    marca VARCHAR(100) DEFAULT 'eBike',
    cliente VARCHAR(120) DEFAULT NULL,
    telefono_cliente VARCHAR(30) DEFAULT NULL,
    id_usuario INT NULL,
    tipo_bicicleta VARCHAR(80) DEFAULT 'Urbana',
    numero_serie VARCHAR(100) NULL,
    color VARCHAR(50) NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_bicicleta_codigo (codigo),
    INDEX idx_bicicletas_usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bicicletas (codigo, modelo, marca, cliente, telefono_cliente, tipo_bicicleta, color) VALUES
('EBK-1095', 'Trail Pro 500W', 'eBike', 'Cliente Demo', '3000000001', 'Montaña', 'Negro/Verde'),
('EBK-1082', 'City Lite 250W', 'eBike', 'Cliente Demo', '3000000002', 'Urbana', 'Blanco'),
('EBK-1077', 'Mountain X 750W', 'eBike', 'Cliente Demo', '3000000003', 'Montaña', 'Gris'),
('EBK-1063', 'Urban Fold 350W', 'eBike', 'Cliente Demo', '3000000004', 'Plegable', 'Azul')
ON DUPLICATE KEY UPDATE modelo=VALUES(modelo);

-- 4. Tabla de Inventario de Repuestos y Costos
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

INSERT INTO inventario_costos_arreglos
(codigo_rep, nombre_rep, descripcion, categoria, cantidad_disponible, stock_minimo, precio_actual, costo_repuestos, proveedor, costo_mano_obra)
VALUES
('REP-0001', 'Pastillas de freno cerámicas', 'Pastillas de alto rendimiento para frenos de disco hidráulicos eBike', 'Frenos', 25, 5, 45000, 30000, 'Shimano', 20000),
('REP-0002', 'Cadena e-bike 9V reforzada', 'Cadena reforzada para soporte de torque elevado de motores centrales', 'Transmisión', 18, 5, 60000, 42000, 'KMC', 20000),
('REP-0003', 'Batería Li-Ion 48V 15Ah', 'Batería de celdas Samsung de alta capacidad con llave de seguridad', 'Batería', 6, 2, 1450000, 1100000, 'Samsung SDI', 80000),
('REP-0004', 'Controlador Brushless 48V 22A', 'Controlador de onda sinusoidal para motor sin escobillas', 'Controlador', 8, 3, 280000, 195000, 'Bafang', 60000),
('REP-0005', 'Display LCD Color con USB', 'Pantalla multifunción con velocímetro, odómetro y puerto de carga', 'Pantalla', 12, 3, 190000, 130000, 'Bafang', 40000),
('REP-0006', 'Cargador Rápido 48V 3A', 'Cargador inteligente con ventilación activa y corte automático', 'Cargador', 15, 4, 150000, 95000, 'Dpower', 15000),
('REP-0007', 'Llanta Antipinchazo 27.5x2.35', 'Cubierta reforzada especial para eBikes con banda protectora de kevlar', 'Llantas', 20, 5, 120000, 80000, 'Maxxis', 25000),
('REP-0008', 'Sensor de Pedaleo Asistido (PAS)', 'Sensor magnético dual hall para asistencia progresiva al pedaleo', 'Sistema eléctrico', 14, 4, 65000, 40000, 'Bafang', 30000)
ON DUPLICATE KEY UPDATE nombre_rep=VALUES(nombre_rep);

-- 5. Tabla de Reportes de Clientes
CREATE TABLE IF NOT EXISTS reportes_clientes (
    id_reporte INT AUTO_INCREMENT PRIMARY KEY,
    codigo_seguimiento VARCHAR(40) UNIQUE NOT NULL,
    id_bicicleta INT NOT NULL,
    id_cliente INT NULL,
    nombre_cliente VARCHAR(120) NOT NULL,
    telefono_cliente VARCHAR(30) DEFAULT NULL,
    marca VARCHAR(100) NULL,
    modelo VARCHAR(120) NULL,
    codigo_bici VARCHAR(50) NULL,
    tipo_bicicleta VARCHAR(80) NULL,
    categoria_problema VARCHAR(80) NOT NULL DEFAULT 'Otros',
    descripcion TEXT NOT NULL,
    observaciones TEXT NULL,
    foto VARCHAR(255) NULL,
    estado VARCHAR(100) NOT NULL DEFAULT 'Recibido',
    id_tecnico INT NULL,
    id_mantenimiento INT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    atendido_en DATETIME NULL,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_cliente) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_tecnico) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_reportes_estado (estado),
    INDEX idx_reportes_codigo (codigo_seguimiento),
    INDEX idx_reportes_bicicleta (id_bicicleta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabla de Mantenimientos
CREATE TABLE IF NOT EXISTS mantenimientos (
    id_manten INT PRIMARY KEY AUTO_INCREMENT,
    fecha_ingreso DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_tecnico INT NOT NULL,
    diagnostico VARCHAR(255) NOT NULL,
    trabajos_realizados TEXT NULL,
    observaciones TEXT NULL,
    tipo_manten VARCHAR(255) NOT NULL,
    estado VARCHAR(255) NOT NULL DEFAULT 'Recibido',
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
    id_bicicleta INT NULL,
    id_reporte_cliente INT NULL,
    FOREIGN KEY (id_bicicleta) REFERENCES bicicletas(id_bicicleta) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (id_tecnico) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_manten_estado (estado),
    INDEX idx_manten_estado_pago (estado_pago),
    INDEX idx_manten_fecha (fecha_ingreso),
    INDEX idx_manten_tecnico (id_tecnico),
    INDEX idx_manten_reporte (id_reporte_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabla Detalle de Repuestos Utilizados en Mantenimientos
CREATE TABLE IF NOT EXISTS mantenimiento_repuestos (
    id_manten INT NOT NULL,
    id_repuesto INT NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id_manten, id_repuesto),
    FOREIGN KEY (id_manten) REFERENCES mantenimientos(id_manten) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_repuesto) REFERENCES inventario_costos_arreglos(id_repuesto) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabla de Auditoría y Trazabilidad
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_manten INT NULL,
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
    INDEX idx_logs_fecha (fecha_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabla de Gastos Operativos y Compras
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

-- 10. Tabla de Movimientos de Inventario (Kardex)
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

-- 11. Tabla de Notificaciones Internas
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

-- 12. Tabla de Configuración General del Taller
CREATE TABLE IF NOT EXISTS configuracion_taller (
    clave VARCHAR(50) PRIMARY KEY,
    valor TEXT NOT NULL,
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_taller (clave, valor, descripcion) VALUES
('nombre_taller', 'eBike Maintenance System (EMS)', 'Nombre comercial del taller'),
('nit', '901.845.123-4', 'NIT / Identificación fiscal'),
('telefono', '+57 310 987 6543', 'Teléfono principal'),
('email', 'contacto@ebiketaller.com', 'Correo de atención'),
('direccion', 'Cra 45 # 128 - 20, Bogotá, Colombia', 'Dirección física del taller'),
('moneda', 'COP', 'Moneda oficial del sistema'),
('simbolo_moneda', '$', 'Símbolo monetario'),
('garantia_dias', '90', 'Días de garantía estándar en mano de obra')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);
