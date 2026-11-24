-- ====================================================================
-- eBike · Plataforma de Alquiler con Mantenimiento Interno
-- MIGRACIÓN ADITIVA (NO DESTRUCTIVA) — ebike_taller
-- Ejecutar sobre una base de datos existente: mysql -u root -p < migracion_alquiler.sql
-- No elimina tablas, columnas ni registros. Solo agrega estructuras y
-- reutiliza los datos existentes. Es idempotente: puede ejecutarse varias veces.
-- ====================================================================

USE ebike_taller;

-- Helper: añadir columna solo si no existe
DROP PROCEDURE IF EXISTS ebk_add_column;
DELIMITER $$
CREATE PROCEDURE ebk_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- Helper: añadir índice solo si no existe
DROP PROCEDURE IF EXISTS ebk_add_index;
DELIMITER $$
CREATE PROCEDURE ebk_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_definition, ')');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- Helper: añadir llave foránea solo si no existe
DROP PROCEDURE IF EXISTS ebk_add_fk;
DELIMITER $$
CREATE PROCEDURE ebk_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_constraint
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint, '` FOREIGN KEY ', p_definition);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- --------------------------------------------------------------------
-- 1. NUEVAS TABLAS DEL NEGOCIO DE ALQUILER
-- --------------------------------------------------------------------

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

