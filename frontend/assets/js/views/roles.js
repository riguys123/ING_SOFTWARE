/* Pantalla 8: Gestión de roles y permisos (matriz visual) */
SC.views.Roles = {
    template: `
      <div>
        <div class="encabezado-pagina"><div><h1>Roles y permisos</h1><p class="sub">Qué rol accede a qué módulo (control de acceso RBAC)</p></div></div>

        <div v-if="cargando" class="tarjeta">Cargando…</div>
        <template v-else>
          <div class="tarjetas-roles">
            <div class="tarjeta rol-card" v-for="r in roles" :key="r.id">
              <div class="rol-cabecera"><strong>{{ r.nombre }}</strong><span class="badge badge-info">{{ r.usuarios }} usuario(s)</span></div>
              <p class="sub">{{ r.descripcion }}</p>
              <small class="cap">Área: {{ r.area }}</small>
            </div>
          </div>

          <h2 class="subtitulo">Matriz de permisos</h2>
          <div class="leyenda">
            <span class="celda-nivel total">✔ Acceso total</span>
            <span class="celda-nivel lectura">👁 Solo lectura</span>
            <span class="celda-nivel ninguno">— Sin acceso</span>
          </div>
          <div class="tabla-contenedor matriz">
            <table class="tabla-matriz">
              <thead>
                <tr>
                  <th class="col-modulo">Módulo</th>
                  <th v-for="r in matriz.roles" :key="r.id" class="col-rol"><span>{{ r.nombre }}</span></th>
                </tr>
              </thead>
              <tbody>
                <template v-for="g in grupos" :key="g.nombre">
                  <tr class="fila-grupo"><td :colspan="matriz.roles.length + 1">{{ g.nombre }}</td></tr>
                  <tr v-for="m in g.modulos" :key="m.clave">
                    <td class="col-modulo"><strong>{{ m.nombre }}</strong><br><small>{{ m.descripcion }}</small></td>
                    <td v-for="r in matriz.roles" :key="r.id" class="col-rol">
                      <span class="celda-nivel" :class="nivel(r.id, m.clave) || 'ninguno'"
                            :title="etiqueta(nivel(r.id, m.clave))">
                        {{ simbolo(nivel(r.id, m.clave)) }}
                      </span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>
      </div>`,
    data() { return { cargando: true, roles: [], matriz: { roles: [], modulos: [], matriz: {} } }; },
    computed: {
        grupos() {
            const mapa = {};
            this.matriz.modulos.forEach(m => { (mapa[m.grupo] = mapa[m.grupo] || { nombre: m.grupo, modulos: [] }).modulos.push(m); });
            return Object.values(mapa);
        }
    },
    async created() {
        try {
            const [r, m] = await Promise.all([SC.api.get('/roles'), SC.api.get('/permisos')]);
            this.roles = r.data;
            this.matriz = m.data;
        } catch (e) { SC.store.error(e.mensaje); }
        this.cargando = false;
    },
    methods: {
        nivel(rolId, clave) { return (this.matriz.matriz[rolId] || {})[clave] || null; },
        simbolo(n) { return n === 'total' ? '✔' : (n === 'lectura' ? '👁' : '—'); },
        etiqueta(n) { return n === 'total' ? 'Acceso total' : (n === 'lectura' ? 'Solo lectura' : 'Sin acceso'); }
    }
};
