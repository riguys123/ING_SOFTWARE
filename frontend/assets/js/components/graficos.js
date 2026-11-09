/*
 * Gráficos interactivos en SVG/HTML puro (sin librerías): se adaptan al ancho, muestran un
 * tooltip al pasar el mouse (o tocar) y avisan con eventos cuando el usuario hace clic.
 *
 *   <sc-grafico-linea :datos="[{etiqueta:'2026-11-01', valor:120}, ...]" formato="bs" @seleccionar="i => ..."/>
 *   <sc-grafico-barras :datos="..." formato="bs"/>            barras verticales (series largas)
 *   <sc-barras-h :datos="..." formato="bs"/>                  ranking horizontal
 *   <sc-dona :datos="..." formato="bs"/>                      reparto por categoría / método
 */
SC.COLORES = ['#35c48c', '#5aa0e6', '#e0a93b', '#b58cf0', '#e3654a', '#4fd1d9', '#f08cc0', '#8b96a5'];

/** Formatea un valor según el tipo del gráfico */
SC.fmtValor = (v, formato) => formato === 'bs' ? SC.util.bs(v) : Number(v).toLocaleString('es-BO');

/** Escala "bonita" para el eje Y: devuelve el máximo redondeado */
SC.escalaMax = function (max) {
    if (max <= 0) return 1;
    const exp = Math.pow(10, Math.floor(Math.log10(max)));
    const f = max / exp;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * exp;
};

