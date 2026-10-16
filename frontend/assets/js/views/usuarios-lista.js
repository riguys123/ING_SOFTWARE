/* Pantallas 3 y 6: Lista de usuarios + modal de confirmación de baja */
SC.views.UsuariosLista = {
    template: `
      <div>
        <div class="encabezado-pagina">
          <div>
            <h1>Usuarios</h1>
            <p class="sub">{{ esAdmin ? 'Gestión de cuentas del sistema' : 'Usuarios de tu área (solo lectura)' }}</p>
          </div>
          <router-link v-if="puedeEscribir" to="/usuarios/nuevo" class="btn btn-primario">
            <sc-icono nombre="mas" :tam="16"/> Nuevo usuario
          </router-link>
        </div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="q">Buscar</label>
            <input id="q" type="search" v-model="filtros.q" placeholder="Nombre o correo…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="frol">Rol</label>
            <select id="frol" v-model="filtros.rol_id" @change="alFiltrar(false)">
              <option value="">Todos</option>
              <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
            </select>
          </div>
          <div class="campo">
            <label for="fest">Estado</label>
            <select id="fest" v-model="filtros.activo" @change="alFiltrar(false)">
              <option value="">Todos</option>
              <option value="1">Activos</option>
              <option value="0">Dados de baja</option>
            </select>
          </div>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead>
              <tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Creado</th><th class="col-acciones">Acciones</th></tr>
            </thead>
            <tbody>
              <tr v-if="cargando"><td colspan="5" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="5" class="vacio">No se encontraron usuarios con esos filtros.</td></tr>
              <tr v-for="u in items" :key="u.id" v-show="!cargando">
                <td data-label="Usuario">
                  <div class="celda-usuario">
                    <span class="avatar">{{ iniciales(u.nombre) }}</span>
                    <span><strong>{{ u.nombre }}</strong><br><small>{{ u.email }}</small></span>
                  </div>
                </td>
                <td data-label="Rol"><span class="badge badge-rol">{{ u.rol }}</span></td>
                <td data-label="Estado">
                  <span class="badge" :class="u.activo ? 'badge-ok' : 'badge-error'">{{ u.activo ? 'Activo' : 'Baja' }}</span>
                </td>
                <td data-label="Creado">{{ fecha(u.creado_en) }}</td>
                <td data-label="Acciones" class="col-acciones">
                  <div class="acciones">
                    <router-link v-if="puedeEscribir" :to="'/usuarios/' + u.id + '/editar'" class="btn btn-secundario btn-sm" title="Editar">
                      <sc-icono nombre="editar" :tam="15"/> Editar
                    </router-link>
                    <router-link v-else :to="'/usuarios/' + u.id" class="btn btn-secundario btn-sm" title="Ver">
                      <sc-icono nombre="ojo" :tam="15"/> Ver
                    </router-link>
                    <template v-if="puedeEscribir">
                      <button v-if="u.activo && u.id !== yo.id" class="btn btn-peligro-suave btn-sm" @click="pedirBaja(u)">
                        <sc-icono nombre="papelera" :tam="15"/> Desactivar
                      </button>
                      <button v-else-if="!u.activo" class="btn btn-secundario btn-sm" @click="activar(u)">
                        <sc-icono nombre="check" :tam="15"/> Activar
                      </button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <sc-paginador :meta="meta" @cambiar="irAPagina"/>

        <sc-modal :abierto="!!baja" titulo="Desactivar usuario" texto-confirmar="Sí, desactivar" peligro
                  :cargando="procesando" @confirmar="confirmarBaja" @cancelar="baja = null">
          <p v-if="baja">¿Seguro que quieres dar de baja a <strong>{{ baja.nombre }}</strong> ({{ baja.email }})?</p>
          <p class="sub">Es una baja lógica: no podrá iniciar sesión, pero su registro se conserva y puedes reactivarlo después.</p>
        </sc-modal>
      </div>`,
    data() {
        return {
            items: [], meta: null, roles: [], cargando: true,
            filtros: { q: '', rol_id: '', activo: '' }, pagina: 1,
            baja: null, procesando: false, temporizador: null
        };
    },
    computed: {
        yo() { return SC.store.user; },
        esAdmin() { return SC.store.user.rol === 'Admin'; },
        puedeEscribir() { return SC.store.puedeEscribir('seguridad_usuarios'); }
    },
    async created() {
        try { this.roles = (await SC.api.get('/roles')).data; } catch (e) { /* el filtro de rol es opcional */ }
        this.cargar();
    },
    methods: {
        iniciales: SC.util.iniciales,
        fecha: SC.util.fecha,
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/usuarios', { ...this.filtros, pagina: this.pagina, por_pagina: 10 });
                this.items = r.data;
                this.meta = r.meta;
            } catch (e) {
                SC.store.error(e.mensaje);
            } finally {
                this.cargando = false;
            }
        },
        alFiltrar(conRetraso) {
            clearTimeout(this.temporizador);
            this.pagina = 1;
            if (conRetraso) this.temporizador = setTimeout(() => this.cargar(), 350);
            else this.cargar();
        },
        irAPagina(p) { this.pagina = p; this.cargar(); },
        pedirBaja(u) { this.baja = u; },
        async confirmarBaja() {
            this.procesando = true;
            try {
                await SC.api.delete('/usuarios/' + this.baja.id);
                SC.store.ok('Usuario "' + this.baja.nombre + '" dado de baja.');
                this.baja = null;
                this.cargar();
            } catch (e) {
                SC.store.error(e.mensaje);
            } finally {
                this.procesando = false;
            }
        },
        async activar(u) {
            try {
                await SC.api.put('/usuarios/' + u.id, { nombre: u.nombre, email: u.email, rol_id: u.rol_id, activo: true });
                SC.store.ok('Usuario "' + u.nombre + '" reactivado.');
                this.cargar();
            } catch (e) {
                SC.store.error(e.mensaje);
            }
        }
    }
};
