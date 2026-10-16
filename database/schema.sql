-- ============================================================
-- SmartCell Commerce Platform — Subsistema de SEGURIDAD
-- Esquema SQLite (Sprint 1: Gestión de Usuarios)
-- Se ejecuta con database/seeder.php (crea el archivo smartcell.sqlite)
-- ============================================================

PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS logs_acceso;
DROP TABLE IF EXISTS permisos_rol;
DROP TABLE IF EXISTS modulos;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS roles;

-- Roles del sistema. "area" sirve para que los Jefes vean solo usuarios de su área.
CREATE TABLE roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL UNIQUE,
    descripcion TEXT,
    area        TEXT NOT NULL      -- seguridad | inventario | distribucion | ventas | cobranzas | clientes
);

CREATE TABLE usuarios (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre         TEXT NOT NULL,
    email          TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password       TEXT NOT NULL,                       -- hash bcrypt (nunca texto plano)
    rol_id         INTEGER NOT NULL REFERENCES roles(id),
    activo         INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    creado_en      TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    actualizado_en TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- Auditoría de intentos de login
CREATE TABLE logs_acceso (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id    INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    email_intento TEXT NOT NULL,
    resultado     TEXT NOT NULL CHECK (resultado IN ('exito', 'fallido', 'bloqueado')),
    ip            TEXT,
    fecha         TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- Módulos del sistema y matriz de permisos (RBAC, RF-06)
CREATE TABLE modulos (
    clave       TEXT PRIMARY KEY,
    nombre      TEXT NOT NULL,
    descripcion TEXT,
    grupo       TEXT NOT NULL,           -- General | Seguridad | Otros módulos
    orden       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE permisos_rol (
    rol_id       INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    modulo_clave TEXT    NOT NULL REFERENCES modulos(clave) ON DELETE CASCADE,
    nivel        TEXT    NOT NULL CHECK (nivel IN ('total', 'lectura')),
    PRIMARY KEY (rol_id, modulo_clave)
);

CREATE INDEX idx_usuarios_rol   ON usuarios(rol_id);
CREATE INDEX idx_logs_fecha     ON logs_acceso(fecha);
CREATE INDEX idx_logs_email     ON logs_acceso(email_intento, fecha);
