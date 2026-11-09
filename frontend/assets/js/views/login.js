/* Pantalla 1: Login */
SC.views.Login = {
    template: `
      <div class="login-pagina">
        <div class="login-shell">
          <div class="login-brand">
            <div>
              <div class="marca">Smart<span>Cell</span> Commerce</div>
              <p class="frase">Plataforma de venta y gestión de celulares: catálogo, inventario por IMEI, distribución y cobranzas en un solo lugar.</p>
            </div>
            <div class="modulo-tag">Módulo de Seguridad de Usuario · Autenticación RBAC</div>
          </div>

          <div class="login-form-area">
            <h1>Bienvenido de nuevo</h1>
            <p class="sub">Ingresa con tu cuenta para continuar</p>

            <div v-if="$route.query.expirada" class="mensaje-info">Tu sesión expiró. Inicia sesión nuevamente.</div>
            <div v-if="error" class="mensaje-error" role="alert">{{ error }}</div>

            <form @submit.prevent="entrar" novalidate>
              <div class="campo">
                <label for="email">Correo electrónico</label>
                <input type="email" id="email" v-model.trim="email" placeholder="tucorreo@smartcell.com"
                       autocomplete="username" autofocus>
              </div>
              <div class="campo">
                <label for="password">Contraseña</label>
                <div class="input-con-boton">
                  <input :type="ver ? 'text' : 'password'" id="password" v-model="password"
                         placeholder="••••••••" autocomplete="current-password">
                  <button type="button" class="ojo" @click="ver = !ver"
                          :aria-label="ver ? 'Ocultar contraseña' : 'Mostrar contraseña'">
                    <sc-icono :nombre="ver ? 'ojotachado' : 'ojo'"/>
                  </button>
                </div>
              </div>
              <button type="submit" class="btn-entrar" :disabled="cargando">
                {{ cargando ? 'Verificando…' : 'Iniciar sesión' }}
              </button>
            </form>

            <p class="pista">
              <router-link to="/registro">Crear una cuenta</router-link> · <router-link to="/tienda">Ver catálogo</router-link><br><br>
              Admin: <code>admin@smartcell.com</code> / <code>Admin123!</code><br>
              Cliente: <code>cliente@smartcell.com</code> / <code>Smart1234!</code>
            </p>
          </div>
        </div>
      </div>`,
    data() { return { email: '', password: '', ver: false, cargando: false, error: '' }; },
    methods: {
        async entrar() {
            this.error = '';
            if (!this.email || !this.password) {
                this.error = 'Completa tu correo y contraseña.';
                return;
            }
            if (!SC.util.emailValido(this.email)) {
                this.error = 'Ingresa un correo electrónico válido.';
                return;
            }
            this.cargando = true;
            try {
                const r = await SC.api.post('/auth/login', { email: this.email, password: this.password });
                SC.store.aplicarSesion(r.data);
                const redir = this.$route.query.redir;
                // Solo rutas internas (evita redirecciones abiertas)
                const destino = (typeof redir === 'string' && redir.startsWith('/') && !redir.startsWith('//')) ? redir : SC.store.inicio();
                this.$router.replace(destino);
            } catch (e) {
                this.error = e.mensaje;
                this.password = '';
            } finally {
                this.cargando = false;
            }
        }
    }
};
