/* Módulo de informes: ventas, productos, inventario, pedidos y clientes, con gráficos, tabla y descarga CSV */
SC.views.Informes = {
    template: `
      <div>
        <div class="encabezado-pagina no-imprimir">
          <div><h1>Informes</h1><p class="sub">Reportes con rango de fechas, gráficos y descarga en CSV (abre en Excel).</p></div>
          <div class="acciones">
            <a class="btn btn-secundario" :href="csv"><sc-icono nombre="descargar" :tam="16"/> Descargar CSV</a>
            <button class="btn btn-secundario" @click="imprimir"><sc-icono nombre="imprimir" :tam="16"/> Imprimir</button>
          </div>
        </div>

        <div class="segmentado no-imprimir" role="tablist">
          <button v-for="t in tabs" :key="t.id" role="tab" :class="{ activo: tipo === t.id }" @click="elegir(t.id)">{{ t.nombre }}</button>
        </div>

        <div class="filtros no-imprimir">
          <template v-if="tipo !== 'inventario'">
            <div class="campo"><label for="id">Desde</label><input id="id" type="date" v-model="desde" :max="hasta" @change="cargar"></div>
            <div class="campo"><label for="ih">Hasta</label><input id="ih" type="date" v-model="hasta" :min="desde" @change="cargar"></div>
            <div class="campo"><label>Rango rápido</label>
              <div class="segmentado">
                <button v-for="d in [7, 30, 90]" :key="d" @click="rapido(d)">{{ d }} días</button>
              </div>
            </div>
          </template>
          <div v-if="tipo === 'ventas'" class="campo">
            <label for="ia">Agrupar por</label>
            <select id="ia" v-model="agrupar" @change="cargar"><option value="dia">Día</option><option value="semana">Semana</option><option value="mes">Mes</option></select>
          </div>
        </div>

        <p v-if="cargando && !inf" class="sub">Generando informe…</p>
        <div v-if="inf" :class="{ 'cargando-velo': cargando }">
          <h2 class="informe-titulo">{{ inf.titulo }} <small v-if="tipo !== 'inventario'">{{ inf.desde }} → {{ inf.hasta }}</small></h2>

          <div class="kpis">
            <div v-for="k in inf.kpis" :key="k.etiqueta" class="kpi">
              <span class="kpi-etq">{{ k.etiqueta }}</span>
              <span class="kpi-num">{{ fmt(k.valor, k.tipo) }}</span>
            </div>
          </div>

          <div v-if="inf.graficos.length" class="panel-fila">
            <div v-for="g in inf.graficos" :key="g.titulo" class="tarjeta" :class="{ 'panel-grande': g.tipo === 'barras' }">
              <h3>{{ g.titulo }}</h3>
              <sc-grafico-barras v-if="g.tipo === 'barras'" :datos="g.datos" :formato="formatoGrafico(g)"/>
              <sc-barras-h v-else-if="g.tipo === 'barras_h'" :datos="g.datos" :formato="formatoGrafico(g)"/>
              <sc-dona v-else :datos="g.datos" :formato="formatoGrafico(g)"/>
            </div>
          </div>

          <div class="tabla-contenedor">
            <table class="tabla">
              <thead><tr><th v-for="c in inf.columnas" :key="c.clave" :class="{ der: c.tipo === 'dinero' || c.tipo === 'numero' }">{{ c.titulo }}</th></tr></thead>
              <tbody>
                <tr v-if="!inf.filas.length"><td :colspan="inf.columnas.length" class="vacio">Sin datos en este rango.</td></tr>
                <tr v-for="(f, i) in inf.filas" :key="i">
                  <td v-for="c in inf.columnas" :key="c.clave" :data-label="c.titulo" :class="{ der: c.tipo === 'dinero' || c.tipo === 'numero' }">{{ fmt(f[c.clave], c.tipo) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>`,
    data() {
        const hoy = new Date(), ini = new Date(Date.now() - 29 * 864e5);
        const f = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        return {
            tipo: 'ventas', desde: f(ini), hasta: f(hoy), agrupar: 'dia', inf: null, cargando: false, f,
            tabs: [{ id: 'ventas', nombre: 'Ventas' }, { id: 'productos', nombre: 'Productos' }, { id: 'inventario', nombre: 'Inventario' },
                   { id: 'pedidos', nombre: 'Pedidos' }, { id: 'clientes', nombre: 'Clientes' }]
        };
    },
    computed: {
        consulta() { return { desde: this.desde, hasta: this.hasta, agrupar: this.tipo === 'ventas' ? this.agrupar : '' }; },
        csv() { return SC.api.url('/informes/' + this.tipo, { ...this.consulta, formato: 'csv' }); }
    },
    created() { this.cargar(); },
    methods: {
        fmt: SC.util.valor,
        elegir(t) { this.tipo = t; this.inf = null; this.cargar(); },
        rapido(d) {
            const h = new Date();
            this.hasta = this.f(h);
            this.desde = this.f(new Date(h.getTime() - (d - 1) * 864e5));
            this.cargar();
        },
        /** Los gráficos de dinero muestran Bs; los de cantidades, números */
        formatoGrafico(g) { return /ingreso|venta|valor|total|monto/i.test(g.titulo) ? 'bs' : 'num'; },
        imprimir() { window.print(); },
        async cargar() {
            if (this.desde > this.hasta) return SC.store.error('La fecha inicial no puede ser mayor que la final.');
            this.cargando = true;
            const tipo = this.tipo;
            try {
                const r = await SC.api.get('/informes/' + tipo, this.consulta);
                if (tipo === this.tipo) this.inf = r.data;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        }
    }
};
