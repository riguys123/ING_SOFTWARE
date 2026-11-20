/*
 * Presentación tipo video de los celulares (/showcase).
 * Cada escena dura unos segundos: el celular vuela hacia la cámara, lanza rayos de luz sobre un
 * fondo espacial y aparecen su nombre, características y precio. Con pausa, anterior/siguiente y pantalla completa.
 */
SC.Presentacion = {
    props: { compacto: { type: Boolean, default: false } },
    template: `
      <div>
        <p v-if="cargando" class="sub">Preparando la presentación…</p>
        <p v-else-if="!escenas.length" class="tarjeta vacio-caja">Aún no hay celulares para presentar.</p>
        <section v-else class="showcase" :class="{ compacto }" ref="marco" aria-label="Presentación de celulares">
          <sc-escena :producto="actual" fondo="ciudad" entrada class="show-escena" :key="'e' + i">
            <div class="show-texto" :key="'t' + i">
              <span v-if="actual.descuento_pct > 0" class="badge-oferta grande show-oferta">-{{ actual.descuento_pct }}% OFERTA</span>
              <span v-else class="show-etiqueta">{{ i === 0 ? 'Nueva generación' : 'SmartCell presenta' }}</span>
              <small class="show-marca">{{ actual.marca }}</small>
              <h1 class="show-titulo"><span v-for="(w, k) in palabras" :key="k" :style="{ animationDelay: (0.5 + k * 0.12) + 's' }">{{ w }}</span></h1>
              <p class="show-desc">{{ actual.descripcion }}</p>
              <ul class="show-chips">
                <li v-for="(c, k) in chips" :key="c" :style="{ animationDelay: (1.2 + k * 0.15) + 's' }">{{ c }}</li>
              </ul>
              <div class="show-precio" style="animation-delay:1.9s">
                <sc-precio :p="actual" grande/>
              </div>
              <div class="show-acciones" style="animation-delay:2.1s">
                <router-link :to="'/tienda/' + actual.id" class="btn btn-primario">Ver producto</router-link>
                <button class="btn btn-secundario" @click="comprar" :disabled="!actual.disponible">Añadir al carrito</button>
              </div>
            </div>
          </sc-escena>
          <div class="show-barra">
            <button class="btn-icono" @click="anterior" aria-label="Anterior"><sc-icono nombre="volver" :tam="20"/></button>
            <button class="btn-icono show-play" @click="pausa = !pausa" :aria-label="pausa ? 'Reproducir' : 'Pausar'">{{ pausa ? '▶' : '❚❚' }}</button>
            <button class="btn-icono" @click="siguiente" aria-label="Siguiente"><sc-icono nombre="volver" :tam="20" style="transform:scaleX(-1)"/></button>
            <div class="show-progreso" role="tablist">
              <button v-for="(e, k) in escenas" :key="e.id" :class="{ hecho: k < i, activo: k === i }" @click="ir(k)" :aria-label="e.marca + ' ' + e.modelo">
                <i :style="{ width: k < i ? '100%' : (k === i ? avance + '%' : '0') }"></i>
              </button>
            </div>
            <button class="btn-icono" @click="pantallaCompleta" aria-label="Pantalla completa" title="Pantalla completa">⤢</button>
          </div>
        </section>
      </div>`,
    data() { return { escenas: [], i: 0, cargando: true, pausa: false, avance: 0, timer: null, DURACION: 8000 }; },
    computed: {
        actual() { return this.escenas[this.i]; },
        palabras() { return this.actual.modelo.split(' '); },
        chips() {
            const p = this.actual, c = [];
            if (p.procesador) c.push(p.procesador);
            const m = SC.util.specs(p); if (m) c.push(m);
            if (p.camara) c.push(p.camara.split('+')[0].trim() + ' cámara');
            if (p.bateria_mah) c.push(p.bateria_mah + ' mAh');
            return c.slice(0, 4);
        }
    },
    async created() {
        try {
            const r = await SC.api.get('/productos', { categoria: 'Teléfonos', orden: 'nuevo' });
            const todos = (r.data || []).filter(p => p.fotos && p.fotos.length);
            const lista = (window.SC_CONFIG && SC_CONFIG.PRESENTACION) || [];
            const elegidos = lista.map(n => todos.find(p => (p.marca + ' ' + p.modelo).toLowerCase() === n.toLowerCase())).filter(Boolean);
            this.escenas = (elegidos.length ? elegidos : todos).slice(0, 6);
        } catch (e) { this.escenas = []; }
        this.cargando = false;
        this.timer = setInterval(() => {
            if (this.pausa || !this.escenas.length) return;
            this.avance += 100 / (this.DURACION / 100);
            if (this.avance >= 100) this.siguiente();
        }, 100);
    },
    beforeUnmount() { clearInterval(this.timer); },
    methods: {
        ir(k) { this.i = k; this.avance = 0; },
        siguiente() { this.ir((this.i + 1) % this.escenas.length); },
        anterior() { this.ir((this.i - 1 + this.escenas.length) % this.escenas.length); },
        async comprar() { await SC.agregarAlCarrito(this.$router, this.actual.id, 1); },
        pantallaCompleta() {
            const el = this.$refs.marco;
            if (document.fullscreenElement) document.exitFullscreen(); else if (el && el.requestFullscreen) el.requestFullscreen();
        }
    }
};


/* Página completa de la presentación */
SC.views.Showcase = {
    template: `
      <div>
        <router-link to="/tienda" class="volver"><sc-icono nombre="volver" :tam="16"/> Volver al catálogo</router-link>
        <sc-presentacion/>
      </div>`
};
