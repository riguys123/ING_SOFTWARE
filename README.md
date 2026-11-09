# SmartCell Commerce Platform — Seguridad · E-commerce · Pagos · Logística/Inventario

Plataforma de comercialización de celulares con los **4 módulos**:

| Módulo | Qué incluye |
|---|---|
| **Seguridad** (Sprint 1) | Login, registro de clientes, **JWT** + sesión, CRUD de usuarios, roles y permisos (RBAC), auditoría, bloqueo por intentos |
| **E-commerce** (Sprint 2) | Catálogo con filtros, detalle, carrito persistente, checkout, mis pedidos |
| **Pagos** (Sprint 2) | Pasarela **simulada** (tarjeta, QR, transferencia), historial de transacciones |
| **Logística e Inventario** (Sprint 2) | Stock por **IMEI**, descuento automático de stock, asignación de IMEI único, orden de despacho y seguimiento de entrega |

| Capa | Tecnología |
|---|---|
| Backend | **PHP 8** puro, API REST en JSON · autenticación por **JWT (HS256)** o sesión PHP |
| Base de datos | **SQLite** (`database/smartcell.sqlite`), relacional con llaves foráneas |
| Frontend | **JavaScript con Vue 3 + Vue Router** (sin compilar, no necesita Node) |
| Servidor | XAMPP (Apache + PHP) o `php -S` |

> **¿Por qué PHP y no Node/Express?** El proyecto ya venía en PHP + SQLite + Vue (requisito del ingeniero: backend en PHP, SQLite sin MySQL, framework JS en el frontend), así que el Sprint 2 se construyó **sobre esa misma base**. La arquitectura es la que se pidió (API REST + JWT + RBAC + SQL relacional); solo cambia el lenguaje del servidor. Si algún día se migra, la API REST y el esquema SQL se conservan tal cual.

---

## 1. Estructura de carpetas

```
smartcell/
├── .htaccess                  Reescribe /api/... hacia el backend
├── index.php                  Redirige a frontend/
├── dev-router.php             (opcional) servidor PHP integrado, sin Apache
├── README.md
│
├── database/
│   ├── schema.sql             Seguridad (roles, usuarios, logs_acceso, modulos, permisos_rol) +
│   │                          Tienda (productos, imeis, carrito_items, pedidos, detalle_pedido, pagos, despachos)
│   ├── seeder.php             Crea smartcell.sqlite + usuarios, 11 celulares con IMEI y 5 pedidos de ejemplo
│   ├── smartcell.sqlite       Base ya generada y lista para usar
│   └── .htaccess              Bloquea la descarga de la base desde el navegador
│
├── backend/
│   ├── .htaccess              Bloquea el acceso directo al código fuente
│   ├── public/index.php       Punto de entrada de la API + tabla de rutas y roles
│   └── src/
│       ├── config.php         Constantes (roles, intentos, tiempos)
│       ├── Database.php       Conexión PDO a SQLite
│       ├── Http.php           Response (JSON) y Request
│       ├── Auth.php           Sesión + JWT, requerirLogin(), requerirRol(), requerirModulo(), registrarIntento()
│       ├── Jwt.php            Emisión y verificación de tokens HS256 (sin librerías)
│       ├── Router.php         Enrutador con RBAC declarativo (por rol o por módulo)
│       ├── Services/          Lógica de negocio, sin HTTP:
│       │   ├── PedidoService  Checkout: carrito -> pedido (precios y totales desde la BD)
│       │   ├── PagoService    Valida, cobra en la pasarela, registra el pago y dispara la logística
│       │   ├── Logistica      Descuenta stock + asigna IMEI + crea el despacho (transacción)
│       │   ├── PasarelaSimulada, Imei (Luhn), Catalogo
│       └── Controllers/       Auth, Usuario, Rol, Log, Producto, Inventario, Carrito, Pedido, Pago, Logistica, Reporte
│
└── frontend/
    ├── index.html
    └── assets/
        ├── css/style.css      Tema grafito + verde señal #35c48c (responsive)
        ├── vendor/            (opcional) Vue y Vue Router para uso sin internet
        └── js/
            ├── config.js      URL de la API
            ├── api.js         Cliente fetch
            ├── store.js       Estado global (sesión, módulos, notificaciones)
            ├── router.js      Rutas + guards por rol
            ├── app.js         Layout y menú lateral según rol
            ├── components/    Modal, paginador, toasts, íconos
            └── views/         Seguridad (10 pantallas) + tienda.js, carrito.js (carrito y checkout),
                               pedidos.js, registro.js, dashboard.js (panel), admin-productos.js,
                               inventario.js, logistica.js (pagos y despachos)
```