CREATE TABLE IF NOT EXISTS reporte_fotos (
    id_foto INT AUTO_INCREMENT PRIMARY KEY,
    id_reporte INT NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_reporte) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_fotos_reporte (id_reporte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participantes_reporte (
    id_reporte INT NOT NULL,
    id_empleado INT NOT NULL,
    rol_participacion VARCHAR(60) NOT NULL DEFAULT 'Mecánico participante',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_reporte, id_empleado),
    FOREIGN KEY (id_reporte) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Vista unificada de configuración (sin duplicar datos)
CREATE OR REPLACE VIEW configuracion_empresa AS
SELECT clave, valor, descripcion FROM configuracion_taller;

-- --------------------------------------------------------------------
-- 2. COLUMNAS NUEVAS SOBRE TABLAS EXISTENTES
-- --------------------------------------------------------------------

CALL ebk_add_column('bicicletas', 'nombre', 'VARCHAR(120) NULL AFTER modelo');
CALL ebk_add_column('bicicletas', 'foto', 'VARCHAR(255) NULL AFTER nombre');
CALL ebk_add_column('bicicletas', 'descripcion', 'TEXT NULL AFTER foto');
CALL ebk_add_column('bicicletas', 'caracteristicas', 'TEXT NULL AFTER descripcion');
CALL ebk_add_column('bicicletas', 'autonomia_km', 'INT NOT NULL DEFAULT 0 AFTER caracteristicas');
CALL ebk_add_column('bicicletas', 'capacidad_bateria_wh', 'INT NOT NULL DEFAULT 0 AFTER autonomia_km');
CALL ebk_add_column('bicicletas', 'nivel_bateria', 'TINYINT NOT NULL DEFAULT 100 AFTER capacidad_bateria_wh');
CALL ebk_add_column('bicicletas', 'precio_hora', 'DECIMAL(10,2) NOT NULL DEFAULT 15000 AFTER nivel_bateria');
CALL ebk_add_column('bicicletas', 'precio_dia', 'DECIMAL(10,2) NOT NULL DEFAULT 90000 AFTER precio_hora');
CALL ebk_add_column('bicicletas', 'estado_operativo', 'ENUM(''disponible'',''reservada'',''alquilada'',''en_mantenimiento'',''en_revision'',''fuera_de_servicio'') NOT NULL DEFAULT ''disponible'' AFTER precio_dia');
CALL ebk_add_column('bicicletas', 'fecha_incorporacion', 'DATETIME NULL AFTER estado_operativo');
CALL ebk_add_index('bicicletas', 'idx_bici_estado', 'estado_operativo');

CALL ebk_add_column('reportes_clientes', 'id_alquiler', 'INT NULL AFTER id_bicicleta');
CALL ebk_add_column('reportes_clientes', 'id_ticket', 'INT NULL AFTER id_alquiler');
CALL ebk_add_column('reportes_clientes', 'prioridad', 'ENUM(''baja'',''media'',''alta'',''urgente'') NOT NULL DEFAULT ''media'' AFTER categoria_problema');
CALL ebk_add_column('reportes_clientes', 'cerrado_en', 'DATETIME NULL AFTER atendido_en');
CALL ebk_add_column('reportes_clientes', 'validado_por', 'INT NULL AFTER cerrado_en');
CALL ebk_add_fk('reportes_clientes', 'fk_reporte_alquiler', '(id_alquiler) REFERENCES alquileres(id_alquiler) ON UPDATE CASCADE ON DELETE SET NULL');
CALL ebk_add_fk('reportes_clientes', 'fk_reporte_ticket', '(id_ticket) REFERENCES tickets_alquiler(id_ticket) ON UPDATE CASCADE ON DELETE SET NULL');
CALL ebk_add_fk('reportes_clientes', 'fk_reporte_validador', '(validado_por) REFERENCES usuarios(id) ON UPDATE CASCADE ON DELETE SET NULL');
CALL ebk_add_index('reportes_clientes', 'idx_reportes_alquiler', 'id_alquiler');

CALL ebk_add_column('logs_auditoria', 'entidad_tipo', 'VARCHAR(40) NULL AFTER id_manten');
CALL ebk_add_column('logs_auditoria', 'entidad_id', 'INT NULL AFTER entidad_tipo');
CALL ebk_add_index('logs_auditoria', 'idx_logs_entidad', 'entidad_tipo, entidad_id');

CALL ebk_add_fk('mantenimientos', 'fk_manten_reporte', '(id_reporte_cliente) REFERENCES reportes_clientes(id_reporte) ON UPDATE CASCADE ON DELETE SET NULL');
CALL ebk_add_column('mantenimientos', 'cerrado_en', 'DATETIME NULL AFTER fecha_salida');

-- --------------------------------------------------------------------
-- 3. REUTILIZACIÓN DE DATOS EXISTENTES (sin borrar nada)
-- --------------------------------------------------------------------

-- La flota heredada pasa a ser flota de alquiler con criterios definidos por tipo.
UPDATE bicicletas
SET nombre = COALESCE(NULLIF(nombre, ''), modelo),
    fecha_incorporacion = COALESCE(fecha_incorporacion, creado_en),
    autonomia_km = CASE
        WHEN LOWER(tipo_bicicleta) LIKE '%monta%' THEN 65
        WHEN LOWER(tipo_bicicleta) LIKE '%pleg%' THEN 35
        WHEN LOWER(tipo_bicicleta) LIKE '%carga%' THEN 45
        ELSE 50 END,
    capacidad_bateria_wh = CASE
        WHEN LOWER(tipo_bicicleta) LIKE '%monta%' THEN 720
        WHEN LOWER(tipo_bicicleta) LIKE '%pleg%' THEN 360
        ELSE 504 END,
    precio_hora = CASE
        WHEN LOWER(tipo_bicicleta) LIKE '%monta%' THEN 22000
        WHEN LOWER(tipo_bicicleta) LIKE '%pleg%' THEN 14000
        ELSE 18000 END,
    precio_dia = CASE
        WHEN LOWER(tipo_bicicleta) LIKE '%monta%' THEN 120000
        WHEN LOWER(tipo_bicicleta) LIKE '%pleg%' THEN 80000
        ELSE 95000 END,
    estado_operativo = CASE
        WHEN activa = 0 THEN 'fuera_de_servicio'
        ELSE 'disponible' END
WHERE (nombre IS NULL OR nombre = '' OR autonomia_km = 0 OR fecha_incorporacion IS NULL)
  AND estado_operativo = 'disponible';

-- Los reportes heredados conservan su información y se normalizan al nuevo vocabulario.
UPDATE reportes_clientes SET estado = 'En espera' WHERE estado IN ('Recibido', 'Pendiente de revisión');
UPDATE reportes_clientes SET estado = 'En investigación' WHERE estado IN ('En diagnóstico', 'Esperando repuestos');
UPDATE reportes_clientes SET estado = 'En mantenimiento' WHERE estado = 'En reparación';
UPDATE reportes_clientes SET estado = 'Terminado' WHERE estado = 'Reparado';
UPDATE reportes_clientes SET estado = 'Cerrado' WHERE estado = 'Entregado';

UPDATE mantenimientos SET estado = 'En espera' WHERE estado IN ('Recibido', 'Pendiente de revisión');
UPDATE mantenimientos SET estado = 'En investigación' WHERE estado IN ('En diagnóstico', 'Esperando repuestos');
UPDATE mantenimientos SET estado = 'En mantenimiento' WHERE estado = 'En reparación';
UPDATE mantenimientos SET estado = 'Terminado' WHERE estado IN ('Reparado', 'Entregado');

-- Las fotos históricas se conservan también en la nueva tabla multi-foto.
INSERT INTO reporte_fotos (id_reporte, ruta, creado_en)
SELECT r.id_reporte, r.foto, r.creado_en
FROM reportes_clientes r
WHERE r.foto IS NOT NULL AND r.foto <> ''
  AND NOT EXISTS (SELECT 1 FROM reporte_fotos f WHERE f.id_reporte = r.id_reporte AND f.ruta = r.foto);

-- Identidad de empresa (se conservan las claves anteriores).
INSERT INTO configuracion_taller (clave, valor, descripcion) VALUES
('nombre_empresa', 'eBike · Movilidad Eléctrica', 'Razón comercial de la empresa de alquiler'),
('horario_atencion', 'Lun a Dom · 8:00 a 20:00', 'Horario de atención y entrega de bicicletas'),
('politica_alquiler', 'Cancelación gratuita hasta 2 horas antes del inicio.', 'Política comercial del alquiler'),
('deposito_garantia', '0', 'Depósito de garantía (0 = no se cobra)')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

UPDATE configuracion_taller
SET valor = 'eBike · Movilidad Eléctrica', descripcion = 'Nombre comercial de la empresa'
WHERE clave = 'nombre_taller' AND valor LIKE '%Maintenance%';

DROP PROCEDURE IF EXISTS ebk_add_column;
DROP PROCEDURE IF EXISTS ebk_add_index;
DROP PROCEDURE IF EXISTS ebk_add_fk;

-- Verificación rápida
SELECT 'Migración eBike completada' AS resultado,
       (SELECT COUNT(*) FROM bicicletas) AS bicicletas,
       (SELECT COUNT(*) FROM alquileres) AS alquileres,
       (SELECT COUNT(*) FROM reportes_clientes) AS reportes;
