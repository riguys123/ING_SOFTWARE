/* Carrito de compras */
SC.views.Carrito = {
    template: `
      <div>
        <h1>Tu carrito</h1>
        <p v-if="cargando" class="sub">Cargando…</p>

        <div v-else-if="!cart.items.length" class="tarjeta vacio-caja">
          <sc-icono nombre="carrito" :tam="40"/>
          <h2>Tu carrito está vacío</h2>
          <p class="sub">Explora el catálogo y añade lo que te guste.</p>
          <router-link to="/tienda" class="btn btn-primario">Ver catálogo</router-link>
        </div>

        <div v-else class="carrito-layout">
          <div class="tabla-contenedor">
            <table class="tabla tabla-carrito">
              <thead><tr><th>Producto</th><th>Precio</th><th>Cantidad</th><th>Subtotal</th><th class="col-acciones"></th></tr></thead>
              <tbody>
                <tr v-for="i in cart.items" :key="i.id">
                  <td data-label="Producto">
                    <div class="celda-producto">
                      <div class="mini-foto"><sc-foto :fotos="i.fotos" :marca="i.marca" :modelo="i.modelo"/></div>
                      <span>
                        <router-link :to="'/tienda/' + i.producto_id"><strong>{{ i.marca }} {{ i.modelo }}</strong></router-link>
                        <small v-if="!i.disponible" class="aviso-stock"><br>Stock insuficiente: quedan {{ i.stock_actual }}</small>
                      </span>
                    </div>
                  </td>
                  <td data-label="Precio"><sc-precio :p="{ precio: i.precio_lista, precio_final: i.precio, descuento_pct: i.descuento_pct }"/></td>
                  <td data-label="Cantidad">
                    <div class="cantidad">
                      <button class="btn btn-secundario btn-sm" :disabled="ocupado || i.cantidad <= 1" @click="cambiar(i, i.cantidad - 1)" aria-label="Menos"><sc-icono nombre="menos" :tam="14"/></button>
                      <span>{{ i.cantidad }}</span>
                      <button class="btn btn-secundario btn-sm" :disabled="ocupado || i.cantidad >= 10" @click="cambiar(i, i.cantidad + 1)" aria-label="Más"><sc-icono nombre="mas" :tam="14"/></button>
                    </div>
                  </td>
                  <td data-label="Subtotal"><strong>{{ $bs(i.subtotal) }}</strong></td>
                  <td class="col-acciones">
                    <button class="btn btn-peligro-suave btn-sm" :disabled="ocupado" @click="quitar(i)" title="Quitar"><sc-icono nombre="papelera" :tam="15"/> Quitar</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <aside class="tarjeta resumen">
            <h3>Resumen</h3>
            <div class="resumen-fila"><span>Productos</span><span>{{ cart.unidades }}</span></div>
            <div v-if="ahorro > 0" class="resumen-fila ahorro"><span>Ahorro por promociones</span><span>-{{ $bs(ahorro) }}</span></div>
            <div class="resumen-fila"><span>Envío</span><span>A coordinar</span></div>
            <div class="resumen-fila resumen-total"><span>Total</span><strong>{{ $bs(cart.total) }}</strong></div>
            <div v-if="hayProblema" class="mensaje-error">Ajusta las cantidades marcadas: algunos equipos ya no tienen stock suficiente.</div>
            <router-link to="/checkout" class="btn btn-primario btn-bloque" :class="{ deshabilitado: hayProblema }" :aria-disabled="hayProblema">Continuar al pago</router-link>
            <button class="btn btn-secundario btn-bloque" :disabled="ocupado" @click="vaciar">Vaciar carrito</button>
          </aside>
        </div>
      </div>`,
    data() { return { cargando: true, ocupado: false }; },
    computed: {
        cart() { return SC.cart; },
        hayProblema() { return SC.cart.items.some(i => !i.disponible); },
        ahorro() { return SC.cart.items.reduce((s, i) => s + (i.precio_lista - i.precio) * i.cantidad, 0); }
    },
    async created() { await SC.cart.cargar(); this.cargando = false; },
    methods: {
        async accion(fn) {
            this.ocupado = true;
            try { await fn(); } catch (e) { SC.store.error(e.mensaje); }
            finally { this.ocupado = false; }
        },
        cambiar(i, n) { return this.accion(() => SC.cart.cambiarCantidad(i.id, n)); },
        quitar(i) { return this.accion(() => SC.cart.quitar(i.id)); },
        vaciar() { return this.accion(() => SC.cart.vaciar()); }
    }
};

