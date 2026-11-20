/* Pagos (módulo cobranzas) y Despachos (módulo distribucion) */
SC.views.Pagos = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Pagos</h1><p class="sub">Tarjeta (pasarela simulada), y QR y depósito que se verifican a mano</p></div>
          <div v-if="meta" class="stat stat-compacto"><span class="stat-num verde">{{ $bs(meta.total_aprobado) }}</span><span class="stat-txt">Cobrado (según filtros)</span></div>
        </div>

        <div v-if="porVerificar" class="mensaje-aviso" role="status">
          <strong>{{ porVerificar }} cobro(s) por verificar.</strong> Revisa el comprobante, confirma que el dinero llegó a la cuenta y pulsa <em>Confirmar</em>; recién entonces se descuenta el stock y se prepara el pedido.
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="gq">Buscar</label>
            <input id="gq" type="search" v-model="filtros.q" placeholder="Referencia, N° de operación, cliente o pedido…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="ge">Estado</label>
            <select id="ge" v-model="filtros.estado" @change="alFiltrar(false)"><option value="">Todos</option><option value="Por_verificar">Por verificar</option><option>Aprobado</option><option>Rechazado</option></select>
          </div>
          <div class="campo">
            <label for="gm">Método</label>
            <select id="gm" v-model="filtros.metodo" @change="alFiltrar(false)"><option value="">Todos</option><option>Tarjeta</option><option>QR</option><option>Transferencia</option><option>PayPal</option></select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Método</th><th>Monto</th><th>Estado</th><th>Comprobante</th><th v-if="puedeEscribir" class="col-acciones">Acciones</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td :colspan="puedeEscribir ? 8 : 7" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td :colspan="puedeEscribir ? 8 : 7" class="vacio">No hay pagos con esos filtros.</td></tr>
              <tr v-for="g in items" :key="g.id" v-show="!cargando" :class="{ 'fila-pendiente': g.estado === 'Por_verificar' }">
                <td data-label="Fecha">{{ fecha(g.fecha) }}</td>
                <td data-label="Pedido"><router-link v-if="verPedidos" :to="'/pedidos/' + g.pedido_id">#{{ g.pedido_id }}</router-link><template v-else>#{{ g.pedido_id }}</template></td>
                <td data-label="Cliente">{{ g.cliente_nombre }}<br><small>{{ g.cliente_email }}</small></td>
                <td data-label="Método">{{ g.metodo }}<template v-if="g.tarjeta_ultimos4"> •••• {{ g.tarjeta_ultimos4 }}</template></td>
                <td data-label="Monto"><strong>{{ $bs(g.monto) }}</strong></td>
                <td data-label="Estado">
                  <sc-estado tipo="pago" :valor="g.estado"/>
                  <small v-if="g.motivo_rechazo" class="aviso-stock"><br>{{ g.motivo_rechazo }}</small>
                  <small v-if="g.verificado_por"><br>por {{ g.verificado_por }}</small>
                </td>
                <td data-label="Comprobante" class="celda-imei">
                  <template v-if="g.comprobante_ref">N° {{ g.comprobante_ref }}</template><template v-else>{{ g.referencia }}</template>
                  <br v-if="g.comprobante_img"><a v-if="g.comprobante_img" href="#" class="enlace" @click.prevent="foto = g.comprobante_img">Ver foto</a>
                </td>
                <td v-if="puedeEscribir" class="col-acciones">
                  <div v-if="g.estado === 'Por_verificar'" class="acciones">
                    <button class="btn btn-primario btn-sm" :disabled="ocupado === g.id" @click="confirmando = g">Confirmar</button>
                    <button class="btn btn-peligro-suave btn-sm" :disabled="ocupado === g.id" @click="abrirRechazo(g)">Rechazar</button>
                  </div>
                  <span v-else class="sub">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>

        <sc-modal :abierto="!!confirmando" titulo="Confirmar el cobro" texto-confirmar="Sí, el dinero llegó" :cargando="ocupado === (confirmando && confirmando.id)"
                  @confirmar="confirmar" @cancelar="confirmando = null">
          <p v-if="confirmando">Pedido <strong>#{{ confirmando.pedido_id }}</strong> de <strong>{{ confirmando.cliente_nombre }}</strong> por <strong>{{ $bs(confirmando.monto) }}</strong> ({{ confirmando.metodo }}, N° {{ confirmando.comprobante_ref }}).</p>
          <p class="sub">Al confirmar se descuenta el stock, se asignan los IMEI, se crea el despacho y se generan los mensajes de WhatsApp al cliente.</p>
        </sc-modal>

        <sc-modal :abierto="!!rechazando" titulo="Rechazar el cobro" texto-confirmar="Rechazar pago" peligro :cargando="ocupado === (rechazando && rechazando.id)"
                  @confirmar="rechazar" @cancelar="rechazando = null">
          <p v-if="rechazando">Pedido <strong>#{{ rechazando.pedido_id }}</strong> · {{ $bs(rechazando.monto) }}. El cliente podrá enviar otro comprobante.</p>
          <div class="campo" :class="{ 'con-error': errMotivo }">
            <label for="mot">Motivo (lo verá el cliente)</label>
            <input id="mot" type="text" v-model.trim="motivo" maxlength="200" placeholder="Ej: no encontramos el depósito en la cuenta">
            <span v-if="errMotivo" class="error-campo">{{ errMotivo }}</span>
          </div>
        </sc-modal>

        <sc-modal :abierto="!!foto" titulo="Comprobante adjunto" texto-confirmar="Cerrar" grande @confirmar="foto = ''" @cancelar="foto = ''">
          <img v-if="foto" :src="foto" class="comprobante-grande" alt="Comprobante enviado por el cliente">
        </sc-modal>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, porVerificar: 0, ocupado: 0,
                 confirmando: null, rechazando: null, motivo: '', errMotivo: '', foto: '',
                 filtros: { q: '', estado: this.$route.query.estado || '', metodo: '' } };
    },
    computed: {
        verPedidos() { return !!SC.store.nivel('ventas'); },
        puedeEscribir() { return SC.store.puedeEscribir('cobranzas'); }
    },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/pagos', { ...this.filtros, pagina: this.pagina, por_pagina: 12 });
                this.items = r.data; this.meta = r.meta;
                const p = await SC.api.get('/pagos', { estado: 'Por_verificar', por_pagina: 1 });
                this.porVerificar = p.meta.total;
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
        async confirmar() {
            const g = this.confirmando;
            this.ocupado = g.id;
            try {
                await SC.api.post('/pagos/' + g.id + '/confirmar');
                SC.store.ok('Pago confirmado: el pedido #' + g.pedido_id + ' pasa a preparación.');
                this.confirmando = null;
                SC.store.contarMensajes();
                this.cargar();
            } catch (e) { SC.store.error(e.mensaje); this.confirmando = null; this.cargar(); }
            finally { this.ocupado = 0; }
        },
        abrirRechazo(g) { this.rechazando = g; this.motivo = ''; this.errMotivo = ''; },
        async rechazar() {
            const g = this.rechazando;
            this.errMotivo = '';
            if (this.motivo.length < 5) { this.errMotivo = 'Escribe un motivo (mínimo 5 caracteres).'; return; }
            this.ocupado = g.id;
            try {
                await SC.api.post('/pagos/' + g.id + '/rechazar', { motivo: this.motivo });
                SC.store.ok('Pago rechazado. El cliente podrá enviar otro comprobante.');
                this.rechazando = null;
                this.cargar();
            } catch (e) { this.errMotivo = e.mensaje; }
            finally { this.ocupado = 0; }
        }
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
              <option value="">Todos</option><option v-if="veEmpaque" value="En_preparacion">En preparación</option><option value="Empacado">Empacado</option><option value="En_camino">En camino</option><option value="Entregado">Entregado</option><option value="Finalizado">Finalizado</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Guía</th><th>Cliente y destino</th><th>Productos (IMEI)</th><th>Estado</th><th>Repartidor</th><th class="col-acciones">Acción</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="6" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="6" class="vacio">No hay despachos con esos filtros.</td></tr>
              <tr v-for="d in items" :key="d.id" v-show="!cargando">
                <td data-label="Guía"><strong>{{ d.codigo_guia }}</strong><br><small>Pedido #{{ d.pedido_id }} · {{ fecha(d.creado_en) }}</small></td>
                <td data-label="Destino"><strong>{{ d.cliente }}</strong><br><small>{{ d.direccion_envio }}, {{ d.ciudad }}<br>Tel. {{ d.telefono }}</small></td>
                <td data-label="Equipos">
                  <div v-for="(e, k) in d.equipos" :key="k" class="linea-equipo">{{ e.marca }} {{ e.modelo }}<br><span v-if="e.imei" class="celda-imei">{{ e.imei }}</span><small v-else>{{ e.cantidad }} unidad(es) · sin IMEI</small></div>
                </td>
                <td data-label="Estado">
                  <sc-estado tipo="envio" :valor="d.estado"/>
                  <small v-if="d.empacado_en"><br>Empacó: {{ d.empacador }} · {{ d.bultos }} bulto(s)</small>
                  <small v-if="d.receptor"><br>Recibió: {{ d.receptor }}</small>
                  <small v-if="d.calificacion"><br>{{ '★'.repeat(d.calificacion) }}{{ '☆'.repeat(5 - d.calificacion) }}</small>
                </td>
                <td data-label="Repartidor">{{ d.repartidor || '—' }}</td>
                <td class="col-acciones">
                  <template v-if="d.estado === 'En_preparacion'">
                    <router-link v-if="veEmpaque && puedeEmpacar" to="/preparacion" class="btn btn-secundario btn-sm">Ir a empaque</router-link>
                    <span v-else class="sub">Esperando empaque</span>
                  </template>
                  <button v-else-if="d.estado === 'Empacado' && puedeEscribir" class="btn btn-primario btn-sm" :disabled="ocupado === d.id" @click="avanzar(d, 'En_camino')">Salir a entregar</button>
                  <button v-else-if="d.estado === 'En_camino' && puedeEscribir" class="btn btn-primario btn-sm" :disabled="ocupado === d.id" @click="entregando = d; receptor = d.cliente; nota = ''">Registrar entrega</button>
                  <template v-else-if="d.estado === 'Entregado'">
                    <button v-if="puedeCerrar" class="btn btn-secundario btn-sm" :disabled="ocupado === d.id" @click="cerrar(d)">Cerrar pedido</button>
                    <span v-else class="sub">Esperando confirmación del cliente</span>
                  </template>
                  <span v-else-if="d.estado === 'Finalizado'" class="sub">Finalizado</span>
                  <span v-else class="sub">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>

        <sc-modal :abierto="!!entregando" titulo="Registrar la entrega" texto-confirmar="Confirmar entrega" :cargando="!!entregando && ocupado === entregando.id"
                  @confirmar="confirmarEntrega" @cancelar="entregando = null">
          <p v-if="entregando">Pedido <strong>#{{ entregando.pedido_id }}</strong> · {{ entregando.direccion_envio }}, {{ entregando.ciudad }}</p>
          <div class="campo"><label for="rec">¿Quién recibió el pedido?</label><input id="rec" type="text" v-model.trim="receptor" maxlength="80" placeholder="Nombre de quien recibe"></div>
          <div class="campo"><label for="nen">Observación (opcional)</label><input id="nen" type="text" v-model.trim="nota" maxlength="200" placeholder="Ej: lo recibió el portero"></div>
          <p class="sub">Los IMEI quedan como Entregado y el cliente recibe su mensaje. Luego el cliente confirma la recepción y el pedido pasa a Finalizado.</p>
        </sc-modal>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, ocupado: 0, entregando: null, receptor: '', nota: '', filtros: { q: '', estado: '' } };
    },
    computed: {
        puedeEscribir() { return SC.store.puedeEscribir('distribucion'); },
        veEmpaque() { return !!SC.store.nivel('preparacion'); },
        puedeEmpacar() { return SC.store.puedeEscribir('preparacion'); },
        puedeCerrar() { return SC.store.puedeEscribir('ventas'); }
    },
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
        async avanzar(d, estado, extra) {
            this.ocupado = d.id;
            try {
                const nuevo = (await SC.api.patch('/logistica/despachos/' + d.id + '/estado', { estado, ...(extra || {}) })).data;
                Object.assign(d, nuevo);
                SC.store.ok(estado === 'Entregado' ? 'Entrega registrada: los IMEI quedaron como Entregado.' : 'Despacho en camino.');
                return true;
            } catch (e) { SC.store.error(e.mensaje); return false; }
            finally { this.ocupado = 0; }
        },
        async confirmarEntrega() {
            if (await this.avanzar(this.entregando, 'Entregado', { receptor: this.receptor, nota: this.nota })) this.entregando = null;
        },
        /** Ventas cierra el pedido cuando el cliente no confirma por su cuenta */
        async cerrar(d) {
            this.ocupado = d.id;
            try {
                Object.assign(d, (await SC.api.post('/logistica/despachos/' + d.id + '/finalizar')).data);
                SC.store.ok('Pedido finalizado.');
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.ocupado = 0; }
        }
    }
};


