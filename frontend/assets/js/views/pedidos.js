/* Pedidos: lista (cliente: "Mis pedidos" · personal: todos los pedidos) y detalle con IMEI */
SC.views.Pedidos = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>{{ staff ? 'Pedidos' : 'Mis pedidos' }}</h1>
            <p class="sub">{{ staff ? 'Todos los pedidos de la tienda. Puedes buscar por número, cliente o IMEI.' : 'Historial y estado de tus compras' }}</p>
          </div>
        </div>

        <div class="filtros">
          <div v-if="staff" class="campo campo-buscar">
            <label for="pq">Buscar</label>
            <input id="pq" type="search" v-model="filtros.q" placeholder="N° de pedido, cliente o IMEI…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="pp">Pago</label>
            <select id="pp" v-model="filtros.estado_pago" @change="alFiltrar(false)">
              <option value="">Todos</option><option>Pendiente</option><option>Pagado</option><option>Rechazado</option>
            </select>
          </div>
          <div class="campo">
            <label for="pe">Envío</label>
            <select id="pe" v-model="filtros.estado_envio" @change="alFiltrar(false)">
              <option value="">Todos</option>
              <option value="Pendiente">Sin despachar</option><option value="En_preparacion">En preparación</option>
              <option value="En_camino">En camino</option><option value="Entregado">Entregado</option><option value="Cancelado">Cancelado</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead>
              <tr><th>Pedido</th><th>Fecha</th><th v-if="staff">Cliente</th><th>Productos</th><th v-if="staff">IMEI asignados</th><th>Total</th><th>Pago</th><th>Envío</th><th class="col-acciones"></th></tr>
            </thead>
            <tbody>
              <tr v-if="cargando"><td colspan="9" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length">
                <td colspan="9" class="vacio">
                  No hay pedidos con esos filtros.
                  <template v-if="!staff"> <router-link to="/tienda">Ir al catálogo</router-link></template>
                </td>
              </tr>
              <tr v-for="p in items" :key="p.id" v-show="!cargando">
                <td data-label="Pedido"><strong>#{{ p.id }}</strong></td>
                <td data-label="Fecha">{{ fecha(p.fecha) }}</td>
                <td v-if="staff" data-label="Cliente">{{ p.cliente_nombre }}<br><small>{{ p.cliente_email }}</small></td>
                <td data-label="Equipos">{{ p.unidades }}</td>
                <td v-if="staff" data-label="IMEI" class="celda-imei">{{ p.imeis || '—' }}</td>
                <td data-label="Total"><strong>{{ $bs(p.total) }}</strong></td>
                <td data-label="Pago"><sc-estado tipo="pago" :valor="p.estado_pago"/></td>
                <td data-label="Envío"><sc-estado tipo="envio" :valor="p.estado_envio"/></td>
                <td class="col-acciones">
                  <router-link :to="base + p.id" class="btn btn-secundario btn-sm"><sc-icono nombre="ojo" :tam="15"/> Ver</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null,
                 filtros: { q: this.$route.query.q || '', estado_pago: this.$route.query.estado_pago || '', estado_envio: this.$route.query.estado_envio || '' } };
    },
    computed: {
        staff() { return !SC.store.esCliente; },
        base() { return this.staff ? '/pedidos/' : '/mis-pedidos/'; }
    },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/pedidos', { ...this.filtros, pagina: this.pagina, por_pagina: 10 });
                this.items = r.data;
                this.meta = r.meta;
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