/*
 * Checkout: datos de envío + pago simulado.
 *  /checkout            -> crea el pedido desde el carrito y luego cobra
 *  /pagar/:pedidoId     -> reintenta el pago de un pedido que ya existe (pendiente o rechazado)
 */
SC.views.Checkout = {
    template: `
      <div>
        <router-link :to="reintento ? '/mis-pedidos' : '/carrito'" class="volver"><sc-icono nombre="volver" :tam="16"/> {{ reintento ? 'Mis pedidos' : 'Volver al carrito' }}</router-link>
        <h1>{{ reintento ? 'Pagar pedido #' + pedidoId : 'Finalizar compra' }}</h1>
        <p v-if="cargando" class="sub">Cargando…</p>

        <form v-else class="checkout-layout" @submit.prevent="pagar" novalidate>
          <div>
            <section class="tarjeta">
              <h2>1. Datos de entrega</h2>
              <div class="campo" :class="{ 'con-error': err.direccion_envio }">
                <label for="dir">Dirección</label>
                <input id="dir" type="text" v-model="envio.direccion_envio" :disabled="reintento || procesando" placeholder="Calle, número, zona" autocomplete="street-address">
                <span v-if="err.direccion_envio" class="error-campo">{{ err.direccion_envio }}</span>
              </div>
              <div class="grid-2">
                <div class="campo" :class="{ 'con-error': err.ciudad }">
                  <label for="ciu">Ciudad</label>
                  <input id="ciu" type="text" v-model="envio.ciudad" :disabled="reintento || procesando" autocomplete="address-level2">
                  <span v-if="err.ciudad" class="error-campo">{{ err.ciudad }}</span>
                </div>
                <div class="campo" :class="{ 'con-error': err.telefono }">
                  <label for="tel">Teléfono</label>
                  <input id="tel" type="tel" v-model="envio.telefono" :disabled="reintento || procesando" placeholder="7XXXXXXX" autocomplete="tel">
                  <span v-if="err.telefono" class="error-campo">{{ err.telefono }}</span>
                </div>
              </div>
              <div v-if="!reintento" class="campo">
                <label for="not">Indicaciones (opcional)</label>
                <input id="not" type="text" v-model="envio.notas" :disabled="procesando" placeholder="Referencia, horario de entrega…">
              </div>
            </section>

            <section class="tarjeta">
              <h2>2. Método de pago</h2>
              <p v-if="pago.metodo === 'Tarjeta'" class="sub">Pasarela de pagos <strong>simulada</strong>: no se cobra dinero real.</p>
              <p v-else-if="pago.metodo === 'PayPal'" class="sub">Serás redirigido a <strong>PayPal</strong> para pagar de forma segura. Se cobra en dólares (USD) al tipo de cambio de la tienda.</p>
              <p v-else class="sub">El pago se hace por fuera (banco o app) y el vendedor lo <strong>verifica</strong> antes de preparar tu pedido.</p>
              <div class="metodos" role="radiogroup" aria-label="Método de pago">
                <label v-for="m in metodos" :key="m.clave" class="metodo" :class="{ activo: pago.metodo === m.clave }">
                  <input type="radio" name="metodo" :value="m.clave" v-model="pago.metodo" :disabled="procesando">
                  <sc-icono :nombre="m.icono" :tam="22"/> <span>{{ m.texto }}</span>
                </label>
              </div>

              <div v-if="errorPago" class="mensaje-error" role="alert"><strong>Pago rechazado.</strong> {{ errorPago }} Puedes intentar con otra tarjeta u otro método.</div>

              <div v-if="pago.metodo === 'Tarjeta'">
                <div class="campo" :class="{ 'con-error': err.numero }">
                  <label for="num">Número de tarjeta</label>
                  <input id="num" type="text" inputmode="numeric" v-model="pago.numero" @input="formatearNumero" maxlength="23" placeholder="4242 4242 4242 4242" autocomplete="cc-number" :disabled="procesando">
                  <span v-if="err.numero" class="error-campo">{{ err.numero }}</span>
                </div>
                <div class="campo" :class="{ 'con-error': err.titular }">
                  <label for="tit">Nombre del titular</label>
                  <input id="tit" type="text" v-model="pago.titular" autocomplete="cc-name" :disabled="procesando">
                  <span v-if="err.titular" class="error-campo">{{ err.titular }}</span>
                </div>
                <div class="grid-2">
                  <div class="campo" :class="{ 'con-error': err.vencimiento }">
                    <label for="ven">Vencimiento (MM/AA)</label>
                    <input id="ven" type="text" inputmode="numeric" v-model="pago.vencimiento" @input="formatearVencimiento" maxlength="5" placeholder="12/29" autocomplete="cc-exp" :disabled="procesando">
                    <span v-if="err.vencimiento" class="error-campo">{{ err.vencimiento }}</span>
                  </div>
                  <div class="campo" :class="{ 'con-error': err.cvv }">
                    <label for="cvv">CVV</label>
                    <input id="cvv" type="password" inputmode="numeric" v-model="pago.cvv" maxlength="4" placeholder="•••" autocomplete="cc-csc" :disabled="procesando">
                    <span v-if="err.cvv" class="error-campo">{{ err.cvv }}</span>
                  </div>
                </div>
                <div class="pistas-test">
                  <strong>Tarjetas de prueba:</strong>
                  <button type="button" class="enlace" @click="probar('4242 4242 4242 4242')">4242… (aprobada)</button>
                  <button type="button" class="enlace" @click="probar('4000 0000 0000 0002')">4000…0002 (rechazada)</button>
                  <button type="button" class="enlace" @click="probar('4000 0000 0000 9995')">4000…9995 (sin fondos)</button>
                </div>
              </div>

              <div v-else-if="pago.metodo === 'PayPal'" class="pago-manual">
                <div v-if="paypal.demo" class="mensaje-aviso" role="status">Modo <strong>demostración</strong>: se simula la pantalla de PayPal, no se cobra dinero real.</div>
                <ol class="pasos-pago">
                  <li>Pulsa <strong>Pagar con PayPal</strong>: te llevaremos a PayPal para iniciar sesión y aprobar el pago.</li>
                  <li>Se cobrará aproximadamente <strong>US$ {{ usd }}</strong> ({{ $bs(total) }} a Bs {{ paypal.tipo_cambio }} por dólar).</li>
                  <li>Al volver, tu pago se confirma automáticamente y el pedido pasa a preparación.</li>
                </ol>
              </div>

              <div v-else class="pago-manual">
                <ol class="pasos-pago">
                  <li v-if="pago.metodo === 'QR'">
                    <strong>Escanea el QR</strong> con la app de tu banco y paga <strong>{{ $bs(total) }}</strong>.
                    <div class="pago-qr">
                      <img v-if="!qrFalla" :src="cfg.qr" class="qr-img" alt="Código QR de pago de la tienda" @error="qrFalla = true">
                      <svg v-else viewBox="0 0 25 25" class="qr" role="img" aria-label="Código QR de demostración" shape-rendering="crispEdges">
                        <rect width="25" height="25" fill="#fff"/>
                        <rect v-for="(c, k) in qrCeldas" :key="k" :x="c[0]" :y="c[1]" width="1" height="1" fill="#14181f"/>
                      </svg>
                      <small v-if="qrFalla" class="sub">QR de demostración: el negocio debe colocar su QR real del banco.</small>
                    </div>
                  </li>
                  <li v-else>
                    <strong>Transfiere o deposita {{ $bs(total) }}</strong> a esta cuenta:
                    <dl class="datos datos-ancho">
                      <dt>Banco</dt><dd>{{ cfg.banco }}</dd>
                      <dt>Cuenta</dt><dd>{{ cfg.cuenta }}</dd>
                      <dt>Titular</dt><dd>{{ cfg.titular }}</dd>
                    </dl>
                  </li>
                  <li>En la glosa o concepto escribe: <strong class="glosa">SmartCell - {{ usuario }}</strong></li>
                  <li>
                    Escribe el <strong>N° de operación</strong> que te dio el banco y, si puedes, adjunta una foto del comprobante.
                    <div class="campo" :class="{ 'con-error': err.comprobante }">
                      <label for="comp">N° de operación o comprobante</label>
                      <input id="comp" type="text" v-model="pago.comprobante" placeholder="Ej: 20481577" :disabled="procesando">
                      <span v-if="err.comprobante" class="error-campo">{{ err.comprobante }}</span>
                    </div>
                    <div class="campo" :class="{ 'con-error': err.imagen }">
                      <label>Foto del comprobante (opcional)</label>
                      <div class="comprobante-subida">
                        <img v-if="pago.imagen" :src="pago.imagen" class="comprobante-mini" alt="Comprobante adjunto">
                        <label class="btn btn-secundario btn-sm"><input type="file" accept="image/jpeg,image/png,image/webp" hidden :disabled="procesando || subiendo" @change="subirImagen"><sc-icono nombre="subir" :tam="15"/> {{ subiendo ? 'Subiendo…' : (pago.imagen ? 'Cambiar foto' : 'Adjuntar foto') }}</label>
                        <button v-if="pago.imagen" type="button" class="enlace" @click="pago.imagen = ''">Quitar</button>
                      </div>
                      <span v-if="err.imagen" class="error-campo">{{ err.imagen }}</span>
                    </div>
                  </li>
                  <li>Pulsa <strong>Enviar comprobante</strong>. Tu pedido quedará <em>por verificar</em> y el vendedor lo confirmará apenas vea el pago.</li>
                </ol>
              </div>
            </section>
          </div>

          <aside class="tarjeta resumen">
            <h3>Tu pedido</h3>
            <ul class="resumen-lista">
              <li v-for="i in lineas" :key="i.clave">
                <span>{{ i.cantidad }} × {{ i.nombre }}</span><span>{{ $bs(i.subtotal) }}</span>
              </li>
            </ul>
            <div class="resumen-fila resumen-total"><span>Total a pagar</span><strong>{{ $bs(total) }}</strong></div>
            <button type="submit" class="btn btn-primario btn-bloque" :disabled="procesando || false">
              {{ procesando ? 'Procesando…' : (pago.metodo === 'Tarjeta' ? 'Pagar ' + $bs(total) : (pago.metodo === 'PayPal' ? 'Pagar con PayPal' : 'Enviar comprobante')) }}
            </button>
            <p v-if="pago.metodo === 'Tarjeta'" class="sub"><sc-icono nombre="candado" :tam="13"/> Tus datos de tarjeta no se guardan: solo los últimos 4 dígitos.</p>
          </aside>
        </form>
      </div>`,
    data() {
        return {
            cargando: true, procesando: false, pedidoId: null, reintento: false,
            lineas: [], total: 0, err: {}, errorPago: '', qrFalla: false, subiendo: false, paypal: { activo: false, demo: true, tipo_cambio: 6.96 }, cfg: (window.SC_CONFIG && SC_CONFIG.PAGO) || {},
            envio: { direccion_envio: '', ciudad: 'Sucre', telefono: '', notas: '' },
            pago: { metodo: 'Tarjeta', numero: '', titular: '', vencimiento: '', cvv: '', comprobante: '', imagen: '' },
            metodos: [
                { clave: 'Tarjeta', texto: 'Tarjeta', icono: 'tarjeta' },
                { clave: 'QR', texto: 'QR', icono: 'qr' },
                { clave: 'Transferencia', texto: 'Transferencia', icono: 'banco' },
                { clave: 'PayPal', texto: 'PayPal', icono: 'tarjeta' }
            ]
        };
    },
    computed: {
        usd() { return (this.total / (this.paypal.tipo_cambio || 6.96)).toFixed(2); },
        usuario() { return SC.store.user ? SC.store.user.nombre : ''; },
        /** Cuadrícula pseudo-aleatoria fija para dibujar el QR de demostración */
        qrCeldas() {
            const celdas = [];
            const finder = (x, y) => {
                for (let i = 0; i < 7; i++) for (let j = 0; j < 7; j++) {
                    const borde = i === 0 || j === 0 || i === 6 || j === 6;
                    const centro = i >= 2 && i <= 4 && j >= 2 && j <= 4;
                    if (borde || centro) celdas.push([x + i, y + j]);
                }
            };
            finder(1, 1); finder(17, 1); finder(1, 17);
            let semilla = 7 + (this.pedidoId || 0);
            for (let y = 1; y < 24; y++) for (let x = 1; x < 24; x++) {
                const enFinder = (x < 9 && y < 9) || (x > 15 && y < 9) || (x < 9 && y > 15);
                semilla = (semilla * 1103515245 + 12345) & 0x7fffffff;
                if (!enFinder && semilla % 100 < 46) celdas.push([x, y]);
            }
            return celdas;
        }
    },
    async created() {
        const id = this.$route.params.pedidoId;
        if (id) {                                   // reintentar el pago de un pedido existente
            this.reintento = true;
            this.pedidoId = Number(id);
            try {
                const p = (await SC.api.get('/pedidos/' + id)).data;
                if (['Pagado', 'Por_verificar'].includes(p.estado_pago) || p.estado_envio === 'Cancelado') return this.$router.replace('/mis-pedidos/' + id);
                this.envio = { direccion_envio: p.direccion_envio, ciudad: p.ciudad, telefono: p.telefono, notas: p.notas || '' };
                this.total = p.total;
                this.lineas = p.items.map(i => ({ clave: i.id, cantidad: i.cantidad, nombre: i.marca + ' ' + i.modelo, subtotal: i.subtotal }));
            } catch (e) { SC.store.error(e.mensaje); return this.$router.replace('/mis-pedidos'); }
        } else {
            await SC.cart.cargar();
            if (!SC.cart.items.length) return this.$router.replace('/carrito');
            this.instantanea();
        }
        try {
            const c = (await SC.api.get('/pagos/config')).data;
            this.paypal = { activo: !!c.paypal, demo: !!c.demo, tipo_cambio: c.tipo_cambio };
        } catch (e) { /* sin PayPal */ }
        this.cargando = false;
    },
    methods: {
        /** Copia del carrito: se conserva aunque el servidor lo vacíe al crear el pedido */
        instantanea() {
            this.total = SC.cart.total;
            this.lineas = SC.cart.items.map(i => ({ clave: i.id, cantidad: i.cantidad, nombre: i.marca + ' ' + i.modelo, subtotal: i.subtotal }));
        },
        formatearNumero() {
            const d = this.pago.numero.replace(/\D/g, '').slice(0, 19);
            this.pago.numero = d.replace(/(.{4})/g, '$1 ').trim();
        },
        formatearVencimiento() {
            let d = this.pago.vencimiento.replace(/\D/g, '').slice(0, 4);
            if (d.length > 2) d = d.slice(0, 2) + '/' + d.slice(2);
            this.pago.vencimiento = d;
        },
        probar(numero) {
            const anio = String((new Date().getFullYear() + 3) % 100).padStart(2, '0');
            this.pago.metodo = 'Tarjeta';
            this.pago.numero = numero;
            this.pago.titular = this.pago.titular || SC.store.user.nombre.toUpperCase();
            this.pago.vencimiento = '12/' + anio;
            this.pago.cvv = '123';
        },
        /** Validación rápida en el navegador (el servidor valida de nuevo todo) */
        validarLocal() {
            const e = {};
            if (!this.reintento) {
                if (this.envio.direccion_envio.trim().length < 8) e.direccion_envio = 'Ingresa una dirección de entrega (mínimo 8 caracteres).';
                if (this.envio.ciudad.trim().length < 2) e.ciudad = 'Ingresa la ciudad.';
                if (!/^[0-9+\-\s]{7,20}$/.test(this.envio.telefono.trim())) e.telefono = 'Ingresa un teléfono válido.';
            }
            if (this.pago.metodo === 'Tarjeta') {
                if (this.pago.numero.replace(/\D/g, '').length < 13) e.numero = 'Ingresa el número completo de la tarjeta.';
                if (this.pago.titular.trim().length < 3) e.titular = 'Ingresa el nombre del titular.';
                if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(this.pago.vencimiento)) e.vencimiento = 'Usa el formato MM/AA.';
                if (!/^\d{3,4}$/.test(this.pago.cvv)) e.cvv = 'El CVV tiene 3 o 4 dígitos.';
            } else if (this.pago.metodo === 'PayPal') {
            } else {   // QR y Transferencia
                if (!/^[A-Za-z0-9\-]{6,30}$/.test(this.pago.comprobante.trim())) e.comprobante = 'Ingresa el N° de operación (6 a 30 letras o números).';
            }
            return e;
        },
        /** Sube la foto del comprobante (se reduce antes de enviarla) */
        async subirImagen(ev) {
            const archivo = ev.target.files[0]; ev.target.value = '';
            if (!archivo) return;
            this.subiendo = true;
            delete this.err.imagen;
            try {
                const blob = await SC.util.reducirImagen(archivo);
                this.pago.imagen = (await SC.api.subir('/pagos/comprobante', 'imagen', blob)).data.ruta;
            } catch (e) { this.err = { ...this.err, imagen: e.mensaje || e.message }; }
            finally { this.subiendo = false; }
        },
        async pagar() {
            this.err = {};
            this.errorPago = '';
            this.err = this.validarLocal();
            if (Object.keys(this.err).length) return;

            this.procesando = true;
            try {
                // 1) Crear el pedido (solo la primera vez; si el pago es rechazado se reutiliza)
                if (!this.pedidoId) {
                    const r = await SC.api.post('/pedidos', this.envio);
                    this.pedidoId = r.data.id;
                    SC.cart.vaciarLocal();
                }
                // 2a) PayPal: crear la orden y redirigir a PayPal
                if (this.pago.metodo === 'PayPal') {
                    if (this.paypal.demo) return this.$router.replace('/paypal-demo/' + this.pedidoId);
                    const o = await SC.api.post('/pagos/paypal/crear', { pedido_id: this.pedidoId });
                    window.location.href = o.data.url;
                    return;                      // la página se abandona; al volver lo atiende PayPalRetorno
                }
                // 2) Cobrar
                const cuerpo = { pedido_id: this.pedidoId, metodo: this.pago.metodo };
                if (this.pago.metodo === 'Tarjeta') {
                    cuerpo.tarjeta = { numero: this.pago.numero, titular: this.pago.titular,
                                       vencimiento: this.pago.vencimiento, cvv: this.pago.cvv };
                } else {
                    cuerpo.comprobante = this.pago.comprobante.trim();
                    if (this.pago.imagen) cuerpo.imagen = this.pago.imagen;
                }
                const r = await SC.api.post('/pagos/procesar', cuerpo);
                this.pago.numero = ''; this.pago.cvv = '';          // no dejamos datos de tarjeta en memoria
                if (r.data.pendiente) {
                    SC.store.ok('Comprobante enviado. El vendedor verificará tu pago.');
                    return this.$router.replace({ path: '/mis-pedidos/' + this.pedidoId, query: { pendiente: 1 } });
                }
                SC.store.ok('¡Pago aprobado! Tu comprobante ya está listo.');
                this.$router.replace({ path: '/mis-pedidos/' + this.pedidoId, query: { exito: 1 } });
            } catch (e) {
                if (e.status === 422 && e.detalles) {
                    this.err = e.detalles;
                    SC.store.error(e.mensaje);
                } else if (e.status === 402) {
                    this.errorPago = e.mensaje;
                    this.pago.cvv = '';
                } else if (e.status === 409 && !this.pedidoId) {
                    SC.store.error(e.mensaje + ' ' + Object.entries(e.detalles || {}).map(([k, v]) => k + ' ' + v).join(' '));
                    this.$router.replace('/carrito');
                } else {
                    SC.store.error(e.mensaje);
                }
            } finally {
                this.procesando = false;
            }
        }
    }
};


