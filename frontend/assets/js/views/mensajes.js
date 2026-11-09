/*
 * Bandeja de mensajes automáticos de WhatsApp (módulo ventas).
 * El sistema redacta solo la confirmación, el comprobante y los avisos de envío/entrega.
 * Funciona en local, sin API de pago: "Abrir WhatsApp" abre el chat del cliente con el texto ya escrito
 * (WhatsApp Web o la app del celular) y solo hay que pulsar Enviar.
 */
SC.views.Mensajes = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>Mensajes de WhatsApp</h1>
            <p class="sub">Confirmaciones, comprobantes y avisos de envío generados automáticamente.</p>
          </div>
          <div v-if="meta" class="stat stat-compacto"><span class="stat-num" :class="meta.pendientes ? 'ambar' : 'verde'">{{ meta.pendientes }}</span><span class="stat-txt">por enviar</span></div>
        </div>

        <div class="mensaje-info">
          <strong>¿Cómo funciona?</strong> Cuando un pago se aprueba o una entrega cambia de estado, el sistema crea el mensaje.
          Pulsa <em>Abrir WhatsApp</em> y se abrirá el chat del cliente con el texto listo; solo falta enviarlo. Se marca como enviado automáticamente.
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="mq">Buscar</label>
            <input id="mq" type="search" v-model="filtros.q" placeholder="Cliente, teléfono o N° de pedido…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="me">Estado</label>
            <select id="me" v-model="filtros.estado" @change="alFiltrar(false)">
              <option value="">Todos</option><option value="Pendiente">Por enviar</option><option value="Enviado">Enviados</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Mensaje</th><th>Pedido</th><th>Cliente</th><th>Creado</th><th>Estado</th><th class="col-acciones">Acciones</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="6" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="6" class="vacio">No hay mensajes con ese filtro.</td></tr>
              <template v-for="m in items" :key="m.id">
                <tr v-show="!cargando">
                  <td data-label="Mensaje"><strong>{{ tipos[m.tipo] }}</strong></td>
                  <td data-label="Pedido"><router-link :to="'/pedidos/' + m.pedido_id">#{{ m.pedido_id }}</router-link></td>
                  <td data-label="Cliente">{{ m.cliente }}<br><small>{{ m.telefono }}</small></td>
                  <td data-label="Creado">{{ fecha(m.creado_en) }}</td>
                  <td data-label="Estado">
                    <span class="badge" :class="m.estado === 'Enviado' ? 'badge-ok' : 'badge-warn'">{{ m.estado === 'Enviado' ? 'Enviado' : 'Por enviar' }}</span>
                    <small v-if="m.enviado_en"><br>{{ fecha(m.enviado_en) }}</small>
                  </td>
                  <td class="col-acciones">
                    <div class="acciones">
                      <button class="btn btn-secundario btn-sm" @click="abierto = abierto === m.id ? 0 : m.id"><sc-icono nombre="ojo" :tam="15"/> {{ abierto === m.id ? 'Ocultar' : 'Ver texto' }}</button>
                      <a v-if="puedeEscribir" :href="m.enlace_wa" target="_blank" rel="noopener" class="btn btn-primario btn-sm" @click="enviado(m)"><sc-icono nombre="whatsapp" :tam="15"/> Abrir WhatsApp</a>
                    </div>
                  </td>
                </tr>
                <tr v-if="abierto === m.id" class="fila-detalle"><td colspan="6"><pre class="texto-whatsapp">{{ m.mensaje }}</pre></td></tr>
              </template>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null, abierto: 0,
                 filtros: { q: '', estado: 'Pendiente' },
                 tipos: { confirmacion: '✅ Confirmación del pedido', comprobante: '🧾 Comprobante', envio: '🚚 Pedido en camino', entrega: '📦 Pedido entregado' } };
    },
    computed: { puedeEscribir() { return SC.store.puedeEscribir('ventas'); } },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/notificaciones', { ...this.filtros, pagina: this.pagina, por_pagina: 10 });
                this.items = r.data; this.meta = r.meta;
                SC.store.mensajesPendientes = r.meta.pendientes;
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
        /** Se marca como enviado al abrir el chat; el enlace se abre igual aunque la marca falle */
        async enviado(m) {
            if (m.estado === 'Enviado') return;
            try {
                await SC.api.patch('/notificaciones/' + m.id + '/enviado');
                m.estado = 'Enviado'; m.enviado_en = new Date().toISOString().slice(0, 19).replace('T', ' ');
                SC.store.mensajesPendientes = Math.max(0, SC.store.mensajesPendientes - 1);
                if (this.meta) this.meta.pendientes = SC.store.mensajesPendientes;
            } catch (e) { /* silencioso */ }
        }
    }
};
