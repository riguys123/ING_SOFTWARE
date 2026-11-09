-- ============================================================
-- SmartCell Commerce Platform — Subsistema de SEGURIDAD
-- Esquema SQLite (Sprint 1: Usuarios/RBAC · Sprint 2: E-commerce, Pagos, Logística · Sprint 3: Categorías, Promociones, Mensajes, Informes)
-- Se ejecuta con database/seeder.php (crea el archivo smartcell.sqlite)
-- ============================================================

PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS notificaciones;
DROP TABLE IF EXISTS promociones;
DROP TABLE IF EXISTS despachos;
DROP TABLE IF EXISTS pagos;
DROP TABLE IF EXISTS detalle_pedido;
DROP TABLE IF EXISTS pedidos;
DROP TABLE IF EXISTS carrito_items;
DROP TABLE IF EXISTS imeis;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS categorias;
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


-- ============================================================
-- SPRINT 2 — E-COMMERCE · PAGOS · LOGÍSTICA E INVENTARIO
-- ============================================================

-- ---------- Categorías del catálogo (Sprint 3) ----------
-- usa_imei = 1: cada unidad es un equipo con IMEI único (teléfonos).
-- usa_imei = 0: se controla solo por cantidad (accesorios, complementos).
CREATE TABLE categorias (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    descripcion TEXT,
    icono       TEXT NOT NULL DEFAULT 'etiqueta',
    usa_imei    INTEGER NOT NULL DEFAULT 0 CHECK (usa_imei IN (0, 1)),
    orden       INTEGER NOT NULL DEFAULT 0,
    activo      INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1))
);

-- ---------- Catálogo ----------
CREATE TABLE productos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    categoria_id  INTEGER REFERENCES categorias(id),
    requiere_imei INTEGER NOT NULL DEFAULT 1 CHECK (requiere_imei IN (0, 1)),   -- copia de categorias.usa_imei
    marca         TEXT NOT NULL,
    modelo        TEXT NOT NULL,
    procesador    TEXT,
    ram_gb        INTEGER,
    almacenamiento_gb INTEGER,
    camara        TEXT,
    pantalla      TEXT,
    bateria_mah   INTEGER,
    color         TEXT,
    descripcion   TEXT,
    precio        REAL NOT NULL CHECK (precio > 0),         -- en Bolivianos (Bs)
    stock_actual  INTEGER NOT NULL DEFAULT 0 CHECK (stock_actual >= 0),  -- = IMEIs 'Disponible'
    stock_minimo  INTEGER NOT NULL DEFAULT 3,                -- umbral de alerta de stock bajo
    fotos         TEXT NOT NULL DEFAULT '[]',                -- arreglo JSON de URLs
    activo        INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),   -- baja lógica
    creado_en     TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    actualizado_en TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- ---------- Inventario por IMEI (un registro = un equipo físico) ----------
CREATE TABLE imeis (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    producto_id  INTEGER NOT NULL REFERENCES productos(id),
    numero_imei  TEXT NOT NULL UNIQUE CHECK (length(numero_imei) = 15),
    estado       TEXT NOT NULL DEFAULT 'Disponible' CHECK (estado IN ('Disponible', 'Vendido', 'Entregado')),
    pedido_id    INTEGER REFERENCES pedidos(id),             -- pedido al que quedó asignado
    ingresado_en TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    vendido_en   TEXT,
    entregado_en TEXT
);

-- ---------- Carrito (persistente en el servidor, uno por cliente) ----------
CREATE TABLE carrito_items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    producto_id INTEGER NOT NULL REFERENCES productos(id),
    cantidad    INTEGER NOT NULL CHECK (cantidad BETWEEN 1 AND 10),
    agregado_en TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    UNIQUE (usuario_id, producto_id)
);

-- ---------- Pedidos ----------
CREATE TABLE pedidos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id     INTEGER NOT NULL REFERENCES usuarios(id),
    total          REAL NOT NULL CHECK (total >= 0),
    estado_pago    TEXT NOT NULL DEFAULT 'Pendiente' CHECK (estado_pago IN ('Pendiente', 'Pagado', 'Rechazado')),
    estado_envio   TEXT NOT NULL DEFAULT 'Pendiente'
                   CHECK (estado_envio IN ('Pendiente', 'En_preparacion', 'En_camino', 'Entregado', 'Cancelado')),
    direccion_envio TEXT NOT NULL,
    ciudad         TEXT NOT NULL,
    telefono       TEXT NOT NULL,
    notas          TEXT,
    fecha          TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    actualizado_en TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- Una fila por producto. Al despachar, cada línea se desdobla en una fila por
