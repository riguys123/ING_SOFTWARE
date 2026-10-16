/*
 * Pantallas 4 y 5: Crear / Editar usuario (y vista de solo lectura para los Jefes).
 * El modo viene de route.meta.modo: 'crear' | 'editar' | 'ver'
 */
SC.views.UsuarioForm = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>{{ titulo }}</h1>
            <p class="sub"><router-link to="/usuarios">‹ Volver a usuarios</router-link></p>
          </div>
        </div>

        <div v-if="cargandoInicial" class="tarjeta">Cargando…</div>

        <template v-else>
          <form class="tarjeta formulario" @submit.prevent="guardar" novalidate>
            <div class="campo" :class="{ 'con-error': errores.nombre }">
              <label for="nombre">Nombre completo</label>
              <input id="nombre" v-model.trim="form.nombre" :disabled="soloLectura" maxlength="100" autocomplete="off">
              <small class="error-campo" v-if="errores.nombre">{{ errores.nombre }}</small>
            </div>

            <div class="campo" :class="{ 'con-error': errores.email }">
              <label for="email">Correo electrónico</label>
              <input id="email" type="email" v-model.trim="form.email" :disabled="soloLectura" maxlength="150" autocomplete="off">
              <small class="error-campo" v-if="errores.email">{{ errores.email }}</small>
            </div>

            <div class="campo" :class="{ 'con-error': errores.rol_id }">
              <label for="rol">Rol</label>
              <select id="rol" v-model="form.rol_id" :disabled="soloLectura || esMiCuenta">
                <option value="" disabled>Selecciona un rol…</option>
                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
              </select>
              <small class="ayuda" v-if="esMiCuenta && !soloLectura">No puedes cambiar tu propio rol.</small>
              <small class="error-campo" v-if="errores.rol_id">{{ errores.rol_id }}</small>
            </div>

            <template v-if="modo === 'crear'">
              <div class="campo" :class="{ 'con-error': errores.password }">
                <label for="pass">Contraseña</label>
                <div class="input-con-boton">
                  <input id="pass" :type="ver ? 'text' : 'password'" v-model="form.password" autocomplete="new-password">
                  <button type="button" class="ojo" @click="ver = !ver" :aria-label="ver ? 'Ocultar' : 'Mostrar'">
                    <sc-icono :nombre="ver ? 'ojotachado' : 'ojo'"/>
                  </button>
                </div>
                <small class="ayuda">Mínimo 8 caracteres. Se guarda cifrada (bcrypt).</small>
                <small class="error-campo" v-if="errores.password">{{ errores.password }}</small>
              </div>
              <div class="campo" :class="{ 'con-error': errores.password2 }">
                <label for="pass2">Repetir contraseña</label>
                <input id="pass2" :type="ver ? 'text' : 'password'" v-model="form.password2" autocomplete="new-password">
                <small class="error-campo" v-if="errores.password2">{{ errores.password2 }}</small>
              </div>
            </template>

            <div class="campo" v-if="modo !== 'crear'">
              <label class="check">
                <input type="checkbox" v-model="form.activo" :disabled="soloLectura || esMiCuenta">
                <span>Cuenta activa</span>
              </label>
              <small class="ayuda" v-if="esMiCuenta && !soloLectura">No puedes desactivarte a ti mismo.</small>
              <small class="error-campo" v-if="errores.activo">{{ errores.activo }}</small>
            </div>

            <p v-if="modo === 'ver'" class="sub">Creado: {{ fecha(form.creado_en) }} · Última modificación: {{ fecha(form.actualizado_en) }}</p>

            <div class="formulario-acciones" v-if="!soloLectura">
              <router-link to="/usuarios" class="btn btn-secundario">Cancelar</router-link>
              <button type="submit" class="btn btn-primario" :disabled="guardando">
                {{ guardando ? 'Guardando…' : (modo === 'crear' ? 'Crear usuario' : 'Guardar cambios') }}
              </button>
            </div>
          </form>

          <!-- Restablecer contraseña (solo Admin, al editar) -->
          <form v-if="modo === 'editar' && !esMiCuenta" class="tarjeta formulario" @submit.prevent="restablecer" novalidate>
            <h2 class="subtitulo">Restablecer contraseña</h2>
            <div class="campo" :class="{ 'con-error': errPass.password }">
              <label for="np">Nueva contraseña</label>
              <input id="np" type="password" v-model="nuevaPass.password" autocomplete="new-password">
              <small class="error-campo" v-if="errPass.password">{{ errPass.password }}</small>
            </div>
            <div class="campo" :class="{ 'con-error': errPass.password2 }">
              <label for="np2">Repetir nueva contraseña</label>
              <input id="np2" type="password" v-model="nuevaPass.password2" autocomplete="new-password">
              <small class="error-campo" v-if="errPass.password2">{{ errPass.password2 }}</small>
            </div>
            <div class="formulario-acciones">
              <button type="submit" class="btn btn-secundario" :disabled="guardandoPass">
                {{ guardandoPass ? 'Guardando…' : 'Cambiar contraseña' }}
              </button>
            </div>
          </form>
        </template>
      </div>`,
    data() {
        return {
            form: { nombre: '', email: '', rol_id: '', password: '', password2: '', activo: true, creado_en: '', actualizado_en: '' },
            errores: {}, roles: [], ver: false,
            cargandoInicial: true, guardando: false,
            nuevaPass: { password: '', password2: '' }, errPass: {}, guardandoPass: false
        };
    },
    computed: {
        modo() { return this.$route.meta.modo; },
        id() { return Number(this.$route.params.id); },
        soloLectura() { return this.modo === 'ver'; },
        esMiCuenta() { return this.modo !== 'crear' && this.id === SC.store.user.id; },
        titulo() {
            return { crear: 'Nuevo usuario', editar: 'Editar usuario', ver: 'Detalle del usuario' }[this.modo];
        }
    },
    async created() {
        try {
            this.roles = (await SC.api.get('/roles')).data;
            if (this.modo !== 'crear') {
                const u = (await SC.api.get('/usuarios/' + this.id)).data;
                this.form = { ...this.form, ...u, activo: !!u.activo };
            }
        } catch (e) {
            SC.store.error(e.mensaje);
            this.$router.replace(e.status === 403 ? '/403' : (e.status === 404 ? '/usuarios' : '/usuarios'));
            return;
        }
        this.cargandoInicial = false;
    },
    methods: {
        fecha: SC.util.fecha,
        validar() {
            const e = {}, f = this.form;
            if (!f.nombre || f.nombre.length < 2) e.nombre = 'El nombre debe tener al menos 2 caracteres.';
            if (!f.email) e.email = 'El correo es obligatorio.';
            else if (!SC.util.emailValido(f.email)) e.email = 'Ingresa un correo electrónico válido.';
            if (!f.rol_id) e.rol_id = 'Selecciona un rol.';
            if (this.modo === 'crear') {
                if (!f.password || f.password.length < 8) e.password = 'La contraseña debe tener al menos 8 caracteres.';
                if (f.password2 !== f.password) e.password2 = 'Las contraseñas no coinciden.';
            }
            return e;
        },
        async guardar() {
            this.errores = this.validar();
            if (Object.keys(this.errores).length) return;
            this.guardando = true;
            try {
                const f = this.form;
                if (this.modo === 'crear') {
                    await SC.api.post('/usuarios', { nombre: f.nombre, email: f.email, rol_id: Number(f.rol_id), password: f.password });
                    SC.store.ok('Usuario creado correctamente.');
                } else {
                    await SC.api.put('/usuarios/' + this.id, { nombre: f.nombre, email: f.email, rol_id: Number(f.rol_id), activo: f.activo });
                    SC.store.ok('Cambios guardados.');
                    // Si edité mi propia cuenta, refresco el nombre/correo del menú
                    if (this.esMiCuenta) await SC.store.cargarSesion();
                }
                this.$router.push('/usuarios');
            } catch (e) {
                if (e.status === 422) this.errores = e.detalles;
                SC.store.error(e.mensaje);
            } finally {
                this.guardando = false;
            }
        },
        async restablecer() {
            const e = {};
            if (!this.nuevaPass.password || this.nuevaPass.password.length < 8) e.password = 'Mínimo 8 caracteres.';
            if (this.nuevaPass.password2 !== this.nuevaPass.password) e.password2 = 'Las contraseñas no coinciden.';
            this.errPass = e;
            if (Object.keys(e).length) return;
            this.guardandoPass = true;
            try {
                await SC.api.patch('/usuarios/' + this.id + '/password', { password: this.nuevaPass.password });
                SC.store.ok('Contraseña restablecida.');
                this.nuevaPass = { password: '', password2: '' };
            } catch (er) {
                if (er.status === 422) this.errPass = er.detalles;
                SC.store.error(er.mensaje);
            } finally {
                this.guardandoPass = false;
            }
        }
    }
};
