/*
 * Panel interactivo (Sprint 3). Se adapta al rol: cada bloque aparece solo si el rol tiene el módulo.
 *  - Selector de período (7 / 30 / 90 días) y actualización automática opcional.
 *  - KPIs con variación contra el período anterior; los que llevan a una pantalla son clicables.
 *  - Gráfico de ingresos con detalle del día al pasar el mouse / hacer clic.
 *  - Reparto por categoría, ranking de productos y estados de pedidos (clicables hacia la lista filtrada).
 *  - Repartidor: sus entregas con botones para iniciar la ruta y marcar como entregado.
 */
SC.views.Dashboard = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>{{ saludo }}, {{ primerNombre }}</h1>
            <p class="sub">{{ user.rol }} · actualizado {{ hace }}</p>
          </div>
          <div class="panel-controles">
            <div v-if="r.ventas" class="segmentado" role="group" aria-label="Período">
              <button v-for="d in [7, 30, 90]" :key="d" :class="{ activo: dias === d }" @click="cambiarDias(d)">{{ d }} días</button>
            </div>
            <label class="check auto"><input type="checkbox" v-model="auto" @change="programar"> Auto</label>
            <button class="btn btn-secundario btn-sm" :disabled="cargando" @click="cargar" title="Actualizar ahora">
              <sc-icono nombre="actualizar" :tam="15" :class="{ girando: cargando }"/> Actualizar
            </button>
          </div>
        </div>

        <!-- ================= KPIs ================= -->
        <div class="kpis">
          <template v-if="r.ventas">
            <div class="kpi">
              <span class="kpi-etq">Ingresos ({{ dias }} días)</span>
              <strong class="kpi-num verde">{{ $bs(r.ventas.ingresos) }}</strong>
              <span class="kpi-var" :class="claseVar(varIngresos)">{{ textoVar(varIngresos) }}</span>
            </div>
            <div class="kpi">
              <span class="kpi-etq">Pedidos pagados</span>
              <strong class="kpi-num">{{ r.ventas.pedidos }}</strong>
              <span class="kpi-var" :class="claseVar(varPedidos)">{{ textoVar(varPedidos) }}</span>
            </div>
            <div class="kpi">
              <span class="kpi-etq">Ticket promedio</span>
              <strong class="kpi-num">{{ $bs(r.ventas.ticket_promedio) }}</strong>
              <span class="kpi-var neutro">{{ r.ventas.unidades }} unidades vendidas</span>
            </div>
            <div class="kpi">
              <span class="kpi-etq">Ventas de hoy</span>
              <strong class="kpi-num">{{ $bs(r.ventas.ingresos_hoy) }}</strong>
              <span class="kpi-var neutro">Total histórico {{ $bs(r.ventas.ingresos_total) }}</span>
            </div>
          </template>
          <router-link v-if="r.mensajes_pendientes !== undefined" to="/mensajes" class="kpi kpi-enlace" :class="{ alerta: r.mensajes_pendientes }">
            <span class="kpi-etq">Mensajes de WhatsApp</span>
            <strong class="kpi-num" :class="r.mensajes_pendientes ? 'ambar' : 'verde'">{{ r.mensajes_pendientes }}</strong>
            <span class="kpi-var neutro">{{ r.mensajes_pendientes ? 'por enviar · ver bandeja' : 'todo enviado' }}</span>
          </router-link>
          <router-link v-if="r.inventario" to="/inventario" class="kpi kpi-enlace" :class="{ alerta: r.inventario.stock_bajo }">
            <span class="kpi-etq">Stock bajo</span>
            <strong class="kpi-num" :class="r.inventario.stock_bajo ? 'rojo' : 'verde'">{{ r.inventario.stock_bajo }}</strong>
            <span class="kpi-var neutro">{{ r.inventario.unidades }} unidades · {{ $bs(r.inventario.valor) }} en almacén</span>
          </router-link>
          <router-link v-if="r.promociones_vigentes !== undefined" to="/promociones" class="kpi kpi-enlace">
            <span class="kpi-etq">Promociones vigentes</span>
            <strong class="kpi-num">{{ r.promociones_vigentes }}</strong>
            <span class="kpi-var neutro">ver banner de ofertas</span>
          </router-link>
          <div v-if="usuarios" class="kpi">
            <span class="kpi-etq">Usuarios activos</span>
            <strong class="kpi-num">{{ usuarios.activos }}</strong>
            <span class="kpi-var neutro">de {{ usuarios.total }} registrados</span>
          </div>
          <template v-if="r.distribucion && !r.ventas">
            <router-link to="/despachos" class="kpi kpi-enlace"><span class="kpi-etq">Por preparar</span><strong class="kpi-num ambar">{{ r.distribucion.por_estado.En_preparacion }}</strong><span class="kpi-var neutro">listos para salir</span></router-link>
            <router-link to="/despachos" class="kpi kpi-enlace"><span class="kpi-etq">En camino</span><strong class="kpi-num">{{ r.distribucion.por_estado.En_camino }}</strong><span class="kpi-var neutro">en ruta ahora</span></router-link>
            <div class="kpi"><span class="kpi-etq">Entregados</span><strong class="kpi-num verde">{{ r.distribucion.por_estado.Entregado }}</strong><span class="kpi-var neutro">en total</span></div>
          </template>
        </div>

        <!-- ================= Ventas ================= -->
        <div v-if="r.ventas" class="panel-fila">
          <section class="tarjeta panel-grande">
            <div class="bloque-cabecera">
              <h2>Ingresos por día</h2>
              <router-link v-if="puedeInformes" to="/informes" class="btn btn-secundario btn-sm"><sc-icono nombre="grafico" :tam="15"/> Informe completo</router-link>
            </div>
            <sc-grafico-linea :datos="serie" formato="bs" @seleccionar="elegirDia"/>
            <p v-if="dia" class="detalle-dia">
              <strong>{{ fechaLarga(dia.dia) }}</strong>: {{ $bs(dia.total) }} en {{ dia.pedidos }} pedido(s).
              <router-link v-if="dia.pedidos && puedeVentas" :to="{ path: '/pedidos', query: { estado_pago: 'Pagado' } }">Ver pedidos pagados</router-link>
            </p>
            <p v-else class="sub">Pasa el mouse (o toca) sobre el gráfico para ver el detalle de cada día.</p>
          </section>
          <section class="tarjeta">
            <h2>Ventas por categoría</h2>
            <sc-dona :datos="r.ventas.por_categoria" formato="bs" titulo="Ingresos"/>
          </section>
        </div>

        <div v-if="r.ventas" class="panel-fila">
          <section class="tarjeta">
            <h2>Más vendidos</h2>
            <p class="sub">Por ingresos en los últimos {{ dias }} días</p>
            <sc-barras-h :datos="top" formato="bs" @seleccionar="irProducto"/>
          </section>
          <section class="tarjeta">
            <h2>Estado de los pedidos</h2>
            <p class="sub">Haz clic en un estado para ver esos pedidos</p>
            <div class="estados-clic">
              <button v-for="e in estadosEnvio" :key="e.clave" class="estado-clic" :disabled="!puedeVentas" @click="verPedidos(e.clave)">
                <strong>{{ e.n }}</strong><sc-estado tipo="envio" :valor="e.clave"/>
              </button>
            </div>
            <h3 class="mini-titulo">Pagos</h3>
            <div class="estados-clic">
              <button v-for="e in estadosPago" :key="e.clave" class="estado-clic" :disabled="!puedeVentas" @click="verPedidos(null, e.clave)">
                <strong>{{ e.n }}</strong><sc-estado tipo="pago" :valor="e.clave"/>
              </button>
            </div>
          </section>
        </div>

        <!-- ================= Repartidor / distribución ================= -->
        <section v-if="r.distribucion" class="tarjeta">
          <div class="bloque-cabecera">
            <h2>{{ r.ventas ? 'Entregas pendientes' : 'Mis entregas' }}</h2>
            <router-link to="/despachos" class="btn btn-secundario btn-sm">Ver todos los despachos</router-link>
          </div>
          <p v-if="!r.distribucion.activos.length" class="sub">No hay entregas pendientes. ¡Buen trabajo!</p>
          <ul class="entregas">
            <li v-for="d in r.distribucion.activos" :key="d.id">
              <div>
                <strong>{{ d.codigo_guia }}</strong> <sc-estado tipo="envio" :valor="d.estado"/>
                <p class="sub">{{ d.cliente }} · {{ d.direccion_envio }}, {{ d.ciudad }}</p>
                <small>Tel. {{ d.telefono }}</small>
              </div>
              <div v-if="puedeEntregar" class="acciones">
                <a class="btn btn-secundario btn-sm" :href="'tel:' + d.telefono"><sc-icono nombre="usuario" :tam="14"/> Llamar</a>
                <button class="btn btn-primario btn-sm" :disabled="avanzando === d.id" @click="avanzar(d)">
                  {{ d.estado === 'En_preparacion' ? 'Salir a entregar' : 'Marcar entregado' }}
                </button>
              </div>
            </li>
          </ul>
        </section>

        <!-- ================= Inventario ================= -->
        <section v-if="r.inventario && r.inventario.alertas.length" class="tarjeta">
          <div class="bloque-cabecera">
            <h2>Alertas de stock</h2>
            <router-link v-if="puedeInventario" to="/inventario" class="btn btn-secundario btn-sm">Ir a inventario</router-link>
          </div>
          <ul class="alertas-stock">
            <li v-for="a in r.inventario.alertas" :key="a.id">
              <div><strong>{{ a.nombre }}</strong> <small>{{ a.categoria }}</small></div>
              <div class="alerta-barra"><span :style="{ width: Math.min(100, a.stock_minimo ? a.stock_actual / a.stock_minimo * 100 : 0) + '%' }" :class="{ vacio: a.stock_actual === 0 }"></span></div>
              <span class="badge" :class="a.stock_actual === 0 ? 'badge-error' : 'badge-warn'">{{ a.stock_actual === 0 ? 'Agotado' : a.stock_actual + ' de ' + a.stock_minimo }}</span>
            </li>
          </ul>
        </section>

        <!-- ================= Últimos pedidos ================= -->
        <section v-if="r.ultimos_pedidos" class="panel-bloque">
          <div class="bloque-cabecera"><h2>Últimos pedidos</h2><router-link to="/pedidos" class="btn btn-secundario btn-sm">Ver todos</router-link></div>
          <div class="tabla-contenedor">
            <table class="tabla">
              <thead><tr><th>Pedido</th><th>Cliente</th><th>Productos</th><th>IMEI asignados</th><th>Total</th><th>Pago</th><th>Envío</th></tr></thead>
              <tbody>
                <tr v-if="!r.ultimos_pedidos.length"><td colspan="7" class="vacio">Todavía no hay pedidos.</td></tr>
                <tr v-for="p in r.ultimos_pedidos" :key="p.id" class="fila-clic" @click="$router.push('/pedidos/' + p.id)">
                  <td data-label="Pedido"><strong>#{{ p.id }}</strong><br><small>{{ fecha(p.fecha) }}</small></td>
                  <td data-label="Cliente">{{ p.cliente_nombre }}</td>
                  <td data-label="Productos">{{ p.productos || '—' }}</td>
                  <td data-label="IMEI" class="celda-imei">{{ p.imeis || '—' }}</td>
                  <td data-label="Total"><strong>{{ $bs(p.total) }}</strong></td>
                  <td data-label="Pago"><sc-estado tipo="pago" :valor="p.estado_pago"/></td>
                  <td data-label="Envío"><sc-estado tipo="envio" :valor="p.estado_envio"/></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <h2 class="subtitulo">Accesos rápidos</h2>
        <div class="accesos">
          <router-link v-for="m in accesos" :key="m.ruta" :to="m.ruta" class="acceso">
            <span class="acceso-icono"><sc-icono :nombre="m.icono" :tam="22"/></span>
            <span class="acceso-texto">{{ m.texto }}</span>
            <span class="badge" :class="m.nivel === 'total' ? 'badge-ok' : 'badge-info'">{{ m.nivel === 'total' ? 'Acceso total' : 'Solo lectura' }}</span>
          </router-link>
        </div>
      </div>`,
    data() {
        return { r: {}, usuarios: null, dias: 30, cargando: false, auto: false, timer: null, actualizado: null, ahora: Date.now(),
                 dia: null, avanzando: 0, reloj: null };
    },
    computed: {
        user() { return SC.store.user; },
        primerNombre() { return this.user.nombre.split(' ')[0]; },
        puedeInventario() { return !!SC.store.nivel('inventario'); },
        puedeInformes() { return !!SC.store.nivel('informes'); },
        puedeVentas() { return !!SC.store.nivel('ventas'); },
        puedeEntregar() { return SC.store.puedeEscribir('distribucion'); },
        saludo() {
            const h = new Date().getHours();
            return h < 12 ? 'Buenos días' : (h < 19 ? 'Buenas tardes' : 'Buenas noches');
        },
        hace() {
            if (!this.actualizado) return 'ahora';
            const s = Math.round((this.ahora - this.actualizado) / 1000);
            return s < 10 ? 'ahora mismo' : s < 90 ? 'hace ' + s + ' s' : 'hace ' + Math.round(s / 60) + ' min';
        },
        accesos() {
            return SC.MENU.filter(m => m.clave !== 'panel' && SC.store.nivel(m.clave)).map(m => ({ ...m, nivel: SC.store.nivel(m.clave) }));
        },
        serie() { return this.r.ventas ? this.r.ventas.serie.map(d => ({ etiqueta: d.dia, valor: d.total })) : []; },
        top() { return this.r.ventas ? this.r.ventas.top_productos.map(t => ({ etiqueta: t.marca + ' ' + t.modelo, valor: t.ingresos, id: t.id })) : []; },
        estadosEnvio() {
            const e = this.r.ventas ? this.r.ventas.por_estado_envio : {};
            return ['Pendiente', 'En_preparacion', 'En_camino', 'Entregado', 'Cancelado'].map(c => ({ clave: c, n: e[c] || 0 }));
        },
        estadosPago() {
            const e = this.r.ventas ? this.r.ventas.por_estado_pago : {};
            return ['Pagado', 'Pendiente', 'Rechazado'].map(c => ({ clave: c, n: e[c] || 0 }));
        },
        varIngresos() { return this.r.ventas ? SC.util.variacion(this.r.ventas.ingresos, this.r.ventas.ingresos_anterior) : null; },
        varPedidos() { return this.r.ventas ? SC.util.variacion(this.r.ventas.pedidos, this.r.ventas.pedidos_anterior) : null; }
    },
    async created() {
        try { this.dias = Number(localStorage.getItem('sc_dias')) || 30; } catch (e) { /* sin almacenamiento */ }
        this.reloj = setInterval(() => { this.ahora = Date.now(); }, 15000);
        this.cargar();
        if (SC.store.nivel('seguridad_usuarios')) {
            try {
                const [t, a] = await Promise.all([SC.api.get('/usuarios', { por_pagina: 1 }), SC.api.get('/usuarios', { por_pagina: 1, activo: 1 })]);
                this.usuarios = { total: t.meta.total, activos: a.meta.total };
            } catch (e) { /* el KPI es opcional */ }
        }
    },
    beforeUnmount() { clearInterval(this.timer); clearInterval(this.reloj); },
    methods: {
        fecha: SC.util.fecha,
        fechaLarga: d => SC.util.fecha(d).slice(0, 10),
        async cargar() {
            this.cargando = true;
            try {
                this.r = (await SC.api.get('/reportes/resumen', { dias: this.dias })).data;
                this.actualizado = Date.now(); this.ahora = Date.now();
                this.dia = null;
                SC.store.mensajesPendientes = this.r.mensajes_pendientes !== undefined ? this.r.mensajes_pendientes : SC.store.mensajesPendientes;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        cambiarDias(d) {
            this.dias = d;
            try { localStorage.setItem('sc_dias', String(d)); } catch (e) { /* opcional */ }
            this.cargar();
        },
        programar() {
            clearInterval(this.timer);
            if (this.auto) this.timer = setInterval(() => { if (document.visibilityState === 'visible') this.cargar(); }, 60000);
        },
        claseVar(v) { return v === null ? 'neutro' : (v >= 0 ? 'sube' : 'baja'); },
        textoVar(v) { return v === null ? 'Sin período anterior para comparar' : (v >= 0 ? '▲ ' : '▼ ') + Math.abs(v) + ' % vs. período anterior'; },
        elegirDia(i) { this.dia = this.r.ventas.serie[i]; },
        verPedidos(envio, pago) {
            const query = {};
            if (envio) query.estado_envio = envio;
            if (pago) query.estado_pago = pago;
            this.$router.push({ path: '/pedidos', query });
        },
        irProducto(i) {
            const t = this.top[i];
            if (t && t.id) this.$router.push('/tienda/' + t.id);
        },
        /** Repartidor: pasa la entrega al siguiente estado (el servidor valida la transición) */
        async avanzar(d) {
            this.avanzando = d.id;
            try {
                await SC.api.patch('/logistica/despachos/' + d.id + '/estado', { estado: d.estado === 'En_preparacion' ? 'En_camino' : 'Entregado' });
                SC.store.ok(d.estado === 'En_preparacion' ? 'Ruta iniciada.' : 'Entrega registrada. El cliente recibirá su mensaje.');
                await this.cargar();
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.avanzando = 0; }
        }
    }
};
