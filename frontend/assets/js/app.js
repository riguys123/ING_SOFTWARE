/* Aplicación raíz: layout con menú lateral que cambia según el rol */
SC.App = {
    template: `
      <div v-if="layout === 'panel'" class="app" :class="{ 'menu-abierto': store.menuAbierto }">
        <aside class="sidebar">
          <div class="sidebar-marca">Smart<span>Cell</span></div>
          <nav class="menu">
            <template v-for="g in menu" :key="g.nombre">
              <div class="menu-grupo">{{ g.nombre }}</div>
              <router-link v-for="m in g.items" :key="m.ruta" :to="m.ruta" class="menu-item">
                <sc-icono :nombre="m.icono"/> <span>{{ m.texto }}</span>
                <span v-if="m.contador === 'mensajes' && store.mensajesPendientes" class="insignia">{{ store.mensajesPendientes }}</span>
              </router-link>
            </template>
            <div class="menu-grupo">Tienda</div>
            <router-link to="/tienda" class="menu-item"><sc-icono nombre="carrito"/> <span>Ver tienda</span></router-link>
          </nav>
          <div class="sidebar-usuario">
            <span class="avatar">{{ iniciales }}</span>
            <div class="sidebar-datos">
              <strong>{{ store.user.nombre }}</strong>
              <small>{{ store.user.rol }}</small>
            </div>
            <button class="btn-icono" @click="salir" title="Cerrar sesión" aria-label="Cerrar sesión">
              <sc-icono nombre="salir"/>
            </button>
          </div>
        </aside>
        <div class="velo" @click="store.menuAbierto = false"></div>

        <div class="contenido">
          <header class="topbar">
            <button class="btn-icono" @click="store.menuAbierto = !store.menuAbierto" aria-label="Abrir menú">
              <sc-icono nombre="menu" :tam="22"/>
            </button>
            <span class="topbar-marca">Smart<span>Cell</span> Commerce</span>
          </header>
          <main class="main"><router-view :key="$route.path"/></main>
        </div>
      </div>

      <div v-else-if="layout === 'tienda'" class="tienda-app">
        <sc-tiendabar/>
        <main class="tienda-main"><router-view :key="$route.path"/></main>
        <footer class="tienda-pie">SmartCell Commerce · Proyecto universitario · Pasarela de pagos simulada</footer>
      </div>

      <router-view v-else/>
      <sc-toasts/>`,
    computed: {
        store() { return SC.store; },
        /*
         * 'panel'  -> menú lateral del personal
         * 'tienda' -> barra superior de la tienda (visitantes, clientes y rutas de la tienda)
         * 'ninguno'-> login y registro, que traen su propio diseño
         */
        layout() {
            const r = this.$route, u = SC.store.user;
            if (r.meta.publico) return 'ninguno';
            if (r.meta.tienda || (u && u.rol === 'Cliente')) return 'tienda';
            return u ? 'panel' : 'ninguno';
        },
        iniciales() { return SC.util.iniciales(SC.store.user.nombre); },
        /** Menú agrupado, solo con los módulos que el rol puede ver */
        menu() {
            const grupos = [];
            SC.MENU.filter(m => SC.store.nivel(m.clave)).forEach(m => {
                let g = grupos.find(x => x.nombre === m.grupo);
                if (!g) grupos.push(g = { nombre: m.grupo, items: [] });
                g.items.push(m);
            });
            return grupos;
        }
    },
    watch: { '$route'() { SC.store.menuAbierto = false; } },
    methods: {
        async salir() {
            await SC.store.cerrarSesion();
            SC.store.ok('Sesión cerrada.');
            this.$router.replace('/login');
        }
    }
};

const app = Vue.createApp(SC.App);
app.component('sc-icono', SC.Icono);
app.component('sc-modal', SC.Modal);
app.component('sc-paginador', SC.Paginador);
app.component('sc-toasts', SC.Toasts);
app.component('sc-foto', SC.Foto);
app.component('sc-estado', SC.Estado);
app.component('sc-tiendabar', SC.TiendaBar);
app.component('sc-precio', SC.Precio);
app.component('sc-banner', SC.Banner);
app.component('sc-subir-fotos', SC.SubirFotos);
app.component('sc-grafico-linea', SC.GraficoLinea);
app.component('sc-grafico-barras', SC.GraficoBarras);
app.component('sc-barras-h', SC.BarrasH);
app.component('sc-dona', SC.Dona);
app.config.globalProperties.$bs = SC.util.bs;      // {{ $bs(1450) }} -> "Bs 1.450" en cualquier plantilla
app.use(SC.router);

/* Sesión vencida o cuenta dada de baja: volver al login con aviso */
SC.onSesionExpirada = function () {
    if (!SC.store.user) return;   // aún no había sesión: no es una expiración
    SC.store.limpiar();
    SC.router.replace({ path: '/login', query: { expirada: 1 } });
};

app.mount('#app');

/* Los mensajes de WhatsApp pendientes se refrescan solos cada minuto (insignia del menú) */
setInterval(() => { if (document.visibilityState === 'visible') SC.store.contarMensajes(); }, 60000);