-- unidad (cantidad = 1) para guardar el IMEI único de cada equipo en imei_asignado.
CREATE TABLE detalle_pedido (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id       INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    producto_id     INTEGER NOT NULL REFERENCES productos(id),
    imei_asignado   TEXT REFERENCES imeis(numero_imei),      -- NULL hasta que se despacha
    cantidad        INTEGER NOT NULL CHECK (cantidad >= 1),
    precio_unitario REAL NOT NULL CHECK (precio_unitario >= 0),   -- lo que se pagó (ya con descuento)
    precio_lista    REAL,                                          -- precio sin descuento (Sprint 3)
    descuento_pct   INTEGER NOT NULL DEFAULT 0                     -- promoción aplicada al comprar
);

-- ---------- Pagos (pasarela simulada; NUNCA se guarda el número completo ni el CVV) ----------
CREATE TABLE pagos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id       INTEGER NOT NULL REFERENCES pedidos(id),
    metodo          TEXT NOT NULL CHECK (metodo IN ('Tarjeta', 'QR', 'Transferencia')),
    monto           REAL NOT NULL,
    estado          TEXT NOT NULL CHECK (estado IN ('Aprobado', 'Rechazado')),
    referencia      TEXT NOT NULL,                           -- id de transacción de la pasarela
    tarjeta_ultimos4 TEXT,
    motivo_rechazo  TEXT,
    fecha           TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- ---------- Orden de despacho (una por pedido pagado) ----------
CREATE TABLE despachos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id     INTEGER NOT NULL UNIQUE REFERENCES pedidos(id),
    codigo_guia   TEXT NOT NULL UNIQUE,
    estado        TEXT NOT NULL DEFAULT 'En_preparacion' CHECK (estado IN ('En_preparacion', 'En_camino', 'Entregado')),
    repartidor_id INTEGER REFERENCES usuarios(id),
    creado_en     TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    enviado_en    TEXT,
    entregado_en  TEXT
);

CREATE INDEX idx_productos_marca  ON productos(marca);
CREATE INDEX idx_imeis_producto   ON imeis(producto_id, estado);
CREATE INDEX idx_imeis_pedido     ON imeis(pedido_id);
CREATE INDEX idx_pedidos_usuario  ON pedidos(usuario_id, fecha);
CREATE INDEX idx_detalle_pedido   ON detalle_pedido(pedido_id);
CREATE INDEX idx_pagos_pedido     ON pagos(pedido_id);


-- ============================================================
-- SPRINT 3 — PROMOCIONES · MENSAJES AUTOMÁTICOS · INFORMES
-- ============================================================

-- ---------- Promociones y banner de ofertas ----------
-- Si descuento_pct > 0 y hay producto_id (o categoria_id) la rebaja se aplica al precio mientras
-- esté vigente. Con descuento 0 es solo un banner informativo.
CREATE TABLE promociones (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo        TEXT NOT NULL,
    descripcion   TEXT,
    imagen        TEXT,                                           -- ruta relativa a frontend/ (uploads/...)
    descuento_pct INTEGER NOT NULL DEFAULT 0 CHECK (descuento_pct BETWEEN 0 AND 90),
    producto_id   INTEGER REFERENCES productos(id),
    categoria_id  INTEGER REFERENCES categorias(id),
    fecha_inicio  TEXT NOT NULL,                                  -- YYYY-MM-DD
    fecha_fin     TEXT NOT NULL,
    activo        INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    creado_en     TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

-- ---------- Mensajes automáticos (confirmación, comprobante, envío, entrega) ----------
-- El sistema genera el texto solo; se envía por WhatsApp con un clic (enlace wa.me), sin API externa.
CREATE TABLE notificaciones (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id   INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    usuario_id  INTEGER NOT NULL REFERENCES usuarios(id),
    tipo        TEXT NOT NULL CHECK (tipo IN ('confirmacion', 'comprobante', 'envio', 'entrega')),
    telefono    TEXT NOT NULL,
    mensaje     TEXT NOT NULL,
    estado      TEXT NOT NULL DEFAULT 'Pendiente' CHECK (estado IN ('Pendiente', 'Enviado')),
    creado_en   TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    enviado_en  TEXT,
    enviado_por INTEGER REFERENCES usuarios(id)
);

CREATE INDEX idx_productos_cat   ON productos(categoria_id);
CREATE INDEX idx_promos_vigencia ON promociones(activo, fecha_inicio, fecha_fin);
CREATE INDEX idx_notif_estado    ON notificaciones(estado, creado_en);
CREATE INDEX idx_notif_pedido    ON notificaciones(pedido_id);
