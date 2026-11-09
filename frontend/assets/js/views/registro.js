/* Registro de clientes (POST /api/auth/register) */
SC.views.Registro = {
    template: `
      <div class="login-pagina">
        <div class="login-shell">
          <div class="login-brand">
            <div>
              <div class="marca">Smart<span>Cell</span> Commerce</div>
              <p class="frase">Crea tu cuenta para comprar celulares nuevos, pagar de forma segura y seguir tu pedido hasta tu puerta.</p>
            </div>
            <div class="modulo-tag">Cada equipo se entrega con su IMEI registrado</div>
          </div>
          <div class="login-form-area">
            <h1>Crear cuenta</h1>
            <p class="sub">Es gratis y toma menos de un minuto</p>
            <div v-if="error" class="mensaje-error" role="alert">{{ error }}</div>
            <form @submit.prevent="registrar" novalidate>
              <div class="campo" :class="{ 'con-error': err.nombre }">
                <label for="rn">Nombre completo</label>
                <input id="rn" type="text" v-model.trim="f.nombre" autocomplete="name" autofocus>
                <span v-if="err.nombre" class="error-campo">{{ err.nombre }}</span>
              </div>
              <div class="campo" :class="{ 'con-error': err.email }">
                <label for="re">Correo electrónico</label>
                <input id="re" type="email" v-model.trim="f.email" autocomplete="email">
                <span v-if="err.email" class="error-campo">{{ err.email }}</span>
              </div>
              <div class="campo" :class="{ 'con-error': err.password }">
                <label for="rp">Contraseña</label>
                <input id="rp" type="password" v-model="f.password" autocomplete="new-password">
                <span v-if="err.password" class="error-campo">{{ err.password }}</span>
                <small v-else class="ayuda">Mínimo 8 caracteres.</small>
              </div>
              <div class="campo" :class="{ 'con-error': err.password2 }">
                <label for="rp2">Repite la contraseña</label>
                <input id="rp2" type="password" v-model="f.password2" autocomplete="new-password">
                <span v-if="err.password2" class="error-campo">{{ err.password2 }}</span>
              </div>
              <button type="submit" class="btn-entrar" :disabled="cargando">{{ cargando ? 'Creando cuenta…' : 'Crear cuenta' }}</button>
            </form>
            <p class="pista">¿Ya tienes cuenta? <router-link to="/login">Inicia sesión</router-link> · <router-link to="/tienda">Ver catálogo</router-link></p>
          </div>
        </div>
      </div>`,
    data() { return { f: { nombre: '', email: '', password: '', password2: '' }, err: {}, error: '', cargando: false }; },
    methods: {
        async registrar() {
            this.err = {}; this.error = '';
            const e = {};
            if (this.f.nombre.length < 2) e.nombre = 'Ingresa tu nombre.';
            if (!SC.util.emailValido(this.f.email)) e.email = 'Ingresa un correo electrónico válido.';
            if (this.f.password.length < 8) e.password = 'La contraseña debe tener al menos 8 caracteres.';
            if (this.f.password !== this.f.password2) e.password2 = 'Las contraseñas no coinciden.';
            if (Object.keys(e).length) { this.err = e; return; }

            this.cargando = true;
            try {
                const r = await SC.api.post('/auth/register', { nombre: this.f.nombre, email: this.f.email, password: this.f.password });
                SC.store.aplicarSesion(r.data);
                SC.store.ok('¡Bienvenido a SmartCell, ' + r.data.usuario.nombre.split(' ')[0] + '!');
                const redir = this.$route.query.redir;
                this.$router.replace((typeof redir === 'string' && redir.startsWith('/') && !redir.startsWith('//')) ? redir : '/tienda');
            } catch (er) {
                if (er.status === 422 && er.detalles) this.err = er.detalles; else this.error = er.mensaje;
                this.f.password = ''; this.f.password2 = '';
            } finally { this.cargando = false; }
        }
    }
};
