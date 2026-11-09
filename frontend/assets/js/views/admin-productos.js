/* Gestión de productos (módulo catalogo): lista + formulario en modal */
SC.views.ProductosAdmin = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Productos</h1><p class="sub">Celulares (control por IMEI), accesorios y complementos (control por cantidad). Stock en <router-link to="/inventario" v-if="verInventario">Inventario</router-link><template v-else>Inventario</template>.</p></div>
          <div v-if="puedeEscribir" class="acciones">
            <button class="btn btn-secundario" @click="gestCat = true"><sc-icono nombre="lista" :tam="16"/> Categorías</button>
            <button class="btn btn-primario" @click="abrir(null)"><sc-icono nombre="mas" :tam="16"/> Nuevo producto</button>
          </div>
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="aq">Buscar</label>
            <input id="aq" type="search" v-model="filtros.q" placeholder="Marca o modelo…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="ac">Categoría</label>
            <select id="ac" v-model="filtros.categoria" @change="alFiltrar(false)">
              <option value="">Todas</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
          </div>
          <div class="campo">
            <label for="aa">Estado</label>
            <select id="aa" v-model="filtros.activo" @change="alFiltrar(false)">
              <option value="">Todos</option><option value="1">Activos</option><option value="0">Dados de baja</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Producto</th><th>Categoría</th><th>Especificaciones</th><th>Precio</th><th>Stock</th><th>Estado</th><th v-if="puedeEscribir" class="col-acciones">Acciones</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="7" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="7" class="vacio">No se encontraron productos.</td></tr>
              <tr v-for="p in items" :key="p.id" v-show="!cargando">
                <td data-label="Producto">
                  <div class="celda-producto">
                    <div class="mini-foto"><sc-foto :fotos="p.fotos" :marca="p.marca" :modelo="p.modelo"/></div>
                    <span><strong>{{ p.marca }} {{ p.modelo }}</strong><br><small>{{ p.color || '' }}</small></span>
                  </div>
                </td>
                <td data-label="Categoría">{{ nombreCat(p.categoria_id) }}<br><small>{{ p.requiere_imei ? 'Control por IMEI' : 'Por cantidad' }}</small></td>
                <td data-label="Especificaciones">{{ specs(p) || '—' }}<br><small>{{ p.procesador }}</small></td>
                <td data-label="Precio">
                  <sc-precio :p="p"/>
                </td>
                <td data-label="Stock">
                  {{ p.stock_actual }} <small>{{ p.requiere_imei ? 'IMEI' : 'uds.' }}</small>
                  <span v-if="p.activo && p.stock_actual <= p.stock_minimo" class="badge badge-warn">Bajo</span>
                </td>
                <td data-label="Estado"><span class="badge" :class="p.activo ? 'badge-ok' : 'badge-error'">{{ p.activo ? 'Activo' : 'Baja' }}</span></td>
                <td v-if="puedeEscribir" class="col-acciones">
                  <div class="acciones">
                    <button class="btn btn-secundario btn-sm" @click="abrir(p)"><sc-icono nombre="editar" :tam="15"/> Editar</button>
                    <button v-if="p.activo" class="btn btn-peligro-suave btn-sm" @click="baja = p">Dar de baja</button>
                    <button v-else class="btn btn-secundario btn-sm" @click="reactivar(p)">Reactivar</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>

        <sc-modal :abierto="!!form" grande :titulo="form && form.id ? 'Editar producto' : 'Nuevo producto'" texto-confirmar="Guardar"
                  :cargando="guardando" @confirmar="guardar" @cancelar="form = null">
          <div v-if="form" class="form-modal">
            <div class="grid-2">
              <div class="campo" :class="{ 'con-error': err.marca }"><label>Marca *</label><input type="text" v-model.trim="form.marca"><span v-if="err.marca" class="error-campo">{{ err.marca }}</span></div>
              <div class="campo" :class="{ 'con-error': err.modelo }"><label>Modelo *</label><input type="text" v-model.trim="form.modelo"><span v-if="err.modelo" class="error-campo">{{ err.modelo }}</span></div>
              <div class="campo" :class="{ 'con-error': err.categoria_id }"><label>Categoría *</label>
                <select v-model="form.categoria_id"><option value="">— Elige —</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
                <span v-if="err.categoria_id" class="error-campo">{{ err.categoria_id }}</span></div>
              <div class="campo" :class="{ 'con-error': err.precio }"><label>Precio (Bs) *</label><input type="number" min="0" step="0.01" v-model="form.precio"><span v-if="err.precio" class="error-campo">{{ err.precio }}</span></div>
              <div class="campo" :class="{ 'con-error': err.stock_minimo }"><label>Stock mínimo (alerta)</label><input type="number" min="0" v-model="form.stock_minimo"><span v-if="err.stock_minimo" class="error-campo">{{ err.stock_minimo }}</span></div>
              <template v-if="esTelefono">
              <div class="campo" :class="{ 'con-error': err.procesador }"><label>Procesador</label><input type="text" v-model.trim="form.procesador"><span v-if="err.procesador" class="error-campo">{{ err.procesador }}</span></div>
              <div class="campo" :class="{ 'con-error': err.color }"><label>Color</label><input type="text" v-model.trim="form.color"><span v-if="err.color" class="error-campo">{{ err.color }}</span></div>
              <div class="campo" :class="{ 'con-error': err.ram_gb }"><label>RAM (GB)</label><input type="number" min="1" v-model="form.ram_gb"><span v-if="err.ram_gb" class="error-campo">{{ err.ram_gb }}</span></div>
              <div class="campo" :class="{ 'con-error': err.almacenamiento_gb }"><label>Almacenamiento (GB)</label><input type="number" min="1" v-model="form.almacenamiento_gb"><span v-if="err.almacenamiento_gb" class="error-campo">{{ err.almacenamiento_gb }}</span></div>
              <div class="campo" :class="{ 'con-error': err.camara }"><label>Cámara</label><input type="text" v-model.trim="form.camara"><span v-if="err.camara" class="error-campo">{{ err.camara }}</span></div>
              <div class="campo" :class="{ 'con-error': err.pantalla }"><label>Pantalla</label><input type="text" v-model.trim="form.pantalla"><span v-if="err.pantalla" class="error-campo">{{ err.pantalla }}</span></div>
              <div class="campo" :class="{ 'con-error': err.bateria_mah }"><label>Batería (mAh)</label><input type="number" min="500" v-model="form.bateria_mah"><span v-if="err.bateria_mah" class="error-campo">{{ err.bateria_mah }}</span></div>
              </template>
            </div>
            <div class="campo" :class="{ 'con-error': err.descripcion }"><label>Descripción</label><textarea rows="2" v-model.trim="form.descripcion"></textarea><span v-if="err.descripcion" class="error-campo">{{ err.descripcion }}</span></div>
            <div class="campo" :class="{ 'con-error': err.fotos }"><label>Fotos (máx. 5; la primera es la principal)</label>
              <sc-subir-fotos v-model="form.fotos" :producto-id="form.id || 0"/>
              <span v-if="err.fotos" class="error-campo">{{ err.fotos }}</span></div>
            <p v-if="!form.id" class="sub">Guarda el producto y podrás subir sus fotos. El stock inicial es 0: se ingresa desde Inventario ({{ esTelefono ? 'con IMEI' : 'por cantidad' }}).</p>
          </div>
        </sc-modal>

        <sc-modal :abierto="gestCat" grande titulo="Categorías del catálogo" texto-confirmar="Cerrar" @confirmar="gestCat = false" @cancelar="gestCat = false">
          <div class="form-modal">
            <table class="tabla">
              <thead><tr><th>Categoría</th><th>Control</th><th>Productos</th><th class="col-acciones"></th></tr></thead>
              <tbody>
                <tr v-for="c in categorias" :key="c.id">
                  <td data-label="Categoría"><strong>{{ c.nombre }}</strong><br><small>{{ c.descripcion }}</small></td>
                  <td data-label="Control">{{ c.usa_imei ? 'Por IMEI' : 'Por cantidad' }}</td>
                  <td data-label="Productos">{{ c.productos }}</td>
                  <td class="col-acciones"><button class="btn btn-peligro-suave btn-sm" @click="quitarCat(c)">Eliminar</button></td>
                </tr>
              </tbody>
            </table>
            <h3>Nueva categoría</h3>
            <div class="grid-2">
              <div class="campo" :class="{ 'con-error': errCat.nombre }"><label>Nombre *</label><input type="text" v-model.trim="nuevaCat.nombre" placeholder="Ej: Audio"><span v-if="errCat.nombre" class="error-campo">{{ errCat.nombre }}</span></div>
              <div class="campo"><label>Control de stock</label>
                <select v-model="nuevaCat.usa_imei"><option :value="false">Por cantidad (accesorios)</option><option :value="true">Por IMEI (equipos)</option></select></div>
            </div>
            <button class="btn btn-primario" @click="crearCat">Agregar categoría</button>
          </div>
        </sc-modal>

        <sc-modal :abierto="!!baja" titulo="Dar de baja el producto" texto-confirmar="Dar de baja" peligro @confirmar="darBaja" @cancelar="baja = null">
          <p>¿Dar de baja <strong>{{ baja && (baja.marca + ' ' + baja.modelo) }}</strong>? Dejará de aparecer en la tienda, pero se conserva su historial de pedidos.</p>
        </sc-modal>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, filtros: { q: '', activo: '', categoria: '' }, categorias: [], gestCat: false, nuevaCat: { nombre: '', usa_imei: false }, errCat: {},
                 form: null, err: {}, guardando: false, baja: null };
    },
    computed: {
        puedeEscribir() { return SC.store.puedeEscribir('catalogo'); },
        verInventario() { return !!SC.store.nivel('inventario'); },
        esTelefono() {
            const c = this.form && this.categorias.find(x => x.id === Number(this.form.categoria_id));
            return !!(c && c.usa_imei);
        }
    },
    created() { this.cargar(); this.cargarCategorias(); },
    methods: {
        specs: SC.util.specs,
        nombreCat(id) { const c = this.categorias.find(x => x.id === id); return c ? c.nombre : '—'; },
        async cargarCategorias() {
            try { this.categorias = (await SC.api.get('/categorias')).data; } catch (e) { SC.store.error(e.mensaje); }
        },
        async crearCat() {
            this.errCat = {};
            try {
                await SC.api.post('/categorias', { ...this.nuevaCat });
                this.nuevaCat = { nombre: '', usa_imei: false };
                SC.store.ok('Categoría creada.');
                this.cargarCategorias();
            } catch (e) { if (e.detalles) this.errCat = e.detalles; SC.store.error(e.mensaje); }
        },
        async quitarCat(c) {
            try { await SC.api.delete('/categorias/' + c.id); SC.store.ok('Categoría eliminada.'); this.cargarCategorias(); }
            catch (e) { SC.store.error(e.mensaje); }
        },
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/admin/productos', { ...this.filtros, pagina: this.pagina, por_pagina: 10 });
                this.items = r.data; this.meta = r.meta;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        alFiltrar(conRetraso) {
            clearTimeout(this.temporizador);
            this.pagina = 1;
            if (conRetraso) this.temporizador = setTimeout(() => this.cargar(), 350);
            else this.cargar();
        },
        irAPagina(p) { this.pagina = p; this.cargar(); },
        abrir(p) {
            this.err = {};
            this.form = p
                ? { ...p, fotos: [...(p.fotos || [])], categoria_id: p.categoria_id || '' }
                : { id: null, marca: '', modelo: '', precio: '', stock_minimo: 3, procesador: '', color: '', ram_gb: '', almacenamiento_gb: '',
                    camara: '', pantalla: '', bateria_mah: '', descripcion: '', fotos: [], categoria_id: '', activo: 1 };
        },
        async guardar() {
            this.err = {};
            this.guardando = true;
            try {
                const f = this.form;
                const cuerpo = { ...f, activo: !!f.activo };
                delete cuerpo.stock_actual;                     // el stock solo se mueve desde Inventario
                if (f.id) {
                    await SC.api.put('/productos/' + f.id, cuerpo);
                    SC.store.ok('Producto actualizado.');
                    this.form = null;
                } else {
                    // Tras crear, el modal sigue abierto en modo edición para poder subir las fotos
                    const r = await SC.api.post('/productos', cuerpo);
                    this.form = { ...this.form, id: r.data.id, fotos: r.data.fotos || [] };
                    SC.store.ok('Producto creado. Ahora puedes añadir sus fotos.');
                }
                this.cargar();
            } catch (e) {
                if (e.status === 422 && e.detalles) this.err = e.detalles;
                SC.store.error(e.mensaje);
            } finally { this.guardando = false; }
        },
        async darBaja() {
            try { await SC.api.delete('/productos/' + this.baja.id); SC.store.ok('Producto dado de baja.'); this.baja = null; this.cargar(); }
            catch (e) { SC.store.error(e.mensaje); this.baja = null; }
        },
        async reactivar(p) {
            try {
                await SC.api.put('/productos/' + p.id, { ...p, fotos: p.fotos, activo: true });
                SC.store.ok('Producto reactivado.');
                this.cargar();
            } catch (e) { SC.store.error(e.mensaje); }
        }
    }
};
