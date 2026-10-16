/* Pantalla 10: Acceso denegado (403), No encontrado (404) y módulos placeholder */
SC.views.Error = {
    template: `
      <div class="pantalla-error">
        <div class="codigo-error">{{ codigo }}</div>
        <h1>{{ codigo === 403 ? 'Acceso denegado' : 'Página no encontrada' }}</h1>
        <p class="sub">{{ codigo === 403
            ? 'Tu rol no tiene permiso para ver esta sección.'
            : 'La dirección que buscas no existe o fue movida.' }}</p>
        <router-link :to="logueado ? '/panel' : '/login'" class="btn btn-primario">
          {{ logueado ? 'Volver al panel' : 'Ir al inicio de sesión' }}
        </router-link>
      </div>`,
    computed: {
        codigo() { return this.$route.meta.codigo || 404; },
        logueado() { return !!SC.store.user; }
    }
};

/* Módulos de otros equipos: pantalla provisional protegida por rol */
SC.views.Placeholder = {
    template: `
      <div>
        <div class="encabezado-pagina"><div><h1>{{ modulo.texto }}</h1><p class="sub">Módulo a cargo de otro equipo</p></div></div>
        <div class="tarjeta placeholder">
          <div class="placeholder-icono"><sc-icono nombre="obra" :tam="40"/></div>
          <h2>En construcción</h2>
          <p class="sub">Esta pantalla es un marcador de posición. Ya está protegida por rol:
             tu rol (<strong>{{ rol }}</strong>) tiene <strong>{{ nivel === 'total' ? 'acceso total' : 'solo lectura' }}</strong> en este módulo.</p>
        </div>
      </div>`,
    computed: {
        rol() { return SC.store.user.rol; },
        nivel() { return SC.store.nivel(this.$route.params.clave); },
        modulo() { return SC.MENU.find(m => m.clave === this.$route.params.clave) || { texto: 'Módulo' }; }
    }
};