/**
 * Preparación y empaque (módulo "preparacion", rol Empaque).
 * Cada pedido pagado llega aquí: se verifica cada producto (y su IMEI), se indica cuántos bultos se armaron
 * y se marca como empacado. Recién entonces el repartidor puede recogerlo.
 */
SC.views.Preparacion = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div><h1>Preparación y empaque</h1><p class="sub">Verifica cada equipo con su IMEI, arma los bultos y márcalo como empacado para que salga a reparto</p></div>
        </div>

        <div class="segmentado" role="tablist" aria-label="Estado">
          <button :class="{ activo: tab === 'En_preparacion' }" @click="cambiarTab('En_preparacion')">Por preparar <span class="insignia-num">{{ totales.En_preparacion }}</span></button>
          <button :class="{ activo: tab === 'Empacado' }" @click="cambiarTab('Empacado')">Empacados <span class="insignia-num">{{ totales.Empacado }}</span></button>
        </div>

        <p v-if="cargando" class="sub">Cargando…</p>
        <p v-else-if="!items.length" class="tarjeta vacio-caja">{{ tab === 'En_preparacion' ? 'No hay pedidos por preparar. ¡Todo al día!' : 'No hay pedidos empacados esperando al repartidor.' }}</p>

        <div v-else class="prep-grid">
          <article v-for="d in items" :key="d.id" class="tarjeta prep-tarjeta">
            <header class="prep-cab">
              <div><strong>{{ d.codigo_guia }}</strong><small><br>Pedido #{{ d.pedido_id }} · {{ fecha(d.creado_en) }}</small></div>
              <sc-estado tipo="envio" :valor="d.estado"/>
            </header>
            <p class="sub">{{ d.cliente }} · {{ d.direccion_envio }}, {{ d.ciudad }}</p>

            <ul class="prep-lista">
              <li v-for="(e, k) in d.equipos" :key="k">
                <label class="check-linea">
                  <input v-if="tab === 'En_preparacion' && puedeEscribir" type="checkbox" v-model="marcas[d.id + '-' + k]">
                  <span><strong>{{ e.marca }} {{ e.modelo }}</strong>
                    <small v-if="e.imei"><br>IMEI <span class="celda-imei">{{ e.imei }}</span></small>
                    <small v-else><br>{{ e.cantidad }} unidad(es) · sin IMEI</small>
                  </span>
                </label>
              </li>
            </ul>

            <template v-if="tab === 'En_preparacion' && puedeEscribir">
              <div class="grid-2">
                <div class="campo"><label :for="'bu' + d.id">Bultos</label><input :id="'bu' + d.id" type="number" min="1" max="20" v-model.number="bultos[d.id]"></div>
                <div class="campo"><label :for="'no' + d.id">Nota (opcional)</label><input :id="'no' + d.id" type="text" maxlength="200" v-model.trim="notas[d.id]" placeholder="Ej: frágil"></div>
              </div>
              <button class="btn btn-primario btn-bloque" :disabled="ocupado === d.id || !completo(d)" @click="empacar(d)">
                {{ ocupado === d.id ? 'Guardando…' : (completo(d) ? 'Marcar como empacado' : 'Verifica todos los equipos') }}
              </button>
            </template>
            <p v-else-if="tab === 'Empacado'" class="sub">Empacó {{ d.empacador }} · {{ d.bultos }} bulto(s) · {{ fecha(d.empacado_en) }}<template v-if="d.nota_empaque"> · {{ d.nota_empaque }}</template></p>
          </article>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { tab: 'En_preparacion', items: [], meta: null, cargando: true, pagina: 1, ocupado: 0,
                 marcas: {}, bultos: {}, notas: {}, totales: { En_preparacion: 0, Empacado: 0 } };
    },
    computed: { puedeEscribir() { return SC.store.puedeEscribir('preparacion'); } },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        completo(d) { return d.equipos.every((e, k) => this.marcas[d.id + '-' + k]); },
        cambiarTab(t) { this.tab = t; this.pagina = 1; this.cargar(); },
        irAPagina(p) { this.pagina = p; this.cargar(); },
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/preparacion/despachos', { estado: this.tab, pagina: this.pagina, por_pagina: 6 });
                this.items = r.data; this.meta = r.meta;
                this.items.forEach(d => { if (!this.bultos[d.id]) this.bultos[d.id] = 1; });
                this.totales[this.tab] = r.meta.total;
                const otro = this.tab === 'En_preparacion' ? 'Empacado' : 'En_preparacion';
                this.totales[otro] = (await SC.api.get('/preparacion/despachos', { estado: otro, por_pagina: 1 })).meta.total;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        async empacar(d) {
            this.ocupado = d.id;
            try {
                await SC.api.patch('/logistica/despachos/' + d.id + '/empacar', { verificado: true, bultos: this.bultos[d.id] || 1, nota: this.notas[d.id] || '' });
                SC.store.ok('Pedido empacado. El repartidor ya puede recogerlo.');
                await this.cargar();
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.ocupado = 0; }
        }
    }
};