SC.views.PedidoDetalle = {
    template: `
      <div>
        <router-link :to="staff ? '/pedidos' : '/mis-pedidos'" class="volver"><sc-icono nombre="volver" :tam="16"/> {{ staff ? 'Pedidos' : 'Mis pedidos' }}</router-link>
        <p v-if="cargando" class="sub">Cargando…</p>
        <p v-else-if="!p" class="tarjeta vacio-caja">Este pedido no existe.</p>

        <div v-else>
          <div v-if="$route.query.exito" class="mensaje-ok">
            <strong>¡Gracias por tu compra!</strong> Tu pago fue aprobado y ya estamos preparando tu pedido.
            Tu confirmación y tu comprobante están listos; si compraste un celular, abajo ves su IMEI (guárdalo para la garantía).
            <div class="formulario-acciones">
              <router-link :to="'/comprobante/' + p.id" class="btn btn-primario btn-sm"><sc-icono nombre="imprimir" :tam="15"/> Ver comprobante</router-link>
            </div>
          </div>

          <div class="encabezado-pagina">
            <div>
              <h1>Pedido #{{ p.id }}</h1>
              <p class="sub">{{ fecha(p.fecha) }}<template v-if="staff"> · {{ p.cliente_nombre }} ({{ p.cliente_email }})</template></p>
            </div>
            <div class="acciones">
              <router-link v-if="p.estado_pago === 'Pagado'" :to="'/comprobante/' + p.id" class="btn btn-secundario btn-sm"><sc-icono nombre="imprimir" :tam="15"/> Comprobante</router-link>
              <sc-estado tipo="pago" :valor="p.estado_pago"/>
              <sc-estado tipo="envio" :valor="p.estado_envio"/>
            </div>
          </div>

          <ol v-if="p.estado_envio !== 'Cancelado'" class="pasos" aria-label="Estado del pedido">
            <li v-for="(s, k) in pasos" :key="s.texto" :class="{ hecho: k < avance, actual: k === avance - 1 }">
              <span class="paso-punto">{{ k < avance ? '✓' : k + 1 }}</span><span class="paso-texto">{{ s.texto }}</span>
            </li>
          </ol>

          <div class="grid-2">
            <section class="tarjeta">
              <h2>Equipos</h2>
              <div class="tabla-contenedor sin-borde">
                <table class="tabla">
                  <thead><tr><th>Producto</th><th>IMEI asignado</th><th>Precio</th></tr></thead>
                  <tbody>
                    <tr v-for="i in p.items" :key="i.id">
                      <td data-label="Producto"><strong>{{ i.marca }} {{ i.modelo }}</strong><small v-if="i.cantidad > 1"><br>{{ i.cantidad }} unidades</small></td>
                      <td data-label="IMEI" class="celda-imei">
                        <template v-if="i.imei_asignado">{{ i.imei_asignado }}</template>
                        <small v-else-if="!i.requiere_imei">No lleva IMEI</small>
                        <small v-else>Se asigna al confirmarse el pago</small>
                      </td>
                      <td data-label="Precio">
                        {{ $bs(i.precio_unitario * i.cantidad) }}
                        <small v-if="i.descuento_pct > 0"><br>-{{ i.descuento_pct }}% promo</small>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="resumen-fila resumen-total"><span>Total</span><strong>{{ $bs(p.total) }}</strong></div>
            </section>

            <div>
              <section class="tarjeta">
                <h2>Entrega</h2>
                <dl class="datos datos-ancho">
                  <dt>Dirección</dt><dd>{{ p.direccion_envio }}</dd>
                  <dt>Ciudad</dt><dd>{{ p.ciudad }}</dd>
                  <dt>Teléfono</dt><dd>{{ p.telefono }}</dd>
                  <template v-if="p.notas"><dt>Notas</dt><dd>{{ p.notas }}</dd></template>
                  <template v-if="p.despacho">
                    <dt>Guía</dt><dd><strong>{{ p.despacho.codigo_guia }}</strong></dd>
                    <dt>Repartidor</dt><dd>{{ p.despacho.repartidor || 'Por asignar' }}</dd>
                    <template v-if="p.despacho.entregado_en"><dt>Entregado</dt><dd>{{ fecha(p.despacho.entregado_en) }}</dd></template>
                  </template>
                </dl>
              </section>

              <section v-if="staff && p.mensajes && p.mensajes.length" class="tarjeta">
                <h2>Mensajes automáticos</h2>
                <p class="sub">Generados por el sistema. Ábrelos en WhatsApp con un clic.</p>
                <ul class="lista-pagos">
                  <li v-for="m in p.mensajes" :key="m.id">
                    <div><strong>{{ tiposMensaje[m.tipo] }}</strong><small><br>{{ fecha(m.creado_en) }}</small></div>
                    <div class="lista-pagos-der">
                      <span class="badge" :class="m.estado === 'Enviado' ? 'badge-ok' : 'badge-warn'">{{ m.estado }}</span>
                      <a :href="m.enlace_wa" target="_blank" rel="noopener" class="btn btn-secundario btn-sm" @click="marcarEnviado(m)"><sc-icono nombre="whatsapp" :tam="15"/> Abrir</a>
                    </div>
                  </li>
                </ul>
              </section>

              <section class="tarjeta">
                <h2>Pagos</h2>
                <p v-if="!p.pagos.length" class="sub">Aún no hay intentos de pago.</p>
                <ul class="lista-pagos">
                  <li v-for="g in p.pagos" :key="g.id">
                    <div>
                      <strong>{{ g.metodo }}<template v-if="g.tarjeta_ultimos4"> •••• {{ g.tarjeta_ultimos4 }}</template></strong>
                      <small><br>{{ fecha(g.fecha) }} · ref. {{ g.referencia }}</small>
                      <small v-if="g.motivo_rechazo" class="aviso-stock"><br>{{ g.motivo_rechazo }}</small>
                    </div>
                    <div class="lista-pagos-der"><strong>{{ $bs(g.monto) }}</strong><sc-estado tipo="pago" :valor="g.estado"/></div>
                  </li>
                </ul>
                <div v-if="puedePagar" class="formulario-acciones">
                  <button v-if="puedeCancelar" class="btn btn-peligro-suave" @click="confirmar = true">Cancelar pedido</button>
                  <router-link v-if="!staff" :to="'/pagar/' + p.id" class="btn btn-primario"><sc-icono nombre="tarjeta" :tam="16"/> Pagar ahora</router-link>
                </div>
              </section>
            </div>
          </div>
        </div>

        <sc-modal :abierto="confirmar" titulo="Cancelar pedido" texto-confirmar="Sí, cancelar" peligro :cargando="cancelando"
                  @confirmar="cancelar" @cancelar="confirmar = false">
          <p>¿Seguro que quieres cancelar el pedido #{{ p && p.id }}? Esta acción no se puede deshacer.</p>
        </sc-modal>
      </div>`,
    data() {
        return { p: null, cargando: true, confirmar: false, cancelando: false,
                 tiposMensaje: { confirmacion: 'Confirmación del pedido', comprobante: 'Comprobante', envio: 'Pedido en camino', entrega: 'Pedido entregado' } };
    },
    computed: {
        staff() { return !SC.store.esCliente; },
        pasos() {
            return [{ texto: 'Pedido creado' }, { texto: 'Pago aprobado' }, { texto: 'En preparación' }, { texto: 'En camino' }, { texto: 'Entregado' }];
        },
        /** Cuántos pasos de la línea de tiempo ya se cumplieron */
        avance() {
            if (!this.p) return 0;
            if (this.p.estado_pago !== 'Pagado') return 1;
            return { Pendiente: 2, En_preparacion: 3, En_camino: 4, Entregado: 5 }[this.p.estado_envio] || 2;
        },
        puedePagar() {
            return this.p && this.p.estado_pago !== 'Pagado' && this.p.estado_envio !== 'Cancelado';
        },
        puedeCancelar() { return this.puedePagar && (!this.staff || SC.store.puedeEscribir('ventas')); }
    },
    async created() {
        try { this.p = (await SC.api.get('/pedidos/' + this.$route.params.id)).data; }
        catch (e) { this.p = null; }
        finally { this.cargando = false; }
    },
    methods: {
        fecha: SC.util.fecha,
        /** Al abrir el enlace de WhatsApp se marca el mensaje como enviado */
        async marcarEnviado(m) {
            if (m.estado === 'Enviado') return;
            try { await SC.api.patch('/notificaciones/' + m.id + '/enviado'); m.estado = 'Enviado'; SC.store.contarMensajes(); }
            catch (e) { /* si falla, el enlace igual se abre */ }
        },
        async cancelar() {
            this.cancelando = true;
            try {
                this.p = (await SC.api.patch('/pedidos/' + this.p.id + '/cancelar')).data;
                SC.store.ok('Pedido cancelado.');
                this.confirmar = false;
            } catch (e) { SC.store.error(e.mensaje); this.confirmar = false; }
            finally { this.cancelando = false; }
        }
    }
};
