/*
 * Estado global (Vue reactive): sesión, módulos permitidos, carrito, notificaciones y menú.
 */
SC.store = Vue.reactive({
    user: null,          // { id, nombre, email, rol, rol_id, area }
    modulos: {},         // { clave: 'total' | 'lectura' }
    cargado: false,      // ¿ya se consultó /auth/me?
    toasts: [],
    menuAbierto: false,
    mensajesPendientes: 0,   // mensajes de WhatsApp por enviar (insignia del menú)

    aplicarSesion(payload) {
        this.user = payload.usuario;
        this.modulos = payload.modulos || {};
        if (this.esCliente) SC.cart.cargar();      // el contador del carrito se actualiza solo
        this.contarMensajes();
    },
    limpiar() {
        this.user = null;
        this.modulos = {};
        SC.cart.vaciarLocal();
    },
    /** Cuenta los mensajes automáticos pendientes (solo personal con el módulo ventas) */
    async contarMensajes() {
        if (!this.user || !this.modulos.ventas) return;
        try { this.mensajesPendientes = (await SC.api.get('/notificaciones', { estado: 'Pendiente', por_pagina: 1 })).meta.pendientes; }
        catch (e) { /* la insignia es opcional */ }
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
    async cerrarSesion() {
        try { await SC.api.post('/auth/logout'); } catch (e) { /* aunque falle, limpiamos el cliente */ }
        this.limpiar();
    },
    get esCliente() { return !!this.user && this.user.rol === 'Cliente'; },
    /** Pantalla de inicio según el rol: el cliente va a la tienda, el personal a su panel */
    inicio() { return this.esCliente ? '/tienda' : '/panel'; },
    /** Nivel de acceso al módulo: 'total', 'lectura' o null */
    nivel(clave) { return this.modulos[clave] || null; },
    puedeEscribir(clave) { return this.modulos[clave] === 'total'; },

    toast(tipo, mensaje) {
        const id = Date.now() + Math.random();
        this.toasts.push({ id, tipo, mensaje });
        setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4500);
    },
    ok(m)    { this.toast('ok', m); },
    error(m) { this.toast('error', m); },
    info(m)  { this.toast('info', m); }
});

/* Carrito del cliente (la fuente de verdad es el servidor; aquí solo se refleja) */
SC.cart = Vue.reactive({
    items: [],
    total: 0,
    unidades: 0,
    aplicar(datos) {
        this.items = datos.items;
        this.total = datos.total;
        this.unidades = datos.unidades;
    },
    vaciarLocal() { this.items = []; this.total = 0; this.unidades = 0; },
    async cargar() {
        try { this.aplicar((await SC.api.get('/carrito')).data); } catch (e) { /* sin carrito */ }
    },
    async agregar(productoId, cantidad = 1) {
        this.aplicar((await SC.api.post('/carrito', { producto_id: productoId, cantidad })).data);
    },
    async cambiarCantidad(itemId, cantidad) {
        this.aplicar((await SC.api.patch('/carrito/' + itemId, { cantidad })).data);
    },
    async quitar(itemId) {
        this.aplicar((await SC.api.delete('/carrito/' + itemId)).data);
    },
    async vaciar() {
        this.aplicar((await SC.api.delete('/carrito')).data);
    }
});

/*
 * Definición del menú lateral del PERSONAL. Se muestra solo lo que el rol puede acceder
 * (la misma matriz permisos_rol que usa el backend). El Cliente usa la barra de la tienda.
 */