---

## 2. Instalación en XAMPP (Windows)

1. **Copia la carpeta** `smartcell` completa a `C:\xampp\htdocs\` (queda `C:\xampp\htdocs\smartcell`).
2. Abre el **Panel de XAMPP** y arranca **Apache**. (MySQL ya **no** es necesario.)
3. Verifica que PHP tenga SQLite activo: en `C:\xampp\php\php.ini` deben estar **sin `;`** las líneas
   `extension=pdo_sqlite` y `extension=sqlite3`. En XAMPP recientes ya vienen activas; si las cambias, reinicia Apache.
4. Abre en el navegador: **http://localhost/smartcell/**
5. Inicia sesión con `admin@smartcell.com` / `Admin123!`.

**Internet:** Vue y Vue Router se cargan desde un CDN la primera vez. Para trabajar **sin internet**, descarga estos dos archivos y guárdalos en `frontend/assets/vendor/`:
- https://cdn.jsdelivr.net/npm/vue@3.5.13/dist/vue.global.prod.js
- https://cdn.jsdelivr.net/npm/vue-router@4.5.0/dist/vue-router.global.prod.js

(El `index.html` usa primero los archivos locales y solo si no existen va al CDN.)

### Si algo no funciona
| Síntoma | Causa y solución |
|---|---|
| Pantalla "No se pudo iniciar la aplicación" | No hay internet y faltan los archivos de `vendor/` (ver arriba). |
| Login responde "ruta no existe" / 404 en `/api/...` | `mod_rewrite` o `.htaccess` desactivados. Solución rápida: en `frontend/assets/js/config.js` pon `API_BASE: '../backend/public/index.php/api'`. |
| Error 500 / "could not find driver" | Falta activar `pdo_sqlite` en `php.ini` (paso 3). |
| "No existe la base de datos" | Regenérala (sección 3). |

### Alternativa sin Apache (VS Code)
Desde la carpeta del proyecto: `php -S localhost:8000 dev-router.php` y abre http://localhost:8000/frontend/

---

## 3. Base de datos

La base `database/smartcell.sqlite` ya viene creada. Para **recrearla desde cero** (borra todo):

```
C:\xampp\php\php.exe database\seeder.php
```

Tablas de **seguridad**: `roles`, `usuarios`, `logs_acceso`, `modulos`, `permisos_rol`.
Tablas de **tienda y logística**: `productos`, `imeis`, `carrito_items`, `pedidos`, `detalle_pedido`, `pagos`, `despachos`.

```
usuarios 1──* pedidos 1──* detalle_pedido *──1 productos 1──* imeis
   │              │  └──* pagos                    ▲
   │              └──1 despachos                    └── detalle_pedido.imei_asignado = imeis.numero_imei
   └──* carrito_items *──1 productos
```

Equivalencia con los nombres del enunciado: rol `Administrador` = **Admin**, `Auxiliar_Inventario` = **Inventariado**, `Cliente` = **Cliente**.
El estado del IMEI es `Disponible → Vendido → Entregado`. `productos.stock_actual` siempre es igual a la cantidad de IMEI `Disponible` (se mueve solo al ingresar IMEI y al despachar).

### Usuarios de prueba (uno por rol, desde el Sprint 3)

| Rol | Correo | Contraseña |
|---|---|---|
| Admin (gerente/propietario) | admin@smartcell.com | `Admin123!` |
| Vendedor (encargado) | vendedor@smartcell.com | `Smart1234!` |
| Repartidor (encargado del envío) | repartidor@smartcell.com | `Smart1234!` |
| Cliente | cliente@smartcell.com | `Smart1234!` |
| Cliente (2.º) | lucia@smartcell.com | `Smart1234!` |

> Los roles intermedios del Sprint 2 (jefes, inventariado, cobranzas) se fusionaron en estos 4. Mira la sección 9.

---|---|---|
| Admin | admin@smartcell.com | `Admin123!` |
| Jefe de Distribución | jefe.distribucion@smartcell.com | `Smart1234!` |
| Jefe de Almacén | jefe.almacen@smartcell.com | `Smart1234!` |
| Vendedor | vendedor@smartcell.com | `Smart1234!` |
| Inventariado | inventariado@smartcell.com | `Smart1234!` |
| Repartidor | repartidor@smartcell.com | `Smart1234!` |
| Cobranzas | cobranzas@smartcell.com | `Smart1234!` |
| Cliente | cliente@smartcell.com | `Smart1234!` |
| Cliente (2.º) | lucia@smartcell.com | `Smart1234!` |

---

## 4. Matriz de permisos inicial

| Módulo | Admin | Jefe Almacén | Jefe Distrib. | Vendedor | Inventariado | Repartidor | Cobranzas | Cliente |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Panel y Mi perfil | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Gestión de usuarios | ✔ total | 👁 su área | 👁 su área | — | — | — | — | — |
| Roles y permisos · Auditoría | ✔ | — | — | — | — | — | — | — |
| **Tienda** (carrito, comprar, mis pedidos) | — | — | — | — | — | — | — | ✔ |
| **Productos** (catálogo) | ✔ | ✔ | — | 👁 | 👁 | — | — | — |
| **Inventario por IMEI** | ✔ | ✔ | 👁 | 👁 | ✔ | — | — | — |
| **Pedidos** | ✔ | 👁 | 👁 | ✔ | — | — | 👁 | — |
| **Pagos** | ✔ | — | — | — | — | — | ✔ | — |
| **Despachos** | ✔ | — | ✔ | — | — | ✔ | — | — |

✔ = acceso total · 👁 = solo lectura. Áreas: Jefe de Almacén ve `Jefe de Almacén` + `Inventariado`; Jefe de Distribución ve `Jefe de Distribución` + `Repartidor`.
La matriz vive en la base de datos (`permisos_rol`): el backend (`'modulo:inventario:total'` en las rutas), el menú y la pantalla de roles usan **la misma fuente**.

---

## 5. API REST

Base: `http://localhost/smartcell/api`. Todas las respuestas son JSON:
éxito `{ "ok": true, "data": ..., "meta": ... }` · error `{ "ok": false, "error": { "codigo", "mensaje", "detalles" } }`.

