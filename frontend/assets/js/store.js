/*
 * Estado global (Vue reactive): sesión, módulos permitidos, notificaciones y menú.
 */
SC.store = Vue.reactive({
    user: null,          // { id, nombre, email, rol, rol_id, area }
    modulos: {},         // { clave: 'total' | 'lectura' }
    cargado: false,      // ¿ya se consultó /auth/me?
    toasts: [],
    menuAbierto: false,

    aplicarSesion(payload) {
        this.user = payload.usuario;
        this.modulos = payload.modulos || {};
    },
    limpiar() {
        this.user = null;
        this.modulos = {};
    },
    async cargarSesion() {
        try {
            const r = await SC.api.get('/auth/me');
            this.aplicarSesion(r.data);
        } catch (e) {
            this.limpiar();
        }
        this.cargado = true;
    },
    /** Nivel de acceso al módulo: 'total', 'lectura' o null */
    nivel(clave) { return this.modulos[clave] || null; },
    puedeEscribir(clave) { return this.modulos[clave] === 'total'; },

    toast(tipo, mensaje) {
        const id = Date.now() + Math.random();
        this.toasts.push({ id, tipo, mensaje });
        setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4500);
    },
    ok(m)    { this.toast('ok', m); },
    error(m) { this.toast('error', m); }
});

/* Definición del menú lateral. Se muestra solo lo que el rol puede acceder. */
SC.MENU = [
    { clave: 'panel',               grupo: 'General',       texto: 'Panel',               ruta: '/panel',   icono: 'inicio' },
    { clave: 'mi_perfil',           grupo: 'General',       texto: 'Mi perfil',           ruta: '/perfil',  icono: 'usuario' },
    { clave: 'seguridad_usuarios',  grupo: 'Seguridad',     texto: 'Usuarios',            ruta: '/usuarios', icono: 'usuarios' },
    { clave: 'seguridad_roles',     grupo: 'Seguridad',     texto: 'Roles y permisos',    ruta: '/roles',   icono: 'escudo' },
    { clave: 'seguridad_auditoria', grupo: 'Seguridad',     texto: 'Auditoría de accesos', ruta: '/auditoria', icono: 'lista' },
    { clave: 'catalogo',            grupo: 'Otros módulos', texto: 'Catálogo',            ruta: '/modulo/catalogo',     icono: 'etiqueta' },
    { clave: 'inventario',          grupo: 'Otros módulos', texto: 'Inventario por IMEI', ruta: '/modulo/inventario',   icono: 'caja' },
    { clave: 'distribucion',        grupo: 'Otros módulos', texto: 'Distribución',        ruta: '/modulo/distribucion', icono: 'camion' },
    { clave: 'ventas',              grupo: 'Otros módulos', texto: 'Ventas',              ruta: '/modulo/ventas',       icono: 'carrito' },
    { clave: 'cobranzas',           grupo: 'Otros módulos', texto: 'Cobranzas',           ruta: '/modulo/cobranzas',    icono: 'billetera' }
];

/* Utilidades de formato */
SC.util = {
    fecha(texto) {
        if (!texto) return '—';
        const [f, h] = texto.split(' ');
        const [a, m, d] = f.split('-');
        return d + '/' + m + '/' + a + (h ? ' ' + h.slice(0, 5) : '');
    },
    iniciales(nombre) {
        return (nombre || '?').split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase();
    },
    emailValido(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); }
};