SC.MENU = [
    { clave: 'panel',               grupo: 'General',   texto: 'Panel',               ruta: '/panel',          icono: 'inicio' },
    { clave: 'mi_perfil',           grupo: 'General',   texto: 'Mi perfil',           ruta: '/perfil',         icono: 'usuario' },
    { clave: 'ventas',              grupo: 'Operación', texto: 'Pedidos',             ruta: '/pedidos',        icono: 'lista' },
    { clave: 'ventas',              grupo: 'Operación', texto: 'Mensajes WhatsApp',   ruta: '/mensajes',       icono: 'whatsapp', contador: 'mensajes' },
    { clave: 'catalogo',            grupo: 'Operación', texto: 'Productos',           ruta: '/productos-admin', icono: 'etiqueta' },
    { clave: 'promociones',         grupo: 'Operación', texto: 'Promociones',         ruta: '/promociones',    icono: 'oferta' },
    { clave: 'inventario',          grupo: 'Operación', texto: 'Inventario',          ruta: '/inventario',     icono: 'caja' },
    { clave: 'cobranzas',           grupo: 'Operación', texto: 'Pagos',               ruta: '/pagos',          icono: 'billetera' },
    { clave: 'distribucion',        grupo: 'Operación', texto: 'Despachos',           ruta: '/despachos',      icono: 'camion' },
    { clave: 'informes',            grupo: 'Operación', texto: 'Informes',            ruta: '/informes',       icono: 'grafico' },
    { clave: 'seguridad_usuarios',  grupo: 'Seguridad', texto: 'Usuarios',            ruta: '/usuarios',       icono: 'usuarios' },
    { clave: 'seguridad_roles',     grupo: 'Seguridad', texto: 'Roles y permisos',    ruta: '/roles',          icono: 'escudo' },
    { clave: 'seguridad_auditoria', grupo: 'Seguridad', texto: 'Auditoría de accesos', ruta: '/auditoria',     icono: 'lista' }
];

/* Utilidades de formato */
SC.util = {
    /** Fecha corta para ejes de gráficos: 2026-11-08 -> "08/11" */
    diaCorto(texto) { const p = String(texto).split('-'); return p.length === 3 ? p[2] + '/' + p[1] : texto; },
    /** Variación porcentual contra el período anterior (null si no hay base) */
    variacion(actual, anterior) { return anterior > 0 ? Math.round((actual - anterior) / anterior * 100) : null; },
    /** Formatea un valor de informe según su tipo */
    valor(v, tipo) {
        if (tipo === 'dinero') return SC.util.bs(v);
        if (tipo === 'fecha') return SC.util.fecha(v);
        if (tipo === 'porcentaje') return v + ' %';
        if (tipo === 'numero') return Number(v).toLocaleString('es-BO');
        return v;
    },
    /** Ruta de una imagen del servidor (relativa a frontend/) */
    img(ruta) { return ruta || ''; },
    /** Reduce una foto del celular a máx. 1400 px (JPEG) antes de subirla: sube más rápido y cabe en el límite */
    async reducirImagen(archivo, max = 1400) {
        if (!/^image\/(jpeg|png|webp)$/.test(archivo.type)) throw new Error('Elige una imagen JPG, PNG o WebP.');
        const bmp = await createImageBitmap(archivo);
        const k = Math.min(1, max / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise(ok => c.toBlob(ok, 'image/jpeg', 0.86));
        if (!blob) throw new Error('No se pudo procesar la imagen.');
        blob.name = 'imagen.jpg';
        return blob;
    },
    fecha(texto) {
        if (!texto) return '—';
        const [f, h] = texto.split(' ');
        const [a, m, d] = f.split('-');
        return d + '/' + m + '/' + a + (h ? ' ' + h.slice(0, 5) : '');
    },
    iniciales(nombre) {
        return (nombre || '?').split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase();
    },
    emailValido(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); },
    /** 1450 -> "Bs 1.450" · 1450.5 -> "Bs 1.450,50" */
    bs(n) {
        const [entero, dec] = Number(n || 0).toFixed(2).split('.');
        return 'Bs ' + entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (dec === '00' ? '' : ',' + dec);
    },
    /** "8 GB RAM · 256 GB" */
    specs(p) {
        return [p.ram_gb ? p.ram_gb + ' GB RAM' : '', p.almacenamiento_gb ? p.almacenamiento_gb + ' GB' : '']
            .filter(Boolean).join(' · ');
    },
    /** Etiqueta y color de cada estado: [texto, clase de badge] */
    ESTADOS: {
        pago:  { Pendiente: ['Pago pendiente', 'badge-warn'], Pagado: ['Pagado', 'badge-ok'], Rechazado: ['Pago rechazado', 'badge-error'],
                 Aprobado: ['Aprobado', 'badge-ok'] },
        envio: { Pendiente: ['Sin despachar', 'badge-rol'], En_preparacion: ['En preparación', 'badge-info'],
                 En_camino: ['En camino', 'badge-warn'], Entregado: ['Entregado', 'badge-ok'], Cancelado: ['Cancelado', 'badge-error'] },
        imei:  { Disponible: ['Disponible', 'badge-ok'], Vendido: ['Vendido', 'badge-warn'], Entregado: ['Entregado', 'badge-info'] }
    }
};