Toda petición que **no sea GET** debe llevar el encabezado `X-Requested-With: XMLHttpRequest` (protección CSRF básica).

| Método | Ruta | Quién | Descripción |
|---|---|---|---|
| POST | `/auth/login` | público | Login; crea sesión y registra el intento |
| POST | `/auth/logout` | cualquiera | Cierra la sesión |
| GET | `/auth/me` | autenticado | Usuario actual + módulos permitidos |
| GET | `/usuarios` | Admin, Jefes | Lista con `q`, `rol_id`, `activo`, `pagina`, `por_pagina` (Jefes: solo su área) |
| GET | `/usuarios/:id` | Admin, Jefes | Detalle (Jefes: solo su área, si no → 403) |
| POST | `/usuarios` | Admin | Crear |
| PUT | `/usuarios/:id` | Admin | Editar nombre, email, rol, estado |
| PATCH | `/usuarios/:id/password` | uno mismo / Admin | Cambiar contraseña (uno mismo debe enviar `password_actual`) |
| DELETE | `/usuarios/:id` | Admin | Baja lógica (`activo = 0`) |
| GET | `/roles` | autenticado | Roles (Admin: todos; otros: los de su área) |
| GET | `/permisos` | Admin | Matriz rol × módulo |
| GET | `/logs-acceso` | Admin | Auditoría con `resultado`, `desde`, `hasta`, `q`, paginación |

Códigos: `401` sin sesión / sesión expirada · `403` rol sin permiso · `404` · `422` validación · `429` cuenta bloqueada.

### API del Sprint 2 (tienda, pagos, logística)

Autenticación: cookie de sesión (la usa el frontend) **o** `Authorization: Bearer <token>` (el token llega en la respuesta de `/auth/login` y `/auth/register`, vence en 2 h). Con Bearer no hace falta el encabezado `X-Requested-With`.

