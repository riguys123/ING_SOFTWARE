/*
 * Escena espacial: fondo de estrellas animadas (canvas), nebulosa, planeta con anillo,
 * rayos de luz que salen del celular y un celular 3D que se inclina con el mouse.
 * Se usa en la presentación (/showcase) y en el detalle de cada producto.
 * Respeta "reducir movimiento" del sistema: sin animaciones si el usuario lo pidió.
 */
SC.Escena = {
    props: {
        producto: { type: Object, default: null },
        foto: { type: Number, default: 0 },
        entrada: { type: Boolean, default: false },   // el celular "vuela" hacia la cámara al aparecer
        compacta: { type: Boolean, default: false },
        fondo: { type: String, default: 'espacio' }      // 'espacio' o 'ciudad' (ciudad de noche)
    },
    data() { return { rx: 0, ry: 0, raf: 0, estrellas: [], fugaces: [], t: 0 }; },
    computed: {
        src() { const f = this.producto && this.producto.fotos; return f && f.length ? (f[this.foto] || f[0]) : null; },
        /** Foto real recortada del celular (assets/img/telefonos/...): se muestra tal cual, en 3D */
        esReal() { return !!this.src && this.src.indexOf('assets/img/telefonos/') === 0; },
        esCelular() { return !!this.producto && (this.producto.categoria === 'Teléfonos' || !!this.producto.requiere_imei); },
        matiz() {
            let h = 0;
            for (const c of ((this.producto && this.producto.marca) || 'X')) h = (h * 31 + c.charCodeAt(0)) % 360;
            return h;
        },
        estilo() {
            const e = { '--h': this.matiz, '--rx': this.rx + 'deg', '--ry': this.ry + 'deg' };
            if (this.fondo === 'ciudad') e.backgroundImage = 'url("assets/img/ciudad-' + (1 + ((this.producto ? this.producto.id : 0) % 2)) + '.jpg")';
            return e;
        }
    },
    mounted() {
        this.reducido = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.ajustar();
        this._ro = new ResizeObserver(() => this.ajustar());
        this._ro.observe(this.$refs.raiz);
        this.pintar();
    },
    beforeUnmount() { cancelAnimationFrame(this.raf); if (this._ro) this._ro.disconnect(); },
    methods: {
        ajustar() {
            const c = this.$refs.lienzo, r = this.$refs.raiz.getBoundingClientRect();
            const d = Math.min(window.devicePixelRatio || 1, 2);
            c.width = Math.max(1, r.width * d); c.height = Math.max(1, r.height * d);
            this.d = d; this.w = c.width; this.h = c.height;
            const n = Math.round((r.width * r.height) / 5200);
            this.estrellas = Array.from({ length: Math.min(n, 220) }, () => ({
                x: Math.random() * this.w, y: Math.random() * this.h,
                z: Math.random() * 0.9 + 0.1, f: Math.random() * 6.28
            }));
        },
        pintar() {
            const c = this.$refs.lienzo; if (!c) return;
            const g = c.getContext('2d');
            const marco = () => {
                this.t += 0.016;
                g.clearRect(0, 0, this.w, this.h);
                for (const e of this.estrellas) {
                    if (!this.reducido) { e.x -= e.z * 0.18 * this.d; if (e.x < 0) e.x = this.w; }
                    const br = 0.45 + 0.55 * Math.sin(this.t * (1 + e.z * 2) + e.f);
                    g.globalAlpha = Math.max(0.12, br * e.z);
                    g.fillStyle = e.z > 0.8 ? '#bfe3ff' : '#ffffff';
                    g.beginPath(); g.arc(e.x, e.y, e.z * 1.5 * this.d, 0, 6.28); g.fill();
                }
                // estrellas fugaces ocasionales
                if (!this.reducido && Math.random() < 0.006 && this.fugaces.length < 2) {
                    this.fugaces.push({ x: Math.random() * this.w, y: Math.random() * this.h * 0.5, v: 7 * this.d, vida: 1 });
                }
                this.fugaces = this.fugaces.filter(s => s.vida > 0);
                for (const s of this.fugaces) {
                    const gr = g.createLinearGradient(s.x, s.y, s.x - 90 * this.d, s.y - 45 * this.d);
                    gr.addColorStop(0, 'rgba(255,255,255,' + s.vida + ')'); gr.addColorStop(1, 'rgba(255,255,255,0)');
                    g.globalAlpha = 1; g.strokeStyle = gr; g.lineWidth = 2 * this.d;
                    g.beginPath(); g.moveTo(s.x, s.y); g.lineTo(s.x - 90 * this.d, s.y - 45 * this.d); g.stroke();
                    s.x += s.v; s.y += s.v * 0.5; s.vida -= 0.018;
                }
                g.globalAlpha = 1;
                if (!this.reducido) this.raf = requestAnimationFrame(marco);
            };
            marco();
        },
        mover(ev) {
            if (this.reducido) return;
            const r = this.$refs.raiz.getBoundingClientRect();
            this.ry = ((ev.clientX - r.left) / r.width - 0.5) * 34;
            this.rx = -((ev.clientY - r.top) / r.height - 0.5) * 22;
        },
        soltar() { this.rx = 0; this.ry = 0; }
    },
    template: `
      <div class="escena" :class="{ compacta, ciudad: fondo === 'ciudad' }" :style="estilo" ref="raiz" @mousemove="mover" @mouseleave="soltar">
        <canvas ref="lienzo" class="esc-estrellas" aria-hidden="true"></canvas>
        <template v-if="fondo !== 'ciudad'">
          <div class="esc-nebulosa" aria-hidden="true"></div>
          <div class="esc-planeta" aria-hidden="true"><i></i></div>
          <div class="esc-luna" aria-hidden="true"></div>
        </template>
        <div v-else class="esc-velo" aria-hidden="true"></div>
        <div class="esc-centro" :key="producto ? producto.id : 0">
          <div class="esc-rayos a" aria-hidden="true"></div>
          <div class="esc-rayos b" aria-hidden="true"></div>
          <div class="esc-halo" aria-hidden="true"></div>
          <span class="esc-onda" aria-hidden="true"></span><span class="esc-onda dos" aria-hidden="true"></span>
          <div class="esc-giro" :class="{ entra: entrada }">
            <div v-if="esReal" class="esc-real">
              <img :src="src" :alt="producto.marca + ' ' + producto.modelo" class="esc-real-img">
              <img :src="src" alt="" class="esc-real-brillo" aria-hidden="true">
            </div>
            <div v-else-if="esCelular" class="esc-movil">
              <div class="esc-cuerpo">
                <div class="esc-pantalla">
                  <img v-if="src" :src="src" :alt="producto.marca + ' ' + producto.modelo">
                  <div v-else class="esc-pantalla-vacia">{{ producto ? producto.marca : '' }}</div>
                  <span class="esc-reflejo"></span>
                </div>
                <span class="esc-isla"></span>
                <span class="esc-boton b1"></span><span class="esc-boton b2"></span>
              </div>
            </div>
            <div v-else class="esc-objeto">
              <img v-if="src" :src="src" :alt="producto ? producto.modelo : ''">
            </div>
            <div class="esc-sombra"></div>
          </div>
        </div>
        <slot></slot>
      </div>`
};
