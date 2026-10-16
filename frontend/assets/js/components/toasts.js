/* Notificaciones emergentes (éxito / error) */
SC.Toasts = {
    computed: { toasts() { return SC.store.toasts; } },
    template: `
      <div class="toasts" aria-live="polite">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="'toast-' + t.tipo">{{ t.mensaje }}</div>
      </div>`
};