| Método | Ruta | Quién | Descripción |
|---|---|---|---|
| POST | `/auth/register` | público | Crea una cuenta **Cliente** (el rol no se puede elegir) y devuelve sesión + JWT |
| GET | `/productos` | público | Catálogo: `q`, `marca`, `min`, `max`, `ram_gb`, `almacenamiento_gb`, `disponible=1`, `orden` (`nuevo`, `precio_asc`, `precio_desc`, `nombre`), `pagina`, `por_pagina` |
| GET | `/productos/filtros` · `/productos/:id` | público | Opciones para los filtros · detalle |
| GET | `/admin/productos` | módulo catálogo | Todos los productos (incluye dados de baja) |
| POST · PUT · DELETE | `/productos` · `/productos/:id` | catálogo (total) | Crear · editar · baja lógica. El stock **no** se edita aquí |
| GET | `/inventario/stock` · `/inventario/imeis` | módulo inventario | Resumen por producto · listado de IMEI (`producto_id`, `estado`, `q`) |
| POST | `/inventario/imeis` | inventario (total) | `{producto_id, imeis:[...]}`: valida 15 dígitos + Luhn, rechaza repetidos, suma stock |
| GET · POST · DELETE | `/carrito` | Cliente | Ver · `{producto_id, cantidad}` agregar · vaciar |
| PATCH · DELETE | `/carrito/:id` | Cliente | Fijar cantidad · quitar línea |
| POST | `/pedidos` | Cliente | **Checkout**: `{direccion_envio, ciudad, telefono, notas}` convierte el carrito en pedido `Pendiente` |
| GET | `/pedidos` · `/pedidos/:id` | Cliente (los suyos) · módulo ventas (todos) | Lista (`estado_pago`, `estado_envio`, `q` por N°, cliente o **IMEI**) y detalle con IMEI |
| PATCH | `/pedidos/:id/cancelar` | dueño · ventas (total) | Solo pedidos sin pagar |
| POST | `/pagos/procesar` | Cliente | **Pasarela simulada**. 201 = aprobado · 402 = rechazado · 422 = datos inválidos |
| GET | `/pagos` | módulo cobranzas | Transacciones, con total cobrado |
| POST | `/logistica/despacho` | inventario (total) | `{pedido_id}`: descuenta stock + asigna IMEI + crea el despacho. **Idempotente** |
| GET | `/logistica/despachos` | módulo distribución | Despachos con cliente, destino e IMEI |
| PATCH | `/logistica/despachos/:id/estado` | distribución (total) | `En_preparacion → En_camino → Entregado` (al entregar, los IMEI pasan a `Entregado`) |
| GET | `/reportes/resumen` | autenticado | Datos del panel (cada bloque según los módulos del rol) |

**Pago** — cuerpo de `/pagos/procesar`:

```json
{ "pedido_id": 6, "metodo": "Tarjeta",
  "tarjeta": { "numero": "4242 4242 4242 4242", "titular": "Cliente Demo", "vencimiento": "12/29", "cvv": "123" } }
```
`metodo` también puede ser `"QR"` (sin más datos) o `"Transferencia"` con `"comprobante": "TRF-123456"`.

**Tarjetas de prueba:** `4242 4242 4242 4242` aprobada · `4000 0000 0000 0002` rechazada (banco) · `4000 0000 0000 9995` rechazada (fondos insuficientes). Cualquier otro número válido (Luhn) se aprueba.

### Flujo de compra (lo que ocurre por dentro)

```
Cliente              POST /carrito          carrito_items (en el servidor)
  │                  POST /pedidos          pedidos(Pendiente) + detalle_pedido   ← precios desde la BD
  │                  POST /pagos/procesar ─► PasarelaSimulada.autorizar()
  │                                          ├─ rechazado → pagos(Rechazado), pedido=Rechazado (se puede reintentar)
  │                                          └─ aprobado  → TRANSACCIÓN ÚNICA:
  │                                               1. pagos(Aprobado) · pedido = Pagado
  │                                               2. Logistica::despachar()
  │                                                    · productos.stock_actual  −= cantidad
  │                                                    · imeis: Disponible → Vendido (IMEI único por unidad)
  │                                                    · detalle_pedido.imei_asignado (una fila por unidad)
  │                                                    · despachos(En_preparacion) + código de guía
  │                                               si algo falla (p. ej. sin stock) → ROLLBACK de todo
Repartidor           PATCH /logistica/despachos/:id/estado   En_camino → Entregado (IMEI → Entregado)
```

### Probar con curl (CMD de Windows: usa `curl.exe`)

```bash
# 1) Login y guardar la cookie de sesión
curl -c cookies.txt -X POST http://localhost/smartcell/api/auth/login ^
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" ^
  -d "{\"email\":\"admin@smartcell.com\",\"password\":\"Admin123!\"}"

# 2) Usuario actual
curl -b cookies.txt http://localhost/smartcell/api/auth/me

# 3) Listar con búsqueda y filtros
curl -b cookies.txt "http://localhost/smartcell/api/usuarios?q=jefe&activo=1&pagina=1&por_pagina=5"

# 4) Crear usuario
curl -b cookies.txt -X POST http://localhost/smartcell/api/usuarios ^
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" ^
  -d "{\"nombre\":\"Ana Prueba\",\"email\":\"ana@smartcell.com\",\"rol_id\":4,\"password\":\"Clave1234\"}"

# 5) Editar (id 9 de ejemplo)
curl -b cookies.txt -X PUT http://localhost/smartcell/api/usuarios/9 ^
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" ^
  -d "{\"nombre\":\"Ana Editada\",\"email\":\"ana@smartcell.com\",\"rol_id\":5,\"activo\":true}"

# 6) Restablecer contraseña (Admin)
curl -b cookies.txt -X PATCH http://localhost/smartcell/api/usuarios/9/password ^
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" ^
  -d "{\"password\":\"NuevaClave99\"}"

# 7) Baja lógica
curl -b cookies.txt -X DELETE http://localhost/smartcell/api/usuarios/9 -H "X-Requested-With: XMLHttpRequest"

# 8) Roles, matriz y auditoría
curl -b cookies.txt http://localhost/smartcell/api/roles
curl -b cookies.txt http://localhost/smartcell/api/permisos
curl -b cookies.txt "http://localhost/smartcell/api/logs-acceso?resultado=fallido&desde=2026-10-01&por_pagina=5"

# 9) Logout
curl -b cookies.txt -X POST http://localhost/smartcell/api/auth/logout -H "X-Requested-With: XMLHttpRequest"
```

