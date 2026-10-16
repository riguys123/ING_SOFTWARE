/*
 * Cliente de la API REST (fetch). Todas las llamadas devuelven el JSON completo
 * { ok, data, meta } o lanzan un ApiError con status, codigo, mensaje y detalles.
 */
window.SC = window.SC || { views: {} };

class ApiError extends Error {
    constructor(status, codigo, mensaje, detalles) {
        super(mensaje);
        this.status = status;
        this.codigo = codigo;
        this.mensaje = mensaje;
        this.detalles = detalles || {};
    }
}

SC.api = (function () {
    const BASE = (window.SC_CONFIG && SC_CONFIG.API_BASE) || '../api';

    async function pedir(metodo, ruta, cuerpo, query) {
        let url = BASE + ruta;
        if (query) {
            const qs = new URLSearchParams();
            Object.entries(query).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) qs.append(k, v);
            });
            const s = qs.toString();
            if (s) url += '?' + s;
        }
        const opciones = {
            method: metodo,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (cuerpo !== undefined) {
            opciones.headers['Content-Type'] = 'application/json';
            opciones.body = JSON.stringify(cuerpo);
        }

        let resp;
        try {
            resp = await fetch(url, opciones);
        } catch (e) {
            throw new ApiError(0, 'sin_conexion', 'No se pudo conectar con el servidor.');
        }
        let json = null;
        try { json = await resp.json(); } catch (e) { /* respuesta no JSON */ }

        if (!resp.ok || !json || json.ok === false) {
            const er = (json && json.error) || {};
            const err = new ApiError(resp.status, er.codigo || 'error', er.mensaje || 'Ocurrió un error inesperado.', er.detalles);
            // Sesión vencida o cuenta dada de baja mientras se usaba la app
            if (resp.status === 401 && ['no_autenticado', 'sesion_expirada'].includes(err.codigo) && SC.onSesionExpirada) {
                SC.onSesionExpirada(err);
            }
            throw err;
        }
        return json;
    }

    return {
        get:    (ruta, query)  => pedir('GET', ruta, undefined, query),
        post:   (ruta, cuerpo) => pedir('POST', ruta, cuerpo || {}),
        put:    (ruta, cuerpo) => pedir('PUT', ruta, cuerpo),
        patch:  (ruta, cuerpo) => pedir('PATCH', ruta, cuerpo),
        delete: (ruta)         => pedir('DELETE', ruta)
    };
})();