/* ------------------------------------------------------------------ línea / área */
SC.GraficoLinea = {
    props: {
        datos: { type: Array, default: () => [] },
        formato: { type: String, default: 'bs' },
        alto: { type: Number, default: 230 },
        etiquetaFn: { type: Function, default: null }     // cómo mostrar la etiqueta del eje X
    },
    emits: ['seleccionar'],
    data() { return { activo: -1, W: 640, mi: 52, md: 14, ms: 14, mb: 30 }; },
    computed: {
        H() { return this.alto; },
        max() { return SC.escalaMax(Math.max(0, ...this.datos.map(d => d.valor))); },
        ancho() { return this.W - this.mi - this.md; },
        altoUtil() { return this.H - this.ms - this.mb; },
        puntos() {
            const n = this.datos.length;
            return this.datos.map((d, i) => ({
                x: this.mi + (n <= 1 ? this.ancho / 2 : i * this.ancho / (n - 1)),
                y: this.ms + this.altoUtil - (d.valor / this.max) * this.altoUtil
            }));
        },
        linea() { return this.puntos.map((p, i) => (i ? 'L' : 'M') + p.x.toFixed(1) + ' ' + p.y.toFixed(1)).join(' '); },
        area() {
            if (!this.puntos.length) return '';
            const base = this.ms + this.altoUtil;
            const u = this.puntos[this.puntos.length - 1], p = this.puntos[0];
            return this.linea + ' L' + u.x.toFixed(1) + ' ' + base + ' L' + p.x.toFixed(1) + ' ' + base + ' Z';
        },
        ticks() {
            return [0, 1, 2, 3, 4].map(i => ({
                y: this.ms + this.altoUtil - i * this.altoUtil / 4,
                texto: this.formato === 'bs' ? this.corto(this.max * i / 4) : Math.round(this.max * i / 4)
            }));
        },
        /** Etiquetas del eje X: unas 6 para que no se encimen */
        etiquetas() {
            const n = this.datos.length, paso = Math.max(1, Math.ceil(n / 6));
            return this.datos.map((d, i) => ({ i, x: this.puntos[i].x, texto: this.etq(d.etiqueta) })).filter(e => e.i % paso === 0 || e.i === n - 1);
        },
        tip() {
            if (this.activo < 0 || !this.datos[this.activo]) return null;
            const p = this.puntos[this.activo];
            return { izq: (p.x / this.W * 100), arr: (p.y / this.H * 100), etiqueta: this.etq(this.datos[this.activo].etiqueta, true),
                     valor: SC.fmtValor(this.datos[this.activo].valor, this.formato) };
        },
        vacio() { return !this.datos.length || this.datos.every(d => !d.valor); }
    },
    methods: {
        corto(v) { return v >= 1000 ? (v / 1000).toFixed(v % 1000 ? 1 : 0).replace('.0', '') + 'k' : String(Math.round(v)); },
        etq(e, larga) {
            if (this.etiquetaFn) return this.etiquetaFn(e, larga);
            return /^\d{4}-\d{2}-\d{2}$/.test(e) ? (larga ? SC.util.fecha(e) : SC.util.diaCorto(e)) : e;
        },
        mover(ev) {
            const r = this.$refs.svg.getBoundingClientRect();
            const x = ((ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left) / r.width * this.W;
            let mejor = 0, dist = Infinity;
            this.puntos.forEach((p, i) => { const d = Math.abs(p.x - x); if (d < dist) { dist = d; mejor = i; } });
            this.activo = mejor;
        }
    },
    template: `
      <div class="grafico">
        <p v-if="vacio" class="grafico-vacio">Sin ventas en este período.</p>
        <div v-show="!vacio" class="grafico-caja">
          <svg ref="svg" :viewBox="'0 0 ' + W + ' ' + H" class="grafico-svg"
               @mousemove="mover" @touchstart.passive="mover" @touchmove.passive="mover" @mouseleave="activo = -1"
               @click="activo >= 0 && $emit('seleccionar', activo)" role="img" aria-label="Gráfico de líneas">
            <defs><linearGradient id="gl-area" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="#35c48c" stop-opacity=".35"/><stop offset="1" stop-color="#35c48c" stop-opacity="0"/>
            </linearGradient></defs>
            <g v-for="t in ticks" :key="t.y">
              <line :x1="mi" :x2="W - md" :y1="t.y" :y2="t.y" stroke="#2a3240" stroke-width="1"/>
              <text :x="mi - 8" :y="t.y + 4" text-anchor="end" class="eje">{{ t.texto }}</text>
            </g>
            <path :d="area" fill="url(#gl-area)"/>
            <path :d="linea" fill="none" stroke="#35c48c" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
            <text v-for="e in etiquetas" :key="e.i" :x="e.x" :y="H - 8" text-anchor="middle" class="eje">{{ e.texto }}</text>
            <template v-if="activo >= 0 && puntos[activo]">
              <line :x1="puntos[activo].x" :x2="puntos[activo].x" :y1="ms" :y2="ms + altoUtil" stroke="#8b96a5" stroke-dasharray="3 3"/>
              <circle :cx="puntos[activo].x" :cy="puntos[activo].y" r="5" fill="#14181f" stroke="#35c48c" stroke-width="3"/>
            </template>
          </svg>
          <div v-if="tip" class="grafico-tip" :style="{ left: tip.izq + '%', top: tip.arr + '%' }">
            <small>{{ tip.etiqueta }}</small><strong>{{ tip.valor }}</strong>
          </div>
        </div>
      </div>`
};

/* ----------------------------------------------------------------------- barras */
SC.GraficoBarras = {
    props: {
        datos: { type: Array, default: () => [] },
        formato: { type: String, default: 'bs' },
        alto: { type: Number, default: 220 },
        etiquetaFn: { type: Function, default: null }
    },
    emits: ['seleccionar'],
    data() { return { activo: -1 }; },
    computed: {
        max() { return SC.escalaMax(Math.max(0, ...this.datos.map(d => d.valor))); },
        paso() { return Math.max(1, Math.ceil(this.datos.length / 8)); },
        vacio() { return !this.datos.length || this.datos.every(d => !d.valor); }
    },
    methods: {
        etq(e) {
            if (this.etiquetaFn) return this.etiquetaFn(e);
            if (/^\d{4}-\d{2}-\d{2}$/.test(e)) return SC.util.diaCorto(e);
            if (/^\d{4}-\d{2}$/.test(e)) return e.slice(5) + '/' + e.slice(2, 4);
            return e;
        },
        tituloBarra(d) {
            const e = /^\d{4}-\d{2}-\d{2}$/.test(d.etiqueta) ? SC.util.fecha(d.etiqueta) : d.etiqueta;
            return e + ': ' + SC.fmtValor(d.valor, this.formato);
        }
    },
    template: `
      <div class="grafico">
        <p v-if="vacio" class="grafico-vacio">Sin datos en este período.</p>
        <div v-else class="barras-v" :style="{ height: alto + 'px' }">
          <div v-for="(d, i) in datos" :key="i" class="barra-v" :class="{ activa: activo === i }"
               @mouseenter="activo = i" @mouseleave="activo = -1" @click="$emit('seleccionar', i)" :title="tituloBarra(d)">
            <span class="barra-v-tip" v-if="activo === i">{{ tituloBarra(d) }}</span>
            <div class="barra-v-pista"><div class="barra-v-rell" :style="{ height: Math.max(d.valor / max * 100, d.valor ? 2 : 0) + '%' }"></div></div>
            <span class="barra-v-etq">{{ i % paso === 0 ? etq(d.etiqueta) : '' }}</span>
          </div>
        </div>
      </div>`
};

/* ------------------------------------------------------------- barras horizontales */
SC.BarrasH = {
    props: { datos: { type: Array, default: () => [] }, formato: { type: String, default: 'bs' } },
    emits: ['seleccionar'],
    computed: {
        max() { return Math.max(1, ...this.datos.map(d => d.valor)); },
        vacio() { return !this.datos.length; }
    },
    methods: { fmt(v) { return SC.fmtValor(v, this.formato); } },
    template: `
      <div class="grafico">
        <p v-if="vacio" class="grafico-vacio">Sin datos en este período.</p>
        <ul v-else class="barras-h">
          <li v-for="(d, i) in datos" :key="i" @click="$emit('seleccionar', i)">
            <span class="barra-h-etq" :title="d.etiqueta">{{ d.etiqueta }}</span>
            <span class="barra-h-pista"><span class="barra-h-rell" :style="{ width: Math.max(d.valor / max * 100, 2) + '%', background: $options.color(i) }"></span></span>
            <strong class="barra-h-val">{{ fmt(d.valor) }}</strong>
          </li>
        </ul>
      </div>`,
    color: i => SC.COLORES[i % SC.COLORES.length]
};

/* ----------------------------------------------------------------------- dona */
SC.Dona = {
    props: { datos: { type: Array, default: () => [] }, formato: { type: String, default: 'bs' }, titulo: String },
    emits: ['seleccionar'],
    data() { return { activo: -1 }; },
    computed: {
        total() { return this.datos.reduce((s, d) => s + d.valor, 0); },
        R() { return 52; },
        circ() { return 2 * Math.PI * this.R; },
        arcos() {
            let acum = 0;
            return this.datos.map((d, i) => {
                const frac = this.total ? d.valor / this.total : 0;
                const a = { i, largo: frac * this.circ, desfase: -acum * this.circ, color: SC.COLORES[i % SC.COLORES.length],
                            pct: Math.round(frac * 100) };
                acum += frac;
                return a;
            });
        },
        centro() {
            const d = this.datos[this.activo];
            return d ? { t: SC.fmtValor(d.valor, this.formato), s: d.etiqueta } : { t: SC.fmtValor(this.total, this.formato), s: this.titulo || 'Total' };
        },
        vacio() { return !this.datos.length || !this.total; }
    },
    methods: { fmt(v) { return SC.fmtValor(v, this.formato); } },
    template: `
      <div class="grafico">
        <p v-if="vacio" class="grafico-vacio">Sin datos en este período.</p>
        <div v-else class="dona">
          <div class="dona-svg">
            <svg viewBox="0 0 140 140" role="img" :aria-label="titulo || 'Gráfico de dona'">
              <circle cx="70" cy="70" :r="R" fill="none" stroke="#2a3240" stroke-width="18"/>
              <circle v-for="a in arcos" :key="a.i" cx="70" cy="70" :r="R" fill="none" :stroke="a.color"
                      :stroke-width="activo === a.i ? 22 : 18" :stroke-dasharray="a.largo + ' ' + (circ - a.largo)"
                      :stroke-dashoffset="a.desfase" transform="rotate(-90 70 70)" class="dona-arco"
                      :opacity="activo < 0 || activo === a.i ? 1 : .35"
                      @mouseenter="activo = a.i" @mouseleave="activo = -1" @click="$emit('seleccionar', a.i)"/>
            </svg>
            <div class="dona-centro"><strong>{{ centro.t }}</strong><small>{{ centro.s }}</small></div>
          </div>
          <ul class="dona-leyenda">
            <li v-for="(d, i) in datos" :key="i" :class="{ activo: activo === i }" @mouseenter="activo = i" @mouseleave="activo = -1" @click="$emit('seleccionar', i)">
              <span class="punto" :style="{ background: arcos[i].color }"></span>
              <span class="dona-nombre">{{ d.etiqueta }}</span>
              <span class="dona-valor">{{ fmt(d.valor) }} <small>{{ arcos[i].pct }}%</small></span>
            </li>
          </ul>
        </div>
      </div>`
};
