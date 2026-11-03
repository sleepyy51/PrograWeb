-- Base de datos para el frontend "Comisión Discriminadora"
-- Motor: MySQL 8.0+
-- Importar este archivo en MySQL Workbench o con:
-- mysql -u root -p < db.sql

CREATE DATABASE IF NOT EXISTS comision_discriminadora
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
USE comision_discriminadora;

CREATE TABLE IF NOT EXISTS usuarios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_completo VARCHAR(160) NOT NULL,
  nombre_usuario VARCHAR(80) NOT NULL,
  correo VARCHAR(190) NULL,
  contrasena_hash VARCHAR(255) NOT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_nombre_usuario (nombre_usuario),
  UNIQUE KEY uq_usuarios_correo (correo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS convocatorias (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(180) NOT NULL,
  tipo ENUM('Académica', 'Investigación', 'Servicio institucional') NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  clave_registro VARCHAR(40) NOT NULL,
  responsable_id BIGINT UNSIGNED NULL,
  creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_convocatorias_fechas (fecha_inicio, fecha_fin),
  CONSTRAINT fk_convocatorias_responsable
    FOREIGN KEY (responsable_id) REFERENCES usuarios(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_convocatorias_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS proyectos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  convocatoria_id BIGINT UNSIGNED NOT NULL,
  nombre_persona VARCHAR(160) NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  fecha_ingreso DATE NOT NULL,
  nombre_proyecto VARCHAR(220) NOT NULL,
  fecha_terminado DATE NULL,
  pago_estado ENUM('pendiente', 'pagado') NOT NULL DEFAULT 'pendiente',
  paypal_order_id VARCHAR(100) NULL,
  pagado_en TIMESTAMP NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proyectos_codigo (codigo),
  UNIQUE KEY uq_proyectos_paypal_order (paypal_order_id),
  KEY ix_proyectos_convocatoria (convocatoria_id),
  CONSTRAINT fk_proyectos_convocatoria
    FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Usuario demostrativo: cambiar el hash por uno generado por el backend.
-- No existe una contraseña de acceso utilizable en este SQL.
INSERT INTO usuarios (nombre_completo, nombre_usuario, correo, contrasena_hash)
VALUES ('Juan Carlos Herrera', 'jcherrera', 'juan.herrera@example.edu', '$2y$10$REEMPLAZAR_POR_HASH_BCRYPT_GENERADO_EN_EL_BACKEND')
ON DUPLICATE KEY UPDATE nombre_completo = VALUES(nombre_completo);

INSERT INTO convocatorias (nombre, tipo, fecha_inicio, fecha_fin, clave_registro, responsable_id)
SELECT 'Convocatoria de Innovación 2026', 'Investigación', '2026-05-12', '2026-11-30', 'INV-2026', u.id
FROM usuarios u WHERE u.nombre_usuario = 'jcherrera'
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO convocatorias (nombre, tipo, fecha_inicio, fecha_fin, clave_registro)
SELECT datos.nombre, datos.tipo, datos.fecha_inicio, datos.fecha_fin, datos.clave
FROM (
  SELECT 'Programa de Becas 2025' nombre, 'Académica' tipo, DATE('2025-01-23') fecha_inicio, DATE('2025-12-31') fecha_fin, 'BEC-2025' clave
  UNION ALL SELECT 'Proyectos de Investigación Aplicada', 'Investigación', '2024-11-18', '2025-11-18', 'INV-2024'
  UNION ALL SELECT 'Servicio Social Comunitario', 'Servicio institucional', '2024-09-06', '2025-09-06', 'SSC-2024'
  UNION ALL SELECT 'Actualización Docente 2024', 'Académica', '2024-06-15', '2024-12-15', 'DOC-2024'
  UNION ALL SELECT 'Movilidad Académica Primavera', 'Académica', '2024-02-28', '2024-08-31', 'MOV-2024'
  UNION ALL SELECT 'Fondo de Emprendimiento Institucional', 'Investigación', '2023-10-10', '2024-10-10', 'EMP-2023'
) AS datos
WHERE NOT EXISTS (SELECT 1 FROM convocatorias c WHERE c.nombre = datos.nombre);

INSERT INTO proyectos (convocatoria_id, nombre_persona, codigo, fecha_ingreso, nombre_proyecto, fecha_terminado)
SELECT c.id, p.nombre_persona, p.codigo, p.fecha_ingreso, p.nombre_proyecto, p.fecha_terminado
FROM convocatorias c
JOIN (
  SELECT 'Ana Sofía Martínez' nombre_persona, 'INV-2601' codigo, DATE('2026-05-14') fecha_ingreso, 'Red de sensores ambientales' nombre_proyecto, DATE('2026-09-30') fecha_terminado
  UNION ALL SELECT 'Diego Hernández Cruz', 'INV-2602', '2026-05-16', 'Laboratorio de datos abiertos', '2026-09-30'
  UNION ALL SELECT 'Fernanda López Ruiz', 'INV-2603', '2026-05-18', 'Prototipo de riego inteligente', '2026-10-15'
  UNION ALL SELECT 'Jorge Luis Ramírez', 'INV-2604', '2026-05-20', 'Optimización energética del campus', '2026-10-15'
  UNION ALL SELECT 'Mariana Torres Silva', 'INV-2605', '2026-05-22', 'Plataforma de inclusión digital', '2026-10-30'
  UNION ALL SELECT 'Ricardo Navarro Peña', 'INV-2606', '2026-05-25', 'Materiales biodegradables', '2026-10-30'
  UNION ALL SELECT 'Lucía Valdez Moreno', 'INV-2607', '2026-05-28', 'Monitoreo de calidad del agua', '2026-11-15'
  UNION ALL SELECT 'Emilio García Soto', 'INV-2608', '2026-05-30', 'Asistente de orientación académica', '2026-11-15'
  UNION ALL SELECT 'Camila Rojas Méndez', 'INV-2609', '2026-06-02', 'Archivo histórico institucional', '2026-11-30'
  UNION ALL SELECT 'Sergio Álvarez Ortiz', 'INV-2610', '2026-06-04', 'Movilidad sustentable universitaria', '2026-11-30'
) p ON 1=1
WHERE c.nombre = 'Convocatoria de Innovación 2026'
ON DUPLICATE KEY UPDATE nombre_proyecto = VALUES(nombre_proyecto);

-- Consultas de referencia
-- SELECT * FROM convocatorias ORDER BY creada_en DESC;
-- SELECT p.* FROM proyectos p JOIN convocatorias c ON c.id = p.convocatoria_id WHERE c.nombre = 'Convocatoria de Innovación 2026';
