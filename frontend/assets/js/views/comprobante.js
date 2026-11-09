/*
 * Comprobante del pedido: pantalla imprimible (Imprimir → "Guardar como PDF" en el navegador)
 * y texto listo para compartir por WhatsApp. Se arma en el servidor con los datos reales del pago.
 */
SC.views.Comprobante = {
    template: `
      <div>
        <div class="no-imprimir comprobante-barra">
          <router-link :to="volverA" class="volver"><sc-icono nombre="volver" :tam="16"/> Volver al pedido</router-link>
          <div v-if="c" class="acciones">
            <button class="btn btn-secundario" @click="copiar"><sc-icono nombre="lista" :tam="16"/> Copiar texto</button>
            <a class="btn btn-secundario" :href="enlaceWa" target="_blank" rel="noopener"><sc-icono nombre="whatsapp" :tam="16"/> Compartir por WhatsApp</a>
            <button class="btn btn-primario" @click="imprimir"><sc-icono nombre="imprimir" :tam="16"/> Imprimir / Guardar PDF</button>
          </div>
        </div>

        <p v-if="cargando" class="sub">Cargando…</p>
        <div v-else-if="!c" class="tarjeta vacio-caja">
          <h2>Comprobante no disponible</h2>
          <p class="sub">{{ error }}</p>
        </div>

        <article v-else class="comprobante" id="comprobante">
          <header class="comp-cabecera">
            <div>
              <div class="comp-marca">Smart<span>Cell</span></div>
              <small>{{ c.tienda.direccion }}<br>Tel. {{ c.tienda.telefono }} · NIT {{ c.tienda.nit }}</small>
            </div>
            <div class="comp-numero">
              <small>COMPROBANTE DE VENTA</small>
              <strong>{{ c.numero }}</strong>
              <small>{{ fecha(c.emitido_en) }}</small>
            </div>
          </header>

          <section class="comp-partes">
            <div><h3>Cliente</h3><p>{{ c.cliente.nombre }}<br>{{ c.cliente.email }}<br>Tel. {{ c.cliente.telefono }}</p></div>
            <div><h3>Entrega</h3><p>{{ c.entrega.direccion }}<br>{{ c.entrega.ciudad }}<template v-if="c.despacho"><br>Guía <strong>{{ c.despacho.guia }}</strong></template></p></div>
            <div><h3>Pago</h3><p>{{ c.pago.metodo }}<template v-if="c.pago.tarjeta_ultimos4"> •••• {{ c.pago.tarjeta_ultimos4 }}</template><br>Ref. {{ c.pago.referencia }}<br><span class="badge badge-ok">Pagado</span></p></div>
          </section>

          <table class="comp-tabla">
            <thead><tr><th>Descripción</th><th class="der">Cant.</th><th class="der">P. unit.</th><th class="der">Importe</th></tr></thead>
            <tbody>
              <tr v-for="(i, k) in c.items" :key="k">
                <td>
                  <strong>{{ i.producto }}</strong>
                  <div v-for="imei in i.imeis" :key="imei" class="comp-imei">IMEI {{ imei }}</div>
                </td>
                <td class="der">{{ i.cantidad }}</td>
                <td class="der">
                  {{ $bs(i.precio_unitario) }}
                  <small v-if="i.descuento_pct > 0"><br><s>{{ $bs(i.precio_lista) }}</s> -{{ i.descuento_pct }}%</small>
                </td>
                <td class="der"><strong>{{ $bs(i.subtotal) }}</strong></td>
              </tr>
            </tbody>
          </table>

          <div class="comp-totales">
            <div v-if="c.descuento > 0"><span>Subtotal a precio de lista</span><span>{{ $bs(c.subtotal) }}</span></div>
            <div v-if="c.descuento > 0" class="ahorro"><span>Descuento por promociones</span><span>-{{ $bs(c.descuento) }}</span></div>
            <div class="total"><span>TOTAL PAGADO</span><strong>{{ $bs(c.total) }}</strong></div>
          </div>

          <footer class="comp-pie">
            Gracias por tu compra en {{ c.tienda.nombre }}. Conserva este comprobante y el IMEI de tu equipo para la garantía.
            <br>Documento generado automáticamente · pasarela de pagos simulada (proyecto universitario).
          </footer>
        </article>
      </div>`,
    data() { return { c: null, cargando: true, error: '' }; },
    computed: {
        volverA() { return (SC.store.esCliente ? '/mis-pedidos/' : '/pedidos/') + this.$route.params.id; },
        enlaceWa() { return this.c ? 'https://wa.me/?text=' + encodeURIComponent(this.c.texto_whatsapp + '\n\n' + location.href) : '#'; }
    },
    async created() {
        try { this.c = (await SC.api.get('/pedidos/' + this.$route.params.id + '/comprobante')).data; }
        catch (e) { this.error = e.mensaje; }
        finally { this.cargando = false; }
    },
    methods: {
        fecha: SC.util.fecha,
        imprimir() { window.print(); },
        async copiar() {
            try { await navigator.clipboard.writeText(this.c.texto_whatsapp); SC.store.ok('Texto copiado.'); }
            catch (e) { SC.store.error('No se pudo copiar. Selecciona el comprobante y cópialo manualmente.'); }
        }
    }
};
