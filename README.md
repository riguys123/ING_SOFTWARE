# SmartCell Commerce — Subsistema de Seguridad · Sprint 1 (Gestión de Usuarios)

Prototipo del módulo de **Seguridad**: login, CRUD de usuarios, roles y permisos (RBAC) y auditoría de accesos.

| Capa | Tecnología |
|---|---|
| Backend | **PHP 8** puro, API REST en JSON, sesiones PHP |
| Base de datos | **SQLite** (`database/smartcell.sqlite`) — ya no se usa MySQL |
| Frontend | **JavaScript con Vue 3 + Vue Router** (sin compilar, no necesita Node) |
| Servidor | XAMPP (Apache + PHP) |

> **Supuestos que tomé** (el documento tenía los campos `[PHP / otro]` y `[React / Vue / Angular]` sin llenar):
> backend en **PHP** y frontend en **Vue 3**. Elegí Vue cargado directamente en el navegador para que corra en XAMPP sin instalar Node ni compilar. Si tu ingeniero pide React o Angular, la API REST no cambia: solo se reemplaza `frontend/`.

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
│   ├── schema.sql             Tablas: roles, usuarios, logs_acceso, modulos, permisos_rol
│   ├── seeder.php             Crea smartcell.sqlite + datos (un usuario por rol)
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
│       ├── Auth.php           Sesión, requerirLogin(), requerirRol(), registrarIntento()
│       ├── Router.php         Enrutador con RBAC declarativo
│       └── Controllers/       Auth, Usuario, Rol, Log
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
            └── views/         Las 10 pantallas
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

Tablas: `roles`, `usuarios`, `logs_acceso` (los tres que pidió el ingeniero), más `modulos` y `permisos_rol` (que alimentan la matriz de permisos y el menú).

### Usuarios de prueba (uno por rol)

| Rol | Correo | Contraseña |
|---|---|---|
| Admin | admin@smartcell.com | `Admin123!` |
| Jefe de Distribución | jefe.distribucion@smartcell.com | `Smart1234!` |
| Jefe de Almacén | jefe.almacen@smartcell.com | `Smart1234!` |
| Vendedor | vendedor@smartcell.com | `Smart1234!` |
| Inventariado | inventariado@smartcell.com | `Smart1234!` |
| Repartidor | repartidor@smartcell.com | `Smart1234!` |
| Cobranzas | cobranzas@smartcell.com | `Smart1234!` |
| Cliente | cliente@smartcell.com | `Smart1234!` |

---

## 4. Matriz de permisos inicial

| Módulo | Admin | Jefe Almacén | Jefe Distribución | Vendedor, Inventariado, Repartidor, Cobranzas, Cliente |
|---|:-:|:-:|:-:|:-:|
| Panel y Mi perfil | ✔ | ✔ | ✔ | ✔ |
| Gestión de usuarios | ✔ total | 👁 solo su área (inventario) | 👁 solo su área (distribución) | — |
| Roles y permisos | ✔ | — | — | — |
| Auditoría de accesos | ✔ | — | — | — |
| Inventario (placeholder) | ✔ | ✔ | — | — |
| Distribución (placeholder) | ✔ | — | ✔ | — |
| Catálogo, Ventas, Cobranzas (placeholder) | ✔ | — | — | — |

Áreas: Jefe de Almacén ve `Jefe de Almacén` + `Inventariado`; Jefe de Distribución ve `Jefe de Distribución` + `Repartidor`.
La matriz vive en la base de datos (`permisos_rol`), por lo que el backend, el menú y la pantalla de roles usan **la misma fuente**.

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
- Los módulos de otros equipos (catálogo, inventario, distribución, ventas, cobranzas) son placeholders.

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