### Probar con Postman
1. Crea una petición `POST http://localhost/smartcell/api/auth/login`, pestaña **Body → raw → JSON** con el email y la contraseña.
2. En **Headers** agrega `X-Requested-With: XMLHttpRequest` (en todas las que no sean GET).
3. Postman guarda la cookie `SMARTCELLSID` automáticamente; las siguientes peticiones ya van autenticadas.

---

## 6. Qué hice (guía de cambios respecto al login original)

**Migración a SQLite.** `includes/config.php` (MySQL) pasó a `backend/src/Database.php` (PDO + SQLite). `database.sql` pasó a `database/schema.sql` + `seeder.php`.
La tabla `usuarios` ahora usa `rol_id` (FK a `roles`) en lugar del ENUM; `password` sigue siendo hash bcrypt; se agregó `actualizado_en`.
`logs_acceso` ganó la columna `ip` y el resultado `bloqueado`.

**Del login PHP clásico a API REST.** `index.php`/`dashboard.php`/`logout.php` se reemplazaron por los endpoints `/auth/*`.
Las funciones `requerirLogin()`, `requerirRol()` y `registrarIntento()` se conservaron (mismos nombres) en `backend/src/Auth.php`, ahora respondiendo `401`/`403` en JSON.

**RBAC reutilizable.** Cada ruta se declara en `backend/public/index.php` con su lista de roles, por ejemplo
`$router->add('POST', '/usuarios', [UsuarioController::class, 'crear'], [ROL_ADMIN]);` — ahí se ve toda la política de acceso de un vistazo.

**Frontend Vue.** Las 10 pantallas pedidas, con el mismo tema grafito + verde señal, menú lateral que cambia según el rol, guards de ruta, aviso de sesión expirada y notificaciones.
Las tablas se convierten en tarjetas en el celular (RNF-04).