/**
 * Retorno desde PayPal: #/paypal-retorno?pedido=ID&token=ORDEN
 * Captura la orden en el servidor y verifica monto/estado antes de dar el pedido por pagado.
 */
SC.views.PayPalRetorno = {
    template: `
      <div class="centrado">
        <div v-if="!error" class="tarjeta">
          <h1>Confirmando tu pago con PayPal…</h1>
          <p class="sub">No cierres esta ventana.</p>
        </div>
        <div v-else class="tarjeta">
          <h1>No pudimos confirmar el pago</h1>
          <div class="mensaje-error" role="alert">{{ error }}</div>
          <p class="sub">Si PayPal te cobró, escribe al vendedor indicando el pedido #{{ pedido }}.</p>
          <router-link :to="'/pagar/' + pedido" class="btn btn-primario">Reintentar el pago</router-link>
          <router-link to="/mis-pedidos" class="btn btn-secundario">Mis pedidos</router-link>
        </div>
      </div>`,
    data() { return { error: '', pedido: Number(this.$route.query.pedido) || 0 }; },
    async created() {
        const orden = this.$route.query.token;
        if (!this.pedido || !orden) { this.error = 'Faltan datos del pago de PayPal.'; return; }
        try {
            const r = await SC.api.post('/pagos/paypal/capturar', { pedido_id: this.pedido, orden });
            if (r.data && r.data.pendiente) {
                SC.store.ok('Pago recibido. El vendedor lo verificará.');
                return this.$router.replace({ path: '/mis-pedidos/' + this.pedido, query: { pendiente: 1 } });
            }
            SC.store.ok('¡Pago con PayPal aprobado!');
            this.$router.replace({ path: '/mis-pedidos/' + this.pedido, query: { exito: 1 } });
        } catch (e) {
            if (e.status === 409 && /pagado/i.test(e.codigo || '')) return this.$router.replace('/mis-pedidos/' + this.pedido);
            this.error = e.mensaje || 'Error al confirmar el pago.';
        }
    }
};


