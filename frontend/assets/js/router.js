/*
 * Rutas y guards (control de acceso por rol en el cliente).
 * IMPORTANTE: el control real está en el backend (RBAC); estos guards solo mejoran la experiencia.
 *
 * meta.publico -> no requiere sesión
 * meta.modulo  -> clave del módulo requerido (tabla modulos / permisos_rol)
 * meta.nivel   -> 'total' si exige permiso de escritura
 */
SC.router = VueRouter.createRouter({
    history: VueRouter.createWebHashHistory(),
    routes: [
        { path: '/', redirect: '/panel' },
        { path: '/login',  name: 'login', component: SC.views.Login, meta: { publico: true } },

        { path: '/panel',  component: SC.views.Dashboard, meta: { modulo: 'panel' } },
        { path: '/perfil', component: SC.views.Perfil,    meta: { modulo: 'mi_perfil' } },

        { path: '/usuarios',               component: SC.views.UsuariosLista, meta: { modulo: 'seguridad_usuarios' } },
        { path: '/usuarios/nuevo',         component: SC.views.UsuarioForm,   meta: { modulo: 'seguridad_usuarios', nivel: 'total', modo: 'crear' } },
        { path: '/usuarios/:id(\\d+)',        component: SC.views.UsuarioForm, meta: { modulo: 'seguridad_usuarios', modo: 'ver' } },
        { path: '/usuarios/:id(\\d+)/editar', component: SC.views.UsuarioForm, meta: { modulo: 'seguridad_usuarios', nivel: 'total', modo: 'editar' } },

        { path: '/roles',     component: SC.views.Roles,     meta: { modulo: 'seguridad_roles' } },
        { path: '/auditoria', component: SC.views.Auditoria, meta: { modulo: 'seguridad_auditoria' } },

        { path: '/modulo/:clave', name: 'placeholder', component: SC.views.Placeholder },

        { path: '/403', component: SC.views.Error, meta: { codigo: 403 } },
        { path: '/:resto(.*)*', component: SC.views.Error, meta: { codigo: 404 } }
    ]
});

SC.router.beforeEach(async (to) => {
    const s = SC.store;
    if (!s.cargado) await s.cargarSesion();

    // Rutas públicas
    if (to.meta.publico) {
        return s.user ? '/panel' : true;
    }
    // Páginas de error: libres (se muestran con o sin sesión)
    if (to.meta.codigo) return true;

    // Requiere sesión
    if (!s.user) return { path: '/login', query: { redir: to.fullPath } };

    // Requiere módulo
    const clave = to.meta.modulo || (to.name === 'placeholder' ? to.params.clave : null);
    if (clave) {
        const nivel = s.nivel(clave);
        if (!nivel) return '/403';
        if (to.meta.nivel === 'total' && nivel !== 'total') return '/403';
    }
    return true;
});