### Seguridad implementada
- **Consultas preparadas** en todo el acceso a datos (sin SQL injection); los `LIKE` escapan `% _ \`.
- **bcrypt** (cost 10) para contraseñas; mínimo 8 caracteres, máximo 72 (límite de bcrypt).
- **Email único** (sin distinguir mayúsculas), validación de formato y de rol existente.
- **Bloqueo temporal:** 5 intentos fallidos seguidos para un correo bloquean el login 15 minutos (HTTP 429, se registra como `bloqueado`).
- Un **Admin no puede desactivarse ni cambiar su propio rol**.
- **Baja lógica**, nunca borrado físico.
- Sesión con cookie `HttpOnly` + `SameSite=Lax` (y `Secure` automático si hay HTTPS), `session_regenerate_id` al entrar, **expira a los 30 min** de inactividad.
- Cada petición vuelve a leer al usuario en la BD: si lo dan de baja o le cambian el rol, se aplica al instante.
- Mismo mensaje de error para correo inexistente o contraseña incorrecta, y tiempo similar (no revela qué correos existen).
- CSRF básico con el encabezado `X-Requested-With`; carpetas `backend/` y `database/` bloqueadas por `.htaccess`.
- Vue escapa todo el texto que muestra (protección XSS).
- **HTTPS (RNF-01):** en XAMPP local se usa HTTP; en producción hay que servir con HTTPS y la cookie pasa a `Secure` sola.

### Límites conocidos (para decirlo con honestidad al ingeniero)
- El bloqueo es por correo: alguien podría bloquear a propósito la cuenta de otro. En producción se combinaría con límite por IP / CAPTCHA.
- La matriz de permisos es de **solo lectura** en pantalla (se edita en la BD / seeder). Hacerla editable sería un siguiente sprint.
- La pasarela de pagos es **simulada** (no mueve dinero). Para una pasarela real habría que reemplazar `PasarelaSimulada::autorizar()` por la llamada al proveedor y anular la autorización si falla el despacho.
- Sin reembolsos ni devoluciones, sin cálculo de costo de envío y sin límite de intentos para el registro público (en producción: CAPTCHA / límite por IP).
- Las fotos de los productos se cargan por URL (no hay subida de archivos); sin foto se dibuja un celular de color.
- SQLite sirve para un prototipo y una demo; con mucho tráfico concurrente conviene PostgreSQL o MySQL (las consultas son SQL estándar, salvo `INSERT … ON CONFLICT` y `group_concat`).

---

## 7. Lista de verificación para la demostración

| Requisito del ingeniero | Dónde demostrarlo |
|---|---|
| Prototipo del subsistema Seguridad: CRUD + login | Entrar como admin → menú **Usuarios** (crear, editar, desactivar) |
| Backend en el lenguaje del proyecto (PHP) | Carpeta `backend/`; `backend/public/index.php` |
| Base de datos SQLite, sin MySQL | `database/smartcell.sqlite` + `schema.sql`; XAMPP solo con Apache |
| Frontend en JavaScript con framework (Vue) | Carpeta `frontend/` |
| Roles Jefe de Distribución y Jefe de Almacén | Login con cada uno; ven solo usuarios de su área y no pueden modificar |
| Todas las pantallas del módulo | Ver tabla de pantallas abajo |
| Gestión de usuarios | Lista con búsqueda, filtros y paginación; formularios con validación |
| RF-06 RBAC | Pantalla **Roles y permisos** (matriz); entrar con Cliente y abrir `#/usuarios` → 403 |
| RNF-01 contraseñas cifradas | En SQLite la columna `password` empieza con `$2y$10$` (bcrypt) |
| RNF-02 respuestas < 2 s | Pestaña Red del navegador (F12): las llamadas tardan milisegundos |
| RNF-04 responsive | F12 → modo móvil: menú hamburguesa y tablas en tarjetas |
| API REST | Sección 5; probar con curl/Postman |
| Consultas preparadas / sin SQL injection | Buscar `' OR 1=1--` en el buscador de usuarios: devuelve 0 resultados |
| Validaciones | Email duplicado, contraseña de menos de 8 caracteres, correo inválido → mensaje en el campo |
| Admin no puede desactivarse | Editar su propio usuario: rol y estado bloqueados; `DELETE /usuarios/1` → 422 |
| Bloqueo tras 5 intentos | Fallar 5 veces con un correo → mensaje "intenta en 15 minuto(s)"; queda en Auditoría como *Bloqueado* |
| Auditoría | Pantalla **Auditoría de accesos** con filtros por fecha y resultado |
| JWT | `POST /auth/login` devuelve `token`; `GET /auth/me` con `Authorization: Bearer …` y sin cookie funciona; con un carácter cambiado → 401 |
| E-commerce | `#/tienda` (público) → carrito → checkout → pedido en *Mis pedidos* |
| Pagos | Tarjetas de prueba (sección 5); QR; transferencia; pago rechazado y reintento |
| Stock e IMEI automáticos | Tras pagar: stock −N, N IMEI en `Vendido`, pedido con `imei_asignado` y despacho con guía |
| Logística | *Despachos*: En preparación → En camino → Entregado (IMEI → Entregado) |
| Panel administrativo | `#/panel` como Admin: ventas, inventario y últimos pedidos con sus IMEI |
| Sesión expirada | Borrar la cookie `SMARTCELLSID` (F12 → Aplicación) y navegar → vuelve al login con aviso |

### Las 10 pantallas
1. **Login** — `#/login`
2. **Dashboard** — `#/panel`
3. **Lista de usuarios** — `#/usuarios`
4. **Crear usuario** — `#/usuarios/nuevo`
5. **Editar usuario** — `#/usuarios/:id/editar`
6. **Confirmación de baja** — modal al pulsar *Desactivar*
7. **Mi perfil / cambiar contraseña** — `#/perfil`
8. **Roles y permisos** — `#/roles`
9. **Auditoría de accesos** — `#/auditoria`
10. **403 y 404** — `#/403` y cualquier ruta inexistente

### Guion sugerido (5 minutos)
1. Entra como **Admin**: muestra el panel, crea un usuario "Jefe de Almacén", edítalo, desactívalo y reactívalo.
2. Abre **Roles y permisos** y explica la matriz.
3. Cierra sesión y entra como **Jefe de Almacén**: solo ve su área, sin botones de crear/editar. Intenta `#/roles` → 403.
4. Falla 5 veces el login de un usuario → bloqueo → muestra la **Auditoría**.
5. Muestra una llamada con curl/Postman y la columna `password` en SQLite.

