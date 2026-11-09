/*
 * Rutas y guards (control de acceso por rol en el cliente).
 * IMPORTANTE: el control real está en el backend (RBAC); estos guards solo mejoran la experiencia.
 *
 * meta.publico -> login/registro: no requiere sesión (y si ya hay sesión, redirige al inicio)
 * meta.abierta -> abierta a todos (catálogo): con o sin sesión
 * meta.tienda  -> se muestra con la barra superior de la tienda en vez del menú lateral
 * meta.modulo  -> clave del módulo requerido (tabla modulos / permisos_rol)
 * meta.nivel   -> 'total' si exige permiso de escritura
 */
SC.router = VueRouter.createRouter({
    history: VueRouter.createWebHashHistory(),
    routes: [
        { path: '/', redirect: '/tienda' },
        { path: '/login',    name: 'login', component: SC.views.Login,    meta: { publico: true } },
        { path: '/registro', name: 'registro', component: SC.views.Registro, meta: { publico: true } },

        // ---- Tienda ----
        { path: '/tienda',                component: SC.views.Catalogo,        meta: { abierta: true, tienda: true } },
        { path: '/tienda/:id(\\d+)',      component: SC.views.ProductoDetalle, meta: { abierta: true, tienda: true } },
        { path: '/carrito',               component: SC.views.Carrito,         meta: { modulo: 'tienda', tienda: true } },
        { path: '/checkout',              component: SC.views.Checkout,        meta: { modulo: 'tienda', tienda: true } },
        { path: '/pagar/:pedidoId(\\d+)', component: SC.views.Checkout,        meta: { modulo: 'tienda', tienda: true } },
        { path: '/mis-pedidos',           component: SC.views.Pedidos,         meta: { modulo: 'tienda', tienda: true } },
        { path: '/mis-pedidos/:id(\\d+)', component: SC.views.PedidoDetalle,   meta: { modulo: 'tienda', tienda: true } },

        // ---- Operación (personal) ----
        { path: '/pedidos',               component: SC.views.Pedidos,         meta: { modulo: 'ventas' } },
        { path: '/pedidos/:id(\\d+)',     component: SC.views.PedidoDetalle,   meta: { modulo: 'ventas' } },
        { path: '/mensajes',              component: SC.views.Mensajes,        meta: { modulo: 'ventas' } },
        { path: '/productos-admin',       component: SC.views.ProductosAdmin,  meta: { modulo: 'catalogo' } },
        { path: '/promociones',           component: SC.views.Promociones,     meta: { modulo: 'promociones' } },
        { path: '/informes',              component: SC.views.Informes,        meta: { modulo: 'informes' } },
        { path: '/inventario',            component: SC.views.Inventario,      meta: { modulo: 'inventario' } },
        { path: '/pagos',                 component: SC.views.Pagos,           meta: { modulo: 'cobranzas' } },
        { path: '/despachos',             component: SC.views.Despachos,       meta: { modulo: 'distribucion' } },

        // Comprobante: lo ven el cliente (el suyo) y el personal de ventas; el servidor verifica a quién pertenece
        { path: '/comprobante/:id(\\d+)', component: SC.views.Comprobante, meta: { comprobante: true } },

        { path: '/panel',  component: SC.views.Dashboard, meta: { modulo: 'panel' } },
        { path: '/perfil', component: SC.views.Perfil,    meta: { modulo: 'mi_perfil' } },

        { path: '/usuarios',               component: SC.views.UsuariosLista, meta: { modulo: 'seguridad_usuarios' } },
        { path: '/usuarios/nuevo',         component: SC.views.UsuarioForm,   meta: { modulo: 'seguridad_usuarios', nivel: 'total', modo: 'crear' } },
        { path: '/usuarios/:id(\\d+)',        component: SC.views.UsuarioForm, meta: { modulo: 'seguridad_usuarios', modo: 'ver' } },
        { path: '/usuarios/:id(\\d+)/editar', component: SC.views.UsuarioForm, meta: { modulo: 'seguridad_usuarios', nivel: 'total', modo: 'editar' } },

        { path: '/roles',     component: SC.views.Roles,     meta: { modulo: 'seguridad_roles' } },
        { path: '/auditoria', component: SC.views.Auditoria, meta: { modulo: 'seguridad_auditoria' } },

        { path: '/403', component: SC.views.Error, meta: { codigo: 403 } },
        { path: '/:resto(.*)*', component: SC.views.Error, meta: { codigo: 404 } }
    ]
});

SC.router.beforeEach(async (to) => {
    const s = SC.store;
    if (!s.cargado) await s.cargarSesion();

    // Login / registro: si ya hay sesión, al inicio de su rol
    if (to.meta.publico) {
        return s.user ? s.inicio() : true;
    }
    // Catálogo y páginas de error: libres (se muestran con o sin sesión)
    if (to.meta.abierta || to.meta.codigo) return true;

    // Requiere sesión
    if (!s.user) return { path: '/login', query: { redir: to.fullPath } };

    // El cliente no usa el panel administrativo: su inicio es la tienda
    if (s.esCliente && to.path === '/panel') return '/tienda';

    // Requiere módulo
    const clave = to.meta.modulo;
    if (clave) {
        const nivel = s.nivel(clave);
        if (!nivel) return '/403';
        if (to.meta.nivel === 'total' && nivel !== 'total') return '/403';
    }
    return true;
});
