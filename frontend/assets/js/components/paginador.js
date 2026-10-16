/* Paginación: recibe el objeto meta de la API ({ total, pagina, total_paginas }) */
SC.Paginador = {
    props: { meta: Object },
    emits: ['cambiar'],
    template: `
      <div class="paginador" v-if="meta">
        <span class="paginador-info">{{ meta.total }} registro(s) · Página {{ meta.pagina }} de {{ meta.total_paginas }}</span>
        <div class="paginador-botones">
          <button class="btn btn-secundario btn-sm" :disabled="meta.pagina <= 1" @click="$emit('cambiar', meta.pagina - 1)">‹ Anterior</button>
          <button class="btn btn-secundario btn-sm" :disabled="meta.pagina >= meta.total_paginas" @click="$emit('cambiar', meta.pagina + 1)">Siguiente ›</button>
        </div>
      </div>`
};