---

## 8. Sprint 2 — seguridad de la tienda, decisiones y pruebas

**Seguridad añadida**
- **JWT HS256** firmado con un secreto aleatorio que se crea solo en `database/.jwt_secret` (nunca en el código). El algoritmo no se lee del token (evita `alg: none`) y el **rol se vuelve a leer de la BD** en cada petición, no del token.
- **RBAC por módulo** (`'modulo:inventario:total'`): si se cambia la matriz `permisos_rol`, el backend lo respeta sin tocar código.
- **IDOR:** cada consulta del carrito y de pedidos filtra por el usuario autenticado. Un cliente que pide el pedido de otro recibe `404`.
- **Precios y totales siempre desde la BD**: el navegador solo manda ids y cantidades; el monto cobrado es `pedidos.total`.
- **Datos de tarjeta:** el número completo y el CVV solo viven en memoria durante el cobro; en la BD queda únicamente `tarjeta_ultimos4`.
- **Registro público:** el rol siempre es `Cliente`; no se acepta `rol_id` del navegador.
- **Transacciones** `BEGIN IMMEDIATE` + base en modo **WAL**: si dos clientes pagan a la vez la última unidad, uno compra y el otro recibe `409 stock_insuficiente`; nunca se repite un IMEI.
- URLs de fotos: solo `http(s)://`; se bloquean `javascript:` y similares.

**Decisiones de diseño**
- Una línea de pedido se **desdobla en una fila por unidad** al despachar, para guardar un IMEI único en `detalle_pedido.imei_asignado` (el esquema pedido: id, pedido_id, producto_id, imei_asignado, cantidad, precio_unitario).
- Si el pago es **rechazado**, el pedido queda pendiente y el cliente puede reintentar desde *Mis pedidos → Pagar ahora*. Los pedidos sin pagar se pueden cancelar; los pagados no (requerirían reembolso).
- El stock se **valida** al agregar al carrito y al hacer checkout, y se **descuenta** solo al confirmarse el pago.

**Qué se probó (API, con curl contra `php -S`)**: registro y login con JWT (y token manipulado → 401), catálogo con filtros, carrito (tope por stock y producto agotado), checkout con validaciones, pago aprobado / rechazado / datos inválidos / doble pago, descuento de stock, asignación de IMEI, despacho idempotente, transiciones de despacho inválidas, IDOR entre clientes, permisos por rol (Vendedor no crea productos, Cliente no ve pagos ni inventario), carga de IMEI con inválidos y repetidos, y compras simultáneas por la última unidad.
**Pendiente de tu lado:** recorrer las pantallas en el navegador con la lista de la sección 7 (el frontend tiene revisión de sintaxis y de plantillas, pero no pudo ejecutarse en el entorno donde se desarrolló porque no tenía acceso a los CDN de Vue).

### Guion de demostración del Sprint 2 (8 minutos)
1. **Sin sesión**: abre `#/tienda`, filtra por marca y precio, entra al detalle. «Añadir al carrito» te lleva al login.
2. **Cliente** (`cliente@smartcell.com`): añade un Galaxy S24 y un Redmi Note 13, abre el carrito, cambia cantidades, **Continuar al pago**.
3. Intenta pagar con `4000 0000 0000 0002` → *Pago rechazado*. Cambia a `4242 4242 4242 4242` → aprobado: aparece el pedido con **su IMEI asignado** y la línea de tiempo.
4. **Admin** (`admin@smartcell.com`): el Panel muestra ingresos, ventas de 7 días, stock bajo y el pedido nuevo **con sus IMEI**. En *Inventario* el stock bajó y esos IMEI están en `Vendido`.
5. **Inventariado**: *Inventario → Ingresar equipos*; pega IMEI válidos, uno inválido y uno repetido y mira el resultado.
6. **Repartidor** (`repartidor@smartcell.com`): *Despachos → Marcar en camino → Marcar entregado*; los IMEI pasan a `Entregado`.
7. **Cobranzas**: *Pagos* con el rechazo y el aprobado. **Vendedor**: intenta `#/productos-admin` y ve que solo puede leer.



---

## 9. Sprint 3 — roles, categorías, imágenes, promociones, WhatsApp, informes y panel

> **Importante al actualizar:** el esquema y los roles cambiaron. Ejecuta `php database/seeder.php` (borra y recrea los datos de ejemplo).

