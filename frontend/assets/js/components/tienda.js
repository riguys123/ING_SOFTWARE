/* Componentes de la tienda: foto del producto, insignia de estado y barra superior */

/* Foto del producto. Si no hay URL (o falla), dibuja un celular con el color de la marca. */
SC.Foto = {
    props: { fotos: { type: Array, default: () => [] }, marca: String, modelo: String, indice: { type: Number, default: 0 } },
    data() { return { fallo: false }; },
    watch: { indice() { this.fallo = false; }, fotos() { this.fallo = false; } },
    computed: {
        src() { return !this.fallo && this.fotos && this.fotos.length ? (this.fotos[this.indice] || this.fotos[0]) : null; },
        matiz() {
            let h = 0;
            for (const c of (this.marca || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
            return h;
        },
        fondo() { return 'hsl(' + this.matiz + ', 38%, 24%)'; },
        brillo() { return 'hsl(' + this.matiz + ', 62%, 56%)'; },
        inicial() { return (this.marca || '?').charAt(0).toUpperCase(); }
    },
    template: `
      <div class="foto">
        <img v-if="src" :src="src" :alt="marca + ' ' + modelo" loading="lazy" @error="fallo = true">
        <svg v-else viewBox="0 0 120 200" role="img" :aria-label="marca + ' ' + modelo">
          <rect x="22" y="8" width="76" height="184" rx="14" :fill="fondo" stroke="#36404f" stroke-width="2"/>
          <rect x="28" y="20" width="64" height="152" rx="8" fill="#14181f"/>
          <circle cx="60" cy="14" r="2.5" fill="#36404f"/>
          <circle cx="60" cy="92" r="26" :fill="brillo" opacity=".18"/>
          <text x="60" y="102" text-anchor="middle" font-size="30" font-weight="700" :fill="brillo">{{ inicial }}</text>
          <rect x="48" y="180" width="24" height="3" rx="1.5" fill="#36404f"/>
        </svg>
      </div>`
};

/* Insignia de estado: <sc-estado tipo="pago" valor="Pagado"/> */
SC.Estado = {
    props: { tipo: String, valor: String },
    computed: {
        info() {
            const e = (SC.util.ESTADOS[this.tipo] || {})[this.valor];
            return e || [this.valor, 'badge-rol'];
        }
    },
    template: `<span class="badge" :class="info[1]">{{ info[0] }}</span>`
};

/* Barra superior de la tienda (visible para clientes, visitantes y personal que visita la tienda) */
SC.TiendaBar = {
    template: `
      <header class="tbar">
        <div class="tbar-in">
          <router-link to="/tienda" class="tbar-marca">Smart<span>Cell</span></router-link>
          <nav class="tbar-links">
            <router-link to="/tienda">Catálogo</router-link>
            <router-link v-if="store.esCliente" to="/mis-pedidos">Mis pedidos</router-link>
            <router-link v-if="store.user && !store.esCliente" to="/panel">Ir al panel</router-link>
          </nav>
          <div class="tbar-acciones">
            <router-link v-if="!store.user || store.esCliente" to="/carrito" class="tbar-carrito" aria-label="Carrito de compras">
              <sc-icono nombre="carrito" :tam="22"/>
              <span v-if="cart.unidades" class="contador">{{ cart.unidades }}</span>
            </router-link>
            <template v-if="store.user">
              <router-link to="/perfil" class="avatar" :title="store.user.nombre">{{ iniciales }}</router-link>
              <button class="btn-icono" @click="salir" title="Cerrar sesión" aria-label="Cerrar sesión"><sc-icono nombre="salir"/></button>
            </template>
            <template v-else>
              <router-link to="/login" class="btn btn-secundario btn-sm">Ingresar</router-link>
              <router-link to="/registro" class="btn btn-primario btn-sm tbar-registro">Crear cuenta</router-link>
            </template>
          </div>
        </div>
      </header>`,
    computed: {
        store() { return SC.store; },
        cart() { return SC.cart; },
        iniciales() { return SC.util.iniciales(SC.store.user.nombre); }
    },
    methods: {
        async salir() {
            await SC.store.cerrarSesion();
            SC.store.ok('Sesión cerrada.');
            this.$router.replace('/tienda');
        }
    }
};

/* Precio con promoción: <sc-precio :p="producto"/> (usa precio, precio_final y descuento_pct) */
SC.Precio = {
    props: { p: Object, grande: Boolean },
    template: `
      <span class="precio-caja" :class="{ grande }">
        <strong class="precio">{{ $bs(p.precio_final !== undefined ? p.precio_final : p.precio) }}</strong>
        <template v-if="p.descuento_pct > 0">
          <s class="precio-lista">{{ $bs(p.precio) }}</s>
          <span class="badge-oferta">-{{ p.descuento_pct }}%</span>
        </template>
      </span>`
};

/* Carrusel del banner de ofertas (promociones vigentes) */
SC.Banner = {
    props: { promos: { type: Array, default: () => [] } },
    emits: ['ir'],
    data() { return { i: 0, pausa: false, timer: null }; },
    computed: { actual() { return this.promos[this.i] || null; } },
    mounted() { this.timer = setInterval(() => { if (!this.pausa && this.promos.length > 1) this.siguiente(); }, 6000); },
    beforeUnmount() { clearInterval(this.timer); },
    watch: { promos() { this.i = 0; } },
    methods: {
        siguiente() { this.i = (this.i + 1) % this.promos.length; },
        anterior() { this.i = (this.i - 1 + this.promos.length) % this.promos.length; },
        fondo(p) { return p.imagen ? { backgroundImage: 'url("' + p.imagen + '")' } : {}; },
        vigencia(p) { return 'Hasta el ' + SC.util.fecha(p.fecha_fin).slice(0, 5); }
    },
    template: `
      <section v-if="actual" class="banner" @mouseenter="pausa = true" @mouseleave="pausa = false" aria-roledescription="carrusel" aria-label="Ofertas">
        <transition name="fundido" mode="out-in">
          <div :key="actual.id" class="banner-slide" :style="fondo(actual)" role="group">
            <div class="banner-texto">
              <span v-if="actual.descuento_pct > 0" class="badge-oferta grande">-{{ actual.descuento_pct }}%</span>
              <h2>{{ actual.titulo }}</h2>
              <p v-if="actual.descripcion">{{ actual.descripcion }}</p>
              <small>{{ vigencia(actual) }}</small>
              <button class="btn btn-primario" @click="$emit('ir', actual)">{{ actual.producto_id ? 'Ver producto' : 'Ver ofertas' }}</button>
            </div>
          </div>
        </transition>
        <template v-if="promos.length > 1">
          <button class="banner-flecha izq" @click="anterior" aria-label="Anterior"><sc-icono nombre="volver" :tam="20"/></button>
          <button class="banner-flecha der" @click="siguiente" aria-label="Siguiente"><sc-icono nombre="volver" :tam="20" style="transform:scaleX(-1)"/></button>
          <div class="banner-puntos">
            <button v-for="(p, k) in promos" :key="p.id" :class="{ activo: k === i }" @click="i = k" :aria-label="'Oferta ' + (k + 1)"></button>
          </div>
        </template>
      </section>`
};

/*
 * Fotos de un producto: subir (se reducen en el navegador antes de enviarse), quitar y elegir la principal.
 * Se usa con v-model: <sc-subir-fotos v-model="form.fotos" :producto-id="form.id"/>
 * Subir y quitar solo cambian la lista; "Guardar" del formulario la confirma.
 */
SC.SubirFotos = {
    props: { modelValue: { type: Array, default: () => [] }, productoId: Number, max: { type: Number, default: 5 } },
    emits: ['update:modelValue'],
    data() { return { subiendo: false, arrastrando: false }; },
    methods: {
        async elegir(ev) { await this.procesar([...ev.target.files]); ev.target.value = ''; },
        async soltar(ev) { this.arrastrando = false; await this.procesar([...ev.dataTransfer.files]); },
        async procesar(archivos) {
            if (!this.productoId) return SC.store.info('Guarda el producto primero y luego añade las fotos.');
            this.subiendo = true;
            let lista = [...this.modelValue];
            try {
                for (const a of archivos) {
                    if (lista.length >= this.max) { SC.store.info('Máximo ' + this.max + ' fotos por producto.'); break; }
                    const blob = await SC.util.reducirImagen(a);
                    const r = await SC.api.subir('/productos/' + this.productoId + '/fotos', 'foto', blob);
                    lista = r.data.fotos;
                }
                this.$emit('update:modelValue', lista);
                SC.store.ok('Foto subida.');
            } catch (e) { SC.store.error(e.mensaje || e.message); }
            finally { this.subiendo = false; }
        },
        quitar(k) { this.$emit('update:modelValue', this.modelValue.filter((_, i) => i !== k)); },
        principal(k) {
            const l = [...this.modelValue];
            l.unshift(l.splice(k, 1)[0]);
            this.$emit('update:modelValue', l);
        }
    },
    template: `
      <div class="fotos-edit">
        <div class="fotos-lista">
          <div v-for="(f, k) in modelValue" :key="f" class="foto-item" :class="{ principal: k === 0 }">
            <img :src="f" alt="">
            <span v-if="k === 0" class="foto-etq">Principal</span>
            <div class="foto-acciones">
              <button type="button" v-if="k > 0" class="btn-icono" title="Hacer principal" @click="principal(k)"><sc-icono nombre="check" :tam="15"/></button>
              <button type="button" class="btn-icono" title="Quitar" @click="quitar(k)"><sc-icono nombre="papelera" :tam="15"/></button>
            </div>
          </div>
          <label v-if="modelValue.length < max" class="foto-subir" :class="{ arrastrando }" @dragover.prevent="arrastrando = true" @dragleave="arrastrando = false" @drop.prevent="soltar">
            <input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden :disabled="subiendo || !productoId" @change="elegir">
            <sc-icono nombre="subir" :tam="22"/>
            <span>{{ subiendo ? 'Subiendo…' : 'Añadir foto' }}</span>
          </label>
        </div>
        <p class="sub">{{ productoId ? 'JPG, PNG o WebP. La primera foto es la que se ve en el catálogo.' : 'Guarda el producto y podrás añadir sus fotos.' }}</p>
      </div>`
};
