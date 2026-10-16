/* Aplicación raíz: layout con menú lateral que cambia según el rol */
SC.App = {
    template: `
      <div v-if="mostrarLayout" class="app" :class="{ 'menu-abierto': store.menuAbierto }">
        <aside class="sidebar">
          <div class="sidebar-marca">Smart<span>Cell</span></div>
          <nav class="menu">
            <template v-for="g in menu" :key="g.nombre">
              <div class="menu-grupo">{{ g.nombre }}</div>
              <router-link v-for="m in g.items" :key="m.clave" :to="m.ruta" class="menu-item">
                <sc-icono :nombre="m.icono"/> <span>{{ m.texto }}</span>
              </router-link>
            </template>
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
          <main class="main"><router-view/></main>
        </div>
      </div>
      <router-view v-else/>
      <sc-toasts/>`,
    computed: {
        store() { return SC.store; },
        mostrarLayout() { return !!SC.store.user && !this.$route.meta.publico; },
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
            try { await SC.api.post('/auth/logout'); } catch (e) { /* aunque falle, limpiamos el cliente */ }
            SC.store.limpiar();
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
app.use(SC.router);

/* Sesión vencida o cuenta dada de baja: volver al login con aviso */
SC.onSesionExpirada = function () {
    if (!SC.store.user) return;   // aún no había sesión: no es una expiración
    SC.store.limpiar();
    SC.router.replace({ path: '/login', query: { expirada: 1 } });
};

app.mount('#app');
