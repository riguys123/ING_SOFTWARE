/* Modal de confirmación reutilizable */
SC.Modal = {
    props: {
        abierto: Boolean,
        titulo: String,
        textoConfirmar: { type: String, default: 'Confirmar' },
        peligro: Boolean,
        cargando: Boolean,
        grande: Boolean            // ancho extra para formularios
    },
    emits: ['confirmar', 'cancelar'],
    template: `
      <div v-if="abierto" class="modal-fondo" @click.self="$emit('cancelar')" @keydown.esc="$emit('cancelar')">
        <div class="modal" :class="{ 'modal-grande': grande }" role="dialog" aria-modal="true" :aria-label="titulo">
          <h3>{{ titulo }}</h3>
          <div class="modal-cuerpo"><slot></slot></div>
          <div class="modal-acciones">
            <button type="button" class="btn btn-secundario" @click="$emit('cancelar')" :disabled="cargando">Cancelar</button>
            <button type="button" class="btn" :class="peligro ? 'btn-peligro' : 'btn-primario'"
                    @click="$emit('confirmar')" :disabled="cargando">
              {{ cargando ? 'Procesando…' : textoConfirmar }}
            </button>
          </div>
        </div>
      </div>`
};
