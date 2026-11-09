/* Promociones y banner de ofertas de la tienda (módulo promociones) */
SC.views.Promociones = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Promociones</h1><p class="sub">Descuentos por producto o categoría. Aparecen como banner en la tienda y se congelan en cada pedido.</p></div>
          <button v-if="puedeEscribir" class="btn btn-primario" @click="abrir(null)"><sc-icono nombre="mas" :tam="16"/> Nueva promoción</button>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Promoción</th><th>Aplica a</th><th>Descuento</th><th>Vigencia</th><th>Estado</th><th v-if="puedeEscribir" class="col-acciones">Acciones</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="6" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="6" class="vacio">Aún no hay promociones.</td></tr>
              <tr v-for="p in items" :key="p.id" v-show="!cargando">
                <td data-label="Promoción">
                  <div class="celda-producto">
                    <div class="mini-foto"><img v-if="p.imagen" :src="p.imagen" alt=""></div>
                    <span><strong>{{ p.titulo }}</strong><br><small>{{ p.descripcion }}</small></span>
                  </div>
                </td>
                <td data-label="Aplica a">{{ p.producto || (p.categoria ? 'Categoría ' + p.categoria : 'Solo banner') }}</td>
                <td data-label="Descuento"><strong>{{ p.descuento_pct ? p.descuento_pct + '%' : '—' }}</strong></td>
                <td data-label="Vigencia">{{ p.fecha_inicio }} → {{ p.fecha_fin }}</td>
                <td data-label="Estado"><span class="badge" :class="p.vigente ? 'badge-ok' : 'badge-error'">{{ p.vigente ? 'Vigente' : (p.activo ? 'Fuera de fecha' : 'Pausada') }}</span></td>
                <td v-if="puedeEscribir" class="col-acciones">
                  <div class="acciones">
                    <button class="btn btn-secundario btn-sm" @click="abrir(p)"><sc-icono nombre="editar" :tam="15"/> Editar</button>
                    <button class="btn btn-peligro-suave btn-sm" @click="borrar = p">Eliminar</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <sc-modal :abierto="!!form" grande :titulo="form && form.id ? 'Editar promoción' : 'Nueva promoción'" texto-confirmar="Guardar"
                  :cargando="guardando" @confirmar="guardar" @cancelar="form = null">
          <div v-if="form" class="form-modal">
            <div class="grid-2">
              <div class="campo" :class="{ 'con-error': err.titulo }"><label>Título *</label><input type="text" v-model.trim="form.titulo" placeholder="Ej: Semana de accesorios"><span v-if="err.titulo" class="error-campo">{{ err.titulo }}</span></div>
              <div class="campo" :class="{ 'con-error': err.descuento_pct }"><label>Descuento (%) · 0 = solo banner</label><input type="number" min="0" max="90" v-model="form.descuento_pct"><span v-if="err.descuento_pct" class="error-campo">{{ err.descuento_pct }}</span></div>
              <div class="campo" :class="{ 'con-error': err.fecha_inicio }"><label>Desde *</label><input type="date" v-model="form.fecha_inicio"><span v-if="err.fecha_inicio" class="error-campo">{{ err.fecha_inicio }}</span></div>
              <div class="campo" :class="{ 'con-error': err.fecha_fin }"><label>Hasta *</label><input type="date" v-model="form.fecha_fin"><span v-if="err.fecha_fin" class="error-campo">{{ err.fecha_fin }}</span></div>
              <div class="campo" :class="{ 'con-error': err.producto_id }"><label>Producto</label>
                <select v-model="form.producto_id" @change="if (form.producto_id) form.categoria_id = ''"><option value="">— Ninguno —</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.marca }} {{ p.modelo }}</option></select>
                <span v-if="err.producto_id" class="error-campo">{{ err.producto_id }}</span></div>
              <div class="campo" :class="{ 'con-error': err.categoria_id }"><label>o toda una categoría</label>
                <select v-model="form.categoria_id" @change="if (form.categoria_id) form.producto_id = ''"><option value="">— Ninguna —</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                <span v-if="err.categoria_id" class="error-campo">{{ err.categoria_id }}</span></div>
            </div>
            <div class="campo" :class="{ 'con-error': err.descripcion }"><label>Texto del banner</label><input type="text" v-model.trim="form.descripcion" maxlength="200"><span v-if="err.descripcion" class="error-campo">{{ err.descripcion }}</span></div>
            <div class="campo" :class="{ 'con-error': err.imagen }">
              <label>Imagen del banner (jpg, png o webp, se reduce automáticamente)</label>
              <div class="fotos-lista">
                <div v-if="form.imagen" class="foto-item principal"><img :src="form.imagen" alt="">
                  <div class="foto-acciones"><button type="button" class="btn-icono" title="Quitar" @click="form.imagen = ''"><sc-icono nombre="papelera" :tam="15"/></button></div></div>
                <label class="foto-subir"><input type="file" accept="image/jpeg,image/png,image/webp" hidden :disabled="subiendo" @change="subir"><sc-icono nombre="subir" :tam="22"/><span>{{ subiendo ? 'Subiendo…' : 'Subir imagen' }}</span></label>
              </div>
              <span v-if="err.imagen" class="error-campo">{{ err.imagen }}</span>
            </div>
            <label class="check"><input type="checkbox" v-model="form.activo"> Promoción activa</label>
          </div>
        </sc-modal>

        <sc-modal :abierto="!!borrar" titulo="Eliminar promoción" texto-confirmar="Eliminar" peligro @confirmar="eliminar" @cancelar="borrar = null">
          <p>¿Eliminar <strong>{{ borrar && borrar.titulo }}</strong>? Los pedidos ya pagados conservan el precio que pagaron.</p>
        </sc-modal>
      </div>`,
    data() { return { items: [], productos: [], categorias: [], cargando: true, form: null, err: {}, guardando: false, subiendo: false, borrar: null }; },
    computed: { puedeEscribir() { return SC.store.puedeEscribir('promociones'); } },
    async created() {
        this.cargar();
        try {
            this.categorias = (await SC.api.get('/categorias')).data;
            this.productos = (await SC.api.get('/admin/productos', { activo: 1, por_pagina: 100 })).data;
        } catch (e) { /* los selects quedan vacíos */ }
    },
    methods: {
        async cargar() {
            this.cargando = true;
            try { this.items = (await SC.api.get('/promociones')).data; }
            catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        abrir(p) {
            this.err = {};
            const hoy = new Date(), fin = new Date(Date.now() + 14 * 864e5);
            const f = d => d.toISOString().slice(0, 10);
            this.form = p ? { ...p, producto_id: p.producto_id || '', categoria_id: p.categoria_id || '', imagen: p.imagen || '', descripcion: p.descripcion || '', activo: !!p.activo }
                          : { id: null, titulo: '', descripcion: '', imagen: '', descuento_pct: 10, producto_id: '', categoria_id: '', fecha_inicio: f(hoy), fecha_fin: f(fin), activo: true };
        },
        async subir(ev) {
            const a = ev.target.files[0]; ev.target.value = '';
            if (!a) return;
            this.subiendo = true;
            try {
                const blob = await SC.util.reducirImagen(a);
                this.form.imagen = (await SC.api.subir('/promociones/imagen', 'imagen', blob)).data.ruta;
            } catch (e) { SC.store.error(e.mensaje || e.message); }
            finally { this.subiendo = false; }
        },
        async guardar() {
            this.err = {};
            this.guardando = true;
            try {
                const cuerpo = { ...this.form, activo: !!this.form.activo };
                if (this.form.id) await SC.api.put('/promociones/' + this.form.id, cuerpo);
                else await SC.api.post('/promociones', cuerpo);
                SC.store.ok('Promoción guardada.');
                this.form = null;
                this.cargar();
            } catch (e) {
                if (e.status === 422 && e.detalles) this.err = e.detalles;
                SC.store.error(e.mensaje);
            } finally { this.guardando = false; }
        },
        async eliminar() {
            try { await SC.api.delete('/promociones/' + this.borrar.id); SC.store.ok('Promoción eliminada.'); this.borrar = null; this.cargar(); }
            catch (e) { SC.store.error(e.mensaje); this.borrar = null; }
        }
    }
};
