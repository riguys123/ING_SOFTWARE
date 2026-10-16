/* Pantalla 9: Registro de auditoría (logs_acceso) */
SC.views.Auditoria = {
    template: `
      <div>
        <div class="encabezado-pagina"><div><h1>Auditoría de accesos</h1><p class="sub">Intentos de inicio de sesión registrados por el sistema</p></div></div>

        <div class="filtros">
          <div class="campo campo-buscar">
            <label for="lq">Correo o IP</label>
            <input id="lq" type="search" v-model="filtros.q" placeholder="Buscar…" @input="alFiltrar(true)">
          </div>
          <div class="campo">
            <label for="lr">Resultado</label>
            <select id="lr" v-model="filtros.resultado" @change="alFiltrar(false)">
              <option value="">Todos</option>
              <option value="exito">Exitoso</option>
              <option value="fallido">Fallido</option>
              <option value="bloqueado">Bloqueado</option>
            </select>
          </div>
          <div class="campo">
            <label for="ld">Desde</label>
            <input id="ld" type="date" v-model="filtros.desde" @change="alFiltrar(false)">
          </div>
          <div class="campo">
            <label for="lh">Hasta</label>
            <input id="lh" type="date" v-model="filtros.hasta" @change="alFiltrar(false)">
          </div>
          <button class="btn btn-secundario btn-sm limpiar" @click="limpiar">Limpiar</button>
        </div>

        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th>Correo ingresado</th><th>Usuario</th><th>Resultado</th><th>IP</th></tr></thead>
            <tbody>
              <tr v-if="cargando"><td colspan="5" class="vacio">Cargando…</td></tr>
              <tr v-else-if="!items.length"><td colspan="5" class="vacio">No hay registros con esos filtros.</td></tr>
              <tr v-for="l in items" :key="l.id" v-show="!cargando">
                <td data-label="Fecha">{{ fecha(l.fecha) }}</td>
                <td data-label="Correo">{{ l.email_intento }}</td>
                <td data-label="Usuario">{{ l.usuario_nombre || '—' }}</td>
                <td data-label="Resultado"><span class="badge" :class="clase(l.resultado)">{{ texto(l.resultado) }}</span></td>
                <td data-label="IP">{{ l.ip }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <sc-paginador :meta="meta" @cambiar="irAPagina"/>
      </div>`,
    data() {
        return { items: [], meta: null, cargando: true, pagina: 1, temporizador: null,
                 filtros: { q: '', resultado: '', desde: '', hasta: '' } };
    },
    created() { this.cargar(); },
    methods: {
        fecha: SC.util.fecha,
        clase(r) { return { exito: 'badge-ok', fallido: 'badge-error', bloqueado: 'badge-warn' }[r]; },
        texto(r) { return { exito: 'Exitoso', fallido: 'Fallido', bloqueado: 'Bloqueado' }[r]; },
        async cargar() {
            this.cargando = true;
            try {
                const r = await SC.api.get('/logs-acceso', { ...this.filtros, pagina: this.pagina, por_pagina: 15 });
                this.items = r.data;
                this.meta = r.meta;
            } catch (e) { SC.store.error(e.mensaje); }
            finally { this.cargando = false; }
        },
        alFiltrar(conRetraso) {
            clearTimeout(this.temporizador);
            this.pagina = 1;
            if (conRetraso) this.temporizador = setTimeout(() => this.cargar(), 350);
            else this.cargar();
        },
        limpiar() { this.filtros = { q: '', resultado: '', desde: '', hasta: '' }; this.alFiltrar(false); },
        irAPagina(p) { this.pagina = p; this.cargar(); }
    }
};