### 9.1 Los 4 roles

| Módulo | Admin | Vendedor | Repartidor | Cliente |
|---|---|---|---|---|
| Panel / Mi perfil | total | total | total | total |
| Usuarios, Roles, Auditoría | total | — | — | — |
| Catálogo (productos y categorías) | total | total | — | — |
| Promociones | total | total | — | — |
| Inventario | total | total | — | — |
| Pedidos y mensajes de WhatsApp | total | total | — | — |
| Cobranzas | total | lectura | — | — |
| Distribución (despachos) | total | lectura | total | — |
| Informes | total | lectura | — | — |
| Tienda (comprar) | — | — | — | total |

### 9.2 Categorías
Teléfonos (control por **IMEI**), Accesorios y Complementos (control por **cantidad**). El admin y el vendedor pueden crear más desde *Productos → Categorías*. Una categoría con productos no se puede eliminar ni cambiar su tipo de control. Los campos técnicos (RAM, almacenamiento, procesador…) solo aparecen para equipos con IMEI.

### 9.3 Imágenes
Hasta 5 fotos por producto, subidas desde el panel (arrastrar o elegir). El navegador las reduce a 1400 px y el servidor valida el tipo real del archivo (jpg/png/webp), tamaño máx. 2 MB y nombre aleatorio. `frontend/uploads/.htaccess` impide ejecutar PHP en esa carpeta. La primera foto es la principal.

### 9.4 Promociones
Descuento % por producto o por categoría, con fechas de vigencia, e imagen de banner. Las vigentes salen como **banner de ofertas** en la tienda, con etiqueta -% y precio tachado. El precio pagado (y el descuento) queda **congelado** en cada pedido.

### 9.5 Confirmación y comprobante automáticos por WhatsApp (local)
Al aprobarse el pago, el sistema crea solo dos mensajes: **confirmación** y **comprobante** (más "pedido en camino" y "entregado" cuando cambia el envío). Se ven en *Mensajes de WhatsApp*; el botón **Abrir WhatsApp** abre el chat del cliente (`wa.me`) con el texto ya escrito y solo hay que pulsar Enviar. No usa API de pago ni servicios externos. El comprobante (`CP-000016`) tiene su pantalla imprimible (Imprimir → Guardar como PDF).

### 9.6 Informes
Ventas (agrupado por día/semana/mes), productos, inventario, pedidos y clientes: rango de fechas, KPIs, gráficos, tabla y **descarga CSV** (separador `;`, abre en Excel con tildes).

### 9.7 Panel interactivo
Selector de 7/30/90 días, comparación con el período anterior, gráfico de ingresos con detalle por día, ventas por categoría, más vendidos, estados clicables que llevan a los pedidos filtrados, actualización automática opcional, y para el repartidor los botones *Salir a entregar / Marcar entregado*.

### 9.8 Endpoints nuevos
`GET/POST/PUT/DELETE /categorias` · `GET /promociones/vigentes` (público) · `GET/POST/PUT/DELETE /promociones` · `POST /promociones/imagen` · `POST /productos/:id/fotos` · `POST /inventario/ajuste` · `GET /pedidos/:id/comprobante` · `GET /notificaciones` · `PATCH /notificaciones/:id/enviado` · `GET /informes/{ventas|productos|inventario|pedidos|clientes}` (`?desde&hasta&agrupar&formato=csv`) · `GET /reportes/resumen?dias=`

### 9.9 Guion de demostración (6 minutos)
1. Entra como **cliente**: ve el banner de ofertas, filtra por *Accesorios* y *Ofertas*, compra un accesorio con descuento.
2. Entra como **vendedor**: *Mensajes de WhatsApp* muestra la confirmación y el comprobante; pulsa *Abrir WhatsApp*.
3. *Pedidos → Comprobante*: imprime o guarda en PDF.
4. En *Productos*: crea una categoría, un producto y sube sus fotos. En *Promociones*: crea una oferta.
5. Entra como **repartidor**: en el panel pulsa *Salir a entregar* y *Marcar entregado*.
6. Entra como **admin**: panel con 7/30/90 días, *Informes* y descarga CSV.

### 9.10 Límites conocidos
- El envío por WhatsApp es **manual y local** (una persona pulsa Enviar); no hay envío automático sin una API de WhatsApp Business.
- La pasarela de pagos sigue siendo simulada.
- La interfaz del Sprint 3 se verificó con revisión de sintaxis y pruebas de la API (permisos por rol, concurrencia, descuentos, informes), pero **hay que probarla en un navegador**; si ves un error en la consola (F12), envíalo para corregirlo.
