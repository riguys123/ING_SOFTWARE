/* Pantalla 7: Mi perfil / cambiar contraseña */
SC.views.Perfil = {
    template: `
      <div>
        <div class="encabezado-pagina"><div><h1>Mi perfil</h1><p class="sub">Datos de tu cuenta y seguridad</p></div></div>

        <div class="grid-2">
          <div class="tarjeta">
            <div class="perfil-cabecera">
              <span class="avatar avatar-grande">{{ iniciales(user.nombre) }}</span>
              <div><h2>{{ user.nombre }}</h2><span class="badge badge-rol">{{ user.rol }}</span></div>
            </div>
            <dl class="datos">
              <dt>Correo</dt><dd>{{ user.email }}</dd>
              <dt>Rol</dt><dd>{{ user.rol }}</dd>
              <dt>Área</dt><dd class="cap">{{ user.area }}</dd>
            </dl>
            <p class="sub">Tu sesión se cierra automáticamente tras 30 minutos de inactividad.</p>
          </div>

          <form class="tarjeta formulario" @submit.prevent="cambiar" novalidate>
            <h2 class="subtitulo">Cambiar contraseña</h2>
            <div class="campo" :class="{ 'con-error': errores.password_actual }">
              <label for="pa">Contraseña actual</label>
              <input id="pa" type="password" v-model="form.password_actual" autocomplete="current-password">
              <small class="error-campo" v-if="errores.password_actual">{{ errores.password_actual }}</small>
            </div>
            <div class="campo" :class="{ 'con-error': errores.password }">
              <label for="pn">Nueva contraseña</label>
              <input id="pn" type="password" v-model="form.password" autocomplete="new-password">
              <small class="ayuda">Mínimo 8 caracteres.</small>
              <small class="error-campo" v-if="errores.password">{{ errores.password }}</small>
            </div>
            <div class="campo" :class="{ 'con-error': errores.password2 }">
              <label for="pn2">Repetir nueva contraseña</label>
              <input id="pn2" type="password" v-model="form.password2" autocomplete="new-password">
              <small class="error-campo" v-if="errores.password2">{{ errores.password2 }}</small>
            </div>
            <div class="formulario-acciones">
              <button type="submit" class="btn btn-primario" :disabled="guardando">{{ guardando ? 'Guardando…' : 'Actualizar contraseña' }}</button>
            </div>
          </form>
        </div>
      </div>`,
    data() { return { form: { password_actual: '', password: '', password2: '' }, errores: {}, guardando: false }; },
    computed: { user() { return SC.store.user; } },
    methods: {
        iniciales: SC.util.iniciales,
        async cambiar() {
            const e = {}, f = this.form;
            if (!f.password_actual) e.password_actual = 'Ingresa tu contraseña actual.';
            if (!f.password || f.password.length < 8) e.password = 'La nueva contraseña debe tener al menos 8 caracteres.';
            else if (f.password === f.password_actual) e.password = 'Debe ser distinta a la actual.';
            if (f.password2 !== f.password) e.password2 = 'Las contraseñas no coinciden.';
            this.errores = e;
            if (Object.keys(e).length) return;

            this.guardando = true;
            try {
                await SC.api.patch('/usuarios/' + this.user.id + '/password',
                    { password_actual: f.password_actual, password: f.password });
                SC.store.ok('Contraseña actualizada correctamente.');
                this.form = { password_actual: '', password: '', password2: '' };
            } catch (er) {
                if (er.status === 422) this.errores = er.detalles;
                SC.store.error(er.mensaje);
            } finally {
                this.guardando = false;
            }
        }
    }
};
