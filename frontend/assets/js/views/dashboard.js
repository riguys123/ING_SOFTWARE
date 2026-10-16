/* Pantalla 2: Dashboard principal */
SC.views.Dashboard = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>{{ saludo }}, {{ primerNombre }}</h1>
            <p class="sub">Rol: <span class="badge badge-rol">{{ user.rol }}</span></p>
          </div>
        </div>

        <div v-if="stats" class="tarjetas-stats">
          <div class="stat"><span class="stat-num">{{ stats.total }}</span><span class="stat-txt">{{ esAdmin ? 'Usuarios' : 'Usuarios de tu área' }}</span></div>
          <div class="stat"><span class="stat-num verde">{{ stats.activos }}</span><span class="stat-txt">Activos</span></div>
          <div class="stat"><span class="stat-num rojo">{{ stats.inactivos }}</span><span class="stat-txt">Dados de baja</span></div>
        </div>

        <h2 class="subtitulo">Tus accesos</h2>
        <div class="accesos">
          <router-link v-for="m in accesos" :key="m.clave" :to="m.ruta" class="acceso">
            <span class="acceso-icono"><sc-icono :nombre="m.icono" :tam="22"/></span>
            <span class="acceso-texto">{{ m.texto }}</span>
            <span class="badge" :class="m.nivel === 'total' ? 'badge-ok' : 'badge-info'">{{ m.nivel === 'total' ? 'Acceso total' : 'Solo lectura' }}</span>
          </router-link>
        </div>
      </div>`,
    data() { return { stats: null }; },
    computed: {
        user() { return SC.store.user; },
        esAdmin() { return SC.store.user.rol === 'Admin'; },
        primerNombre() { return this.user.nombre.split(' ')[0]; },
        saludo() {
            const h = new Date().getHours();
            return h < 12 ? 'Buenos días' : (h < 19 ? 'Buenas tardes' : 'Buenas noches');
        },
        accesos() {
            return SC.MENU
                .filter(m => m.clave !== 'panel' && SC.store.nivel(m.clave))
                .map(m => ({ ...m, nivel: SC.store.nivel(m.clave) }));
        }
    },
    async created() {
        if (!SC.store.nivel('seguridad_usuarios')) return;
        try {
            const [t, a, i] = await Promise.all([
                SC.api.get('/usuarios', { por_pagina: 1 }),
                SC.api.get('/usuarios', { por_pagina: 1, activo: 1 }),
                SC.api.get('/usuarios', { por_pagina: 1, activo: 0 })
            ]);
            this.stats = { total: t.meta.total, activos: a.meta.total, inactivos: i.meta.total };
        } catch (e) { /* las estadísticas son opcionales */ }
    }
};