/** PayPal en modo demostración: pantalla que imita la aprobación del pago (no cobra dinero). */
SC.views.PayPalDemo = {
    template: `
      <div class="centrado">
        <div class="tarjeta paypal-demo">
          <h1><span class="pp-logo">Pay<span>Pal</span></span> <small>(simulación)</small></h1>
          <p v-if="cargando" class="sub">Cargando…</p>
          <template v-else>
            <p class="sub">Pagar a <strong>SmartCell Commerce</strong></p>
            <p class="pp-monto">US$ {{ usd }} <small>({{ $bs(total) }})</small></p>
            <div class="campo"><label>Cuenta PayPal</label><input type="text" :value="correo" disabled></div>
            <div v-if="error" class="mensaje-error" role="alert">{{ error }}</div>
            <button class="btn btn-primario btn-bloque" :disabled="procesando" @click="aprobar">{{ procesando ? 'Procesando…' : 'Aprobar y pagar' }}</button>
            <router-link :to="'/pagar/' + pedidoId" class="btn btn-secundario btn-bloque">Cancelar</router-link>
            <p class="sub">Pantalla de demostración: no se realiza ningún cobro real.</p>
          </template>
        </div>
      </div>`,
    data() { return { pedidoId: Number(this.$route.params.pedidoId), total: 0, tc: 6.96, cargando: true, procesando: false, error: '' }; },
    computed: {
        usd() { return (this.total / this.tc).toFixed(2); },
        correo() { return SC.store.user ? SC.store.user.email : ''; }
    },
    async created() {
        try {
            const p = (await SC.api.get('/pedidos/' + this.pedidoId)).data;
            if (['Pagado', 'Por_verificar'].includes(p.estado_pago) || p.estado_envio === 'Cancelado') return this.$router.replace('/mis-pedidos/' + this.pedidoId);
            this.total = p.total;
            this.tc = ((await SC.api.get('/pagos/config')).data.tipo_cambio) || 6.96;
            this.cargando = false;
        } catch (e) { SC.store.error(e.mensaje); this.$router.replace('/mis-pedidos'); }
    },
    methods: {
        async aprobar() {
            this.procesando = true; this.error = '';
            try {
                await SC.api.post('/pagos/paypal/simular', { pedido_id: this.pedidoId });
                SC.store.ok('¡Pago con PayPal aprobado!');
                this.$router.replace({ path: '/mis-pedidos/' + this.pedidoId, query: { exito: 1 } });
            } catch (e) { this.error = e.mensaje || 'No se pudo completar el pago.'; }
            finally { this.procesando = false; }
        }
    }
};
