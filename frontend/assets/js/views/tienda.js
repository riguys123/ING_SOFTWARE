/* Tienda pública: catálogo con filtros y detalle del producto */

/** Añade al carrito validando sesión y rol. Devuelve true si se agregó. */
SC.agregarAlCarrito = async function (router, productoId, cantidad) {
    const s = SC.store;
    if (!s.user) {
        s.info('Inicia sesión o crea una cuenta para comprar.');
        router.push({ path: '/login', query: { redir: router.currentRoute.value.fullPath } });
        return false;
    }
    if (!s.esCliente) {
        s.error('Solo las cuentas de cliente pueden comprar en la tienda.');
        return false;
    }
    try {
        await SC.cart.agregar(productoId, cantidad || 1);
        s.ok('Añadido al carrito.');
        return true;
    } catch (e) {
        s.error(e.mensaje);
        return false;
    }
};

SC.views.Catalogo = {
    template: `
      <div>
        <sc-banner v-if="promos.length" :promos="promos" @ir="irPromo"/>
        <section v-else class="hero">
          <h1>Tu próximo celular, con <span class="acento">IMEI registrado</span></h1>
          <p>Equipos nuevos y sellados, accesorios y complementos. Pago seguro y envío a tu domicilio.</p>
        </section>

        <nav class="categorias" aria-label="Categorías">
          <button class="chip-cat" :class="{ activo: !f.categoria && !f.oferta }" @click="elegirCategoria('')">
            <sc-icono nombre="inicio" :tam="16"/> Todo
          </button>
          <button v-for="c in categorias" :key="c.id" class="chip-cat" :class="{ activo: f.categoria === c.nombre && !f.oferta }" @click="elegirCategoria(c.nombre)">
            <sc-icono :nombre="c.icono" :tam="16"/> {{ c.nombre }} <small>{{ c.productos }}</small>
          </button>
          <button class="chip-cat chip-oferta" :class="{ activo: f.oferta }" @click="elegirOfertas">
            <sc-icono nombre="oferta" :tam="16"/> Ofertas
          </button>
        </nav>

        <div class="catalogo-layout">
          <aside class="tarjeta filtros-lateral">
            <h3>Filtros</h3>
            <div class="campo">
              <label for="cq">Buscar</label>
              <input id="cq" type="search" v-model="f.q" placeholder="Producto, marca…" @input="aplicar(true)">
            </div>
            <div class="campo">
              <label for="cm">Marca</label>
              <select id="cm" v-model="f.marca" @change="aplicar(false)">
                <option value="">Todas</option>
                <option v-for="m in opc.marcas" :key="m.marca" :value="m.marca">{{ m.marca }} ({{ m.total }})</option>
              </select>
            </div>
            <div class="campo">
              <label>Precio (Bs)</label>
              <div class="par">
                <input type="number" min="0" v-model.number="f.min" placeholder="Mín." aria-label="Precio mínimo" @input="aplicar(true)">
                <input type="number" min="0" v-model.number="f.max" placeholder="Máx." aria-label="Precio máximo" @input="aplicar(true)">
              </div>
            </div>
            <template v-if="esTelefonos">
              <div class="campo">
                <label for="cr">Memoria RAM</label>
                <select id="cr" v-model="f.ram_gb" @change="aplicar(false)">
                  <option value="">Cualquiera</option>
                  <option v-for="r in opc.ram_gb" :key="r" :value="r">{{ r }} GB</option>
                </select>
              </div>
              <div class="campo">
                <label for="ca">Almacenamiento</label>
                <select id="ca" v-model="f.almacenamiento_gb" @change="aplicar(false)">
                  <option value="">Cualquiera</option>
                  <option v-for="a in opc.almacenamiento_gb" :key="a" :value="a">{{ a }} GB</option>
                </select>
              </div>
            </template>
            <div class="campo">
              <label class="check"><input type="checkbox" v-model="f.disponible" @change="aplicar(false)"> Solo con stock</label>
            </div>
            <button class="btn btn-secundario btn-sm" @click="limpiar">Limpiar filtros</button>
          </aside>

          <section>
            <div class="catalogo-barra">
              <span class="sub">{{ meta ? meta.total + ' producto(s)' : 'Cargando…' }}<template v-if="f.categoria"> en {{ f.categoria }}</template><template v-if="f.oferta"> en oferta</template></span>
              <div class="campo">
                <label for="co" class="sr">Ordenar por</label>
                <select id="co" v-model="f.orden" @change="aplicar(false)">
                  <option value="nuevo">Más recientes</option>
                  <option value="oferta">Mayor descuento</option>
                  <option value="precio_asc">Precio: menor a mayor</option>
                  <option value="precio_desc">Precio: mayor a menor</option>
                  <option value="nombre">Nombre A-Z</option>
                </select>
              </div>
            </div>

            <p v-if="!cargando && !items.length" class="tarjeta vacio-caja">No encontramos productos con esos filtros.</p>
            <div class="grilla-productos">
              <article v-for="p in items" :key="p.id" class="producto" :class="{ agotado: !p.disponible }">
                <router-link :to="'/tienda/' + p.id" class="producto-foto" :aria-label="'Ver ' + p.marca + ' ' + p.modelo">
                  <span v-if="p.descuento_pct > 0" class="badge-oferta esquina">-{{ p.descuento_pct }}%</span>
                  <sc-foto :fotos="p.fotos" :marca="p.marca" :modelo="p.modelo"/>
                </router-link>
                <div class="producto-info">
                  <small class="producto-marca">{{ p.marca }} · {{ p.categoria }}</small>
                  <router-link :to="'/tienda/' + p.id" class="producto-nombre">{{ p.modelo }}</router-link>
                  <p class="producto-specs">{{ resumen(p) }}</p>
                  <div class="producto-pie">
                    <sc-precio :p="p"/>
                    <span v-if="!p.disponible" class="badge badge-error">Agotado</span>
                    <span v-else-if="p.stock_actual <= 3" class="badge badge-warn">Quedan {{ p.stock_actual }}</span>
                  </div>
                  <button class="btn btn-primario btn-bloque" :disabled="!p.disponible || agregando === p.id" @click="agregar(p)">
                    <sc-icono nombre="carrito" :tam="16"/> {{ p.disponible ? 'Añadir al carrito' : 'Sin stock' }}
                  </button>
                </div>
              </article>
            </div>
            <sc-paginador :meta="meta" @cambiar="irAPagina"/>
          </section>
        </div>
      </div>`,
    data() {
        const q = this.$route.query;
        return {
            items: [], meta: null, cargando: true, pagina: 1, temporizador: null, agregando: 0,
            promos: [], categorias: [],
            opc: { marcas: [], ram_gb: [], almacenamiento_gb: [] },
            f: { q: '', categoria: q.cat || '', oferta: q.oferta === '1', marca: '', min: '', max: '', ram_gb: '', almacenamiento_gb: '', disponible: false, orden: q.oferta === '1' ? 'oferta' : 'nuevo' }
        };
    },
    computed: {
        /** Los filtros de RAM y almacenamiento solo tienen sentido para teléfonos */
        esTelefonos() {
            const c = this.categorias.find(x => x.nombre === this.f.categoria);
            return !this.f.categoria ? false : !!(c && c.usa_imei);
        }
    },
    async created() {
        const [p, c] = await Promise.allSettled([SC.api.get('/promociones/vigentes'), SC.api.get('/categorias')]);
        if (p.status === 'fulfilled') this.promos = p.value.data;
        if (c.status === 'fulfilled') this.categorias = c.value.data;
        await this.cargarOpciones();
        this.cargar();
    },
    methods: {
        resumen(p) {
            if (p.requiere_imei) return SC.util.specs(p);
            return p.descripcion ? p.descripcion.slice(0, 70) + (p.descripcion.length > 70 ? '…' : '') : (p.color || '');
        },
        async cargarOpciones() {
            try { this.opc = (await SC.api.get('/productos/filtros', { categoria: this.f.categoria })).data; } catch (e) { /* opcional */ }
        },
        async cargar() {
            this.cargando = true;
            try {
                const { oferta, ...resto } = this.f;
                const q = { ...resto, oferta: oferta ? 1 : '', disponible: this.f.disponible ? 1 : '', pagina: this.pagina, por_pagina: 12 };
                const r = await SC.api.get('/productos', q);
                this.items = r.data;
                this.meta = r.meta;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        async elegirCategoria(nombre) {
            this.f = { ...this.f, categoria: nombre, oferta: false, marca: '', ram_gb: '', almacenamiento_gb: '', orden: this.f.orden === 'oferta' ? 'nuevo' : this.f.orden };
            this.$router.replace({ path: '/tienda', query: nombre ? { cat: nombre } : {} });
            await this.cargarOpciones();
            this.aplicar(false);
        },
        elegirOfertas() {
            this.f = { ...this.f, categoria: '', oferta: true, marca: '', ram_gb: '', almacenamiento_gb: '', orden: 'oferta' };
            this.$router.replace({ path: '/tienda', query: { oferta: 1 } });
            this.cargarOpciones();
            this.aplicar(false);
        },
        irPromo(p) {
            if (p.producto_id) return this.$router.push('/tienda/' + p.producto_id);
            if (p.categoria_id) {
                const c = this.categorias.find(x => x.id === p.categoria_id);
                if (c) return this.elegirCategoria(c.nombre);
            }
            this.elegirOfertas();
        },
        aplicar(conRetraso) {
            clearTimeout(this.temporizador);
            this.pagina = 1;
            if (conRetraso) this.temporizador = setTimeout(() => this.cargar(), 350);
            else this.cargar();
        },
        limpiar() {
            this.f = { q: '', categoria: this.f.categoria, oferta: this.f.oferta, marca: '', min: '', max: '', ram_gb: '', almacenamiento_gb: '', disponible: false, orden: this.f.oferta ? 'oferta' : 'nuevo' };
            this.aplicar(false);
        },
        irAPagina(p) { this.pagina = p; this.cargar(); window.scrollTo({ top: 0, behavior: 'smooth' }); },
        async agregar(p) {
            this.agregando = p.id;
            await SC.agregarAlCarrito(this.$router, p.id, 1);
            this.agregando = 0;
        }
    }
};

SC.views.ProductoDetalle = {
    template: `
      <div>
        <router-link to="/tienda" class="volver"><sc-icono nombre="volver" :tam="16"/> Volver al catálogo</router-link>
        <p v-if="cargando" class="sub">Cargando…</p>
        <p v-else-if="!p" class="tarjeta vacio-caja">Este producto no existe o ya no está disponible.</p>
        <div v-else class="detalle-producto">
          <div>
            <div class="detalle-foto tarjeta">
              <span v-if="p.descuento_pct > 0" class="badge-oferta esquina grande">-{{ p.descuento_pct }}%</span>
              <sc-foto :fotos="p.fotos" :marca="p.marca" :modelo="p.modelo" :indice="foto"/>
            </div>
            <div v-if="p.fotos.length > 1" class="miniaturas">
              <button v-for="(f, k) in p.fotos" :key="f" :class="{ activa: k === foto }" @click="foto = k" :aria-label="'Foto ' + (k + 1)">
                <img :src="f" alt="">
              </button>
            </div>
          </div>
          <div>
            <small class="producto-marca">{{ p.marca }} · <router-link :to="{ path: '/tienda', query: { cat: p.categoria } }">{{ p.categoria }}</router-link></small>
            <h1>{{ p.modelo }}</h1>
            <p class="detalle-precio"><sc-precio :p="p" grande/></p>
            <p v-if="p.descuento_pct > 0" class="sub ahorro">Ahorras {{ $bs(p.precio - p.precio_final) }} con la promoción vigente.</p>
            <p v-if="p.descripcion" class="detalle-desc">{{ p.descripcion }}</p>

            <dl class="datos datos-ancho">
              <template v-if="p.procesador"><dt>Procesador</dt><dd>{{ p.procesador }}</dd></template>
              <template v-if="specs(p)"><dt>Memoria</dt><dd>{{ specs(p) }}</dd></template>
              <template v-if="p.camara"><dt>Cámara</dt><dd>{{ p.camara }}</dd></template>
              <template v-if="p.pantalla"><dt>Pantalla</dt><dd>{{ p.pantalla }}</dd></template>
              <template v-if="p.bateria_mah"><dt>Batería</dt><dd>{{ p.bateria_mah }} mAh</dd></template>
              <template v-if="p.color"><dt>Color</dt><dd>{{ p.color }}</dd></template>
              <dt>Disponibilidad</dt>
              <dd>
                <span v-if="!p.disponible" class="badge badge-error">Agotado</span>
                <span v-else-if="p.stock_actual <= 3" class="badge badge-warn">Últimas {{ p.stock_actual }} unidades</span>
                <span v-else class="badge badge-ok">En stock</span>
              </dd>
            </dl>

            <div v-if="p.disponible" class="detalle-compra">
              <div class="cantidad" role="group" aria-label="Cantidad">
                <button class="btn btn-secundario btn-sm" :disabled="cantidad <= 1" @click="cantidad--" aria-label="Menos"><sc-icono nombre="menos" :tam="14"/></button>
                <span>{{ cantidad }}</span>
                <button class="btn btn-secundario btn-sm" :disabled="cantidad >= tope" @click="cantidad++" aria-label="Más"><sc-icono nombre="mas" :tam="14"/></button>
              </div>
              <button class="btn btn-secundario" :disabled="enviando" @click="agregar(false)"><sc-icono nombre="carrito" :tam="16"/> Añadir al carrito</button>
              <button class="btn btn-primario" :disabled="enviando" @click="agregar(true)">Comprar ahora</button>
            </div>
            <p v-if="p.requiere_imei" class="sub garantia"><sc-icono nombre="escudo" :tam="15"/> Equipo nuevo y sellado · el IMEI de tu unidad se te informa al confirmar el pago.</p>
            <p v-else class="sub garantia"><sc-icono nombre="escudo" :tam="15"/> Producto nuevo · recibirás tu comprobante por WhatsApp al confirmar el pago.</p>
          </div>
        </div>
      </div>`,
    data() { return { p: null, cargando: true, cantidad: 1, enviando: false, foto: 0 }; },
    computed: { tope() { return this.p ? Math.min(10, this.p.stock_actual) : 1; } },
    async created() {
        try { this.p = (await SC.api.get('/productos/' + this.$route.params.id)).data; }
        catch (e) { this.p = null; }
        finally { this.cargando = false; }
    },
    methods: {
        specs: SC.util.specs,
        async agregar(irAlCarrito) {
            this.enviando = true;
            const ok = await SC.agregarAlCarrito(this.$router, this.p.id, this.cantidad);
            this.enviando = false;
            if (ok && irAlCarrito) this.$router.push('/carrito');
        }
    }
};
