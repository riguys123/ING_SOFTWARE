/* Pagos (módulo cobranzas) y Despachos (módulo distribucion) */
SC.views.Pagos = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Pagos</h1><p class="sub">Transacciones de la pasarela de pagos (simulada)</p></div>
          <div v-if="meta" class="stat stat-compacto"><span class="stat-num verde">{{ $bs(meta.total_aprobado) }}</span><span class="stat-txt">Cobrado (según filtros)</span></div>
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="gq">Buscar</label>
            <input id="gq" type="search" v-model="filtros.q" placeholder="Referencia, cliente o N° de pedido…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="ge">Estado</label>
            <select id="ge" v-model="filtros.estado" @change="alFiltrar(false)"><option value="">Todos</option><option>Aprobado</option><option>Rechazado</option></select>
          </div>
          <div class="campo">
            <label for="gm">Método</label>
            <select id="gm" v-model="filtros.metodo" @change="alFiltrar(false)"><option value="">Todos</option><option>Tarjeta</option><option>QR</option><option>Transferencia</option></select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Método</th><th>Monto</th><th>Estado</th><th>Referencia</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="7" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="7" class="vacio">No hay pagos con esos filtros.</td></tr>
              <tr v-for="g in items" :key="g.id" v-show="!cargando">
                <td data-label="Fecha">{{ fecha(g.fecha) }}</td>
                <td data-label="Pedido"><router-link v-if="verPedidos" :to="'/pedidos/' + g.pedido_id">#{{ g.pedido_id }}</router-link><template v-else>#{{ g.pedido_id }}</template></td>
                <td data-label="Cliente">{{ g.cliente_nombre }}<br><small>{{ g.cliente_email }}</small></td>
                <td data-label="Método">{{ g.metodo }}<template v-if="g.tarjeta_ultimos4"> •••• {{ g.tarjeta_ultimos4 }}</template></td>
                <td data-label="Monto"><strong>{{ $bs(g.monto) }}</strong></td>
                <td data-label="Estado"><sc-estado tipo="pago" :valor="g.estado"/><small v-if="g.motivo_rechazo" class="aviso-stock"><br>{{ g.motivo_rechazo }}</small></td>
                <td data-label="Referencia" class="celda-imei">{{ g.referencia }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, filtros: { q: '', estado: '', metodo: '' } };
    },
    computed: { verPedidos() { return !!SC.store.nivel('ventas'); } },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/pagos', { ...this.filtros, pagina: this.pagina, por_pagina: 12 });
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
        irAPagina(p) { this.pagina = p; this.cargar(); }
    }
};

SC.views.Despachos = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Despachos</h1><p class="sub">Órdenes de despacho generadas al confirmarse el pago, con el IMEI de cada equipo</p></div>
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="dq">Buscar</label>
            <input id="dq" type="search" v-model="filtros.q" placeholder="Guía, cliente, N° de pedido o IMEI…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="de">Estado</label>
            <select id="de" v-model="filtros.estado" @change="alFiltrar(false)">
              <option value="">Todos</option><option value="En_preparacion">En preparación</option><option value="En_camino">En camino</option><option value="Entregado">Entregado</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Guía</th><th>Cliente y destino</th><th>Productos (IMEI)</th><th>Estado</th><th>Repartidor</th><th v-if="puedeEscribir" class="col-acciones">Acción</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="6" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="6" class="vacio">No hay despachos con esos filtros.</td></tr>
              <tr v-for="d in items" :key="d.id" v-show="!cargando">
                <td data-label="Guía"><strong>{{ d.codigo_guia }}</strong><br><small>Pedido #{{ d.pedido_id }} · {{ fecha(d.creado_en) }}</small></td>
                <td data-label="Destino"><strong>{{ d.cliente }}</strong><br><small>{{ d.direccion_envio }}, {{ d.ciudad }}<br>Tel. {{ d.telefono }}</small></td>
                <td data-label="Equipos">
                  <div v-for="(e, k) in d.equipos" :key="k" class="linea-equipo">{{ e.marca }} {{ e.modelo }}<br><span v-if="e.imei" class="celda-imei">{{ e.imei }}</span><small v-else>{{ e.cantidad }} unidad(es) · sin IMEI</small></div>
                </td>
                <td data-label="Estado"><sc-estado tipo="envio" :valor="d.estado"/></td>
                <td data-label="Repartidor">{{ d.repartidor || '—' }}</td>
                <td v-if="puedeEscribir" class="col-acciones">
                  <button v-if="d.estado === 'En_preparacion'" class="btn btn-primario btn-sm" :disabled="ocupado === d.id" @click="avanzar(d, 'En_camino')">Marcar en camino</button>
                  <button v-else-if="d.estado === 'En_camino'" class="btn btn-primario btn-sm" :disabled="ocupado === d.id" @click="avanzar(d, 'Entregado')">Marcar entregado</button>
                  <span v-else class="sub">Completado</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, ocupado: 0, filtros: { q: '', estado: '' } };
    },
    computed: { puedeEscribir() { return SC.store.puedeEscribir('distribucion'); } },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/logistica/despachos', { ...this.filtros, pagina: this.pagina, por_pagina: 8 });
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
        async avanzar(d, estado) {
            this.ocupado = d.id;
            try {
                const nuevo = (await SC.api.patch('/logistica/despachos/' + d.id + '/estado', { estado })).data;
                Object.assign(d, nuevo);
                SC.store.ok(estado === 'Entregado' ? 'Entrega confirmada: los IMEI quedaron como Entregado.' : 'Despacho en camino.');
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.ocupado = 0; }
        }
    }
};
