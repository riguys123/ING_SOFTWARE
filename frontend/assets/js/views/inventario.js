/* Inventario (módulo inventario): teléfonos por IMEI; accesorios y complementos por cantidad */
SC.views.Inventario = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Inventario</h1><p class="sub">Teléfonos: cada IMEI es un equipo físico. Accesorios y complementos: se controlan por cantidad.</p></div>
          <div v-if="puedeEscribir" class="acciones">
            <button class="btn btn-secundario" @click="abrirAjuste(0)"><sc-icono nombre="caja" :tam="16"/> Ajustar stock</button>
            <button class="btn btn-primario" @click="abrir(0)"><sc-icono nombre="mas" :tam="16"/> Ingresar equipos</button>
          </div>
        </div>

        <h2 class="subtitulo">Stock por producto</h2>
        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Producto</th><th>Control</th><th>Disponibles</th><th>Vendidos</th><th>Entregados</th><th>Alerta</th><th v-if="puedeEscribir" class="col-acciones"></th></tr></thead>
            <tbody>
              <tr v-if="!stock.length"><td colspan="7" class="vacio">Cargando…</td></tr>
              <tr v-for="s in stock" :key="s.id" :class="{ 'fila-baja': !s.activo }">
                <td data-label="Producto"><strong>{{ s.marca }} {{ s.modelo }}</strong><small v-if="!s.activo"><br>Dado de baja</small></td>
                <td data-label="Control"><small>{{ s.categoria || '—' }}</small><br><span class="badge" :class="s.requiere_imei ? 'badge-info' : 'badge-rol'">{{ s.requiere_imei ? 'Por IMEI' : 'Por cantidad' }}</span></td>
                <td data-label="Disponibles"><strong>{{ s.requiere_imei ? s.disponibles : s.stock_actual }}</strong></td>
                <td data-label="Vendidos">{{ s.requiere_imei ? s.vendidos : '—' }}</td>
                <td data-label="Entregados">{{ s.requiere_imei ? s.entregados : '—' }}</td>
                <td data-label="Alerta">
                  <span v-if="s.stock_actual === 0 && s.activo" class="badge badge-error">Agotado</span>
                  <span v-else-if="s.stock_bajo" class="badge badge-warn">Stock bajo (mín. {{ s.stock_minimo }})</span>
                  <span v-else class="badge badge-ok">Normal</span>
                </td>
                <td v-if="puedeEscribir" class="col-acciones">
                  <button v-if="s.requiere_imei" class="btn btn-secundario btn-sm" @click="abrir(s.id)">Ingresar IMEI</button>
                  <button v-else class="btn btn-secundario btn-sm" @click="abrirAjuste(s.id)">Ajustar stock</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <h2 class="subtitulo">Equipos registrados</h2>
        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="iq">Buscar IMEI</label>
            <input id="iq" type="search" v-model="filtros.q" placeholder="Número de IMEI…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="ip">Producto</label>
            <select id="ip" v-model="filtros.producto_id" @change="alFiltrar(false)">
              <option value="">Todos</option>
              <option v-for="s in conImei" :key="s.id" :value="s.id">{{ s.marca }} {{ s.modelo }}</option>
            </select>
          </div>
          <div class="campo">
            <label for="ie">Estado</label>
            <select id="ie" v-model="filtros.estado" @change="alFiltrar(false)">
              <option value="">Todos</option><option>Disponible</option><option>Vendido</option><option>Entregado</option>
            </select>
          </div>
        </div>
        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>IMEI</th><th>Producto</th><th>Estado</th><th>Pedido</th><th>Ingreso</th><th>Venta</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="6" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="6" class="vacio">No hay equipos con esos filtros.</td></tr>
              <tr v-for="i in items" :key="i.id" v-show="!cargando">
                <td data-label="IMEI" class="celda-imei"><strong>{{ i.numero_imei }}</strong></td>
                <td data-label="Producto">{{ i.marca }} {{ i.modelo }}</td>
                <td data-label="Estado"><sc-estado tipo="imei" :valor="i.estado"/></td>
                <td data-label="Pedido"><router-link v-if="i.pedido_id && verPedidos" :to="'/pedidos/' + i.pedido_id">#{{ i.pedido_id }}</router-link><template v-else>{{ i.pedido_id ? '#' + i.pedido_id : '—' }}</template></td>
                <td data-label="Ingreso">{{ fecha(i.ingresado_en) }}</td>
                <td data-label="Venta">{{ fecha(i.vendido_en) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>

        <sc-modal :abierto="modal" grande titulo="Ingresar equipos al almacén" texto-confirmar="Registrar IMEI" :cargando="guardando"
                  @confirmar="registrar" @cancelar="modal = false">
          <div class="campo" :class="{ 'con-error': err }">
            <label for="mp">Producto</label>
            <select id="mp" v-model="ingreso.producto_id">
              <option :value="0" disabled>Selecciona un producto…</option>
              <option v-for="s in conImei" :key="s.id" :value="s.id">{{ s.marca }} {{ s.modelo }}</option>
            </select>
          </div>
          <div class="campo" :class="{ 'con-error': err }">
            <label for="mi">IMEI (uno por línea, 15 dígitos)</label>
            <textarea id="mi" rows="6" v-model="ingreso.texto" placeholder="351004110390132&#10;351004110390264"></textarea>
            <span v-if="err" class="error-campo">{{ err }}</span>
            <small class="ayuda">Se valida el largo (15 dígitos) y el dígito de control. Los repetidos se rechazan.</small>
          </div>
          <div v-if="resultado" class="mensaje-info">
            <div v-if="resultado.invalidos.length"><strong>Inválidos ({{ resultado.invalidos.length }}):</strong> {{ resultado.invalidos.join(', ') }}</div>
            <div v-if="resultado.duplicados.length"><strong>Ya registrados ({{ resultado.duplicados.length }}):</strong> {{ resultado.duplicados.join(', ') }}</div>
          </div>
        </sc-modal>

        <sc-modal :abierto="modalAjuste" titulo="Ajustar stock (accesorios y complementos)" texto-confirmar="Aplicar ajuste" :cargando="guardando"
                  @confirmar="ajustar" @cancelar="modalAjuste = false">
          <div class="campo" :class="{ 'con-error': errAj.producto_id }">
            <label for="ap">Producto</label>
            <select id="ap" v-model="ajuste.producto_id">
              <option :value="0" disabled>Selecciona un producto…</option>
              <option v-for="s in sinImei" :key="s.id" :value="s.id">{{ s.marca }} {{ s.modelo }} (hay {{ s.stock_actual }})</option>
            </select>
            <span v-if="errAj.producto_id" class="error-campo">{{ errAj.producto_id }}</span>
          </div>
          <div class="campo" :class="{ 'con-error': errAj.cantidad }">
            <label for="ac">Cantidad (positiva = entra al almacén · negativa = sale)</label>
            <input id="ac" type="number" v-model.number="ajuste.cantidad" placeholder="Ej.: 20 o -2">
            <span v-if="errAj.cantidad" class="error-campo">{{ errAj.cantidad }}</span>
          </div>
          <div class="campo" :class="{ 'con-error': errAj.motivo }">
            <label for="am">Motivo</label>
            <input id="am" type="text" v-model="ajuste.motivo" placeholder="Compra a proveedor, producto dañado, conteo…">
            <span v-if="errAj.motivo" class="error-campo">{{ errAj.motivo }}</span>
          </div>
        </sc-modal>
      </div>`,
    data() {
        return { modalAjuste: false, errAj: {}, ajuste: { producto_id: 0, cantidad: null, motivo: '' },
                 stock: [], items: [], meta: null, cargando: true, pagina: 1, temporizador: null,
                 filtros: { q: '', producto_id: '', estado: '' },
                 modal: false, guardando: false, err: '', resultado: null, ingreso: { producto_id: 0, texto: '' } };
    },
    computed: {
        puedeEscribir() { return SC.store.puedeEscribir('inventario'); },
        verPedidos() { return !!SC.store.nivel('ventas'); },
        conImei() { return this.stock.filter(s => s.requiere_imei); },
        sinImei() { return this.stock.filter(s => !s.requiere_imei && s.activo); }
    },
    created() { this.cargarStock(); this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargarStock() {
            try { this.stock = (await SC.api.get('/inventario/stock')).data; } catch (e) { SC.store.error(e.mensaje); }
        },
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/inventario/imeis', { ...this.filtros, pagina: this.pagina, por_pagina: 12 });
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
        abrirAjuste(productoId) {
            this.ajuste = { producto_id: productoId, cantidad: null, motivo: '' };
            this.errAj = {}; this.modalAjuste = true;
        },
        async ajustar() {
            this.errAj = {};
            this.guardando = true;
            try {
                const r = (await SC.api.post('/inventario/ajuste', this.ajuste)).data;
                SC.store.ok('Stock actualizado: ahora hay ' + r.stock_actual + '.');
                this.modalAjuste = false;
                this.cargarStock();
            } catch (e) {
                if (e.status === 422 && e.detalles) this.errAj = e.detalles;
                else this.errAj = { cantidad: e.mensaje };
            } finally { this.guardando = false; }
        },
        abrir(productoId) {
            this.ingreso = { producto_id: productoId, texto: '' };
            this.err = ''; this.resultado = null; this.modal = true;
        },
        async registrar() {
            this.err = ''; this.resultado = null;
            if (!this.ingreso.producto_id) { this.err = 'Selecciona un producto.'; return; }
            if (!this.ingreso.texto.trim()) { this.err = 'Ingresa al menos un IMEI.'; return; }
            this.guardando = true;
            try {
                const r = (await SC.api.post('/inventario/imeis', { producto_id: this.ingreso.producto_id, imeis: this.ingreso.texto })).data;
                SC.store.ok(r.insertados + ' equipo(s) registrado(s).');
                this.cargarStock(); this.cargar();
                if (!r.invalidos.length && !r.duplicados.length) { this.modal = false; }
                else {                                  // deja en el cuadro solo los que fallaron para corregirlos
                    this.resultado = r;
                    this.ingreso.texto = r.invalidos.join('\n');
                }
            } catch (e) {
                this.err = e.mensaje;
                if (e.status === 422 && e.codigo === 'sin_imeis_validos') this.resultado = null;
            } finally { this.guardando = false; }
        }
    }
};
