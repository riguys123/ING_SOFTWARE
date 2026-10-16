/* Íconos SVG simples (trazo) para el menú y las acciones */
SC.ICONOS = {
    inicio:    'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    usuario:   'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
    usuarios:  'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM2 20a7 7 0 0114 0M17 4.5a3.5 3.5 0 010 6.5M18 14a6 6 0 013.5 6',
    escudo:    'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4',
    lista:     'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    etiqueta:  'M3 12V4h8l10 10-8 8L3 12zM7.5 8.5h.01',
    caja:      'M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v8',
    camion:    'M2 6h12v10H2zM14 10h4l4 3v3h-8M6 19a2 2 0 100-4 2 2 0 000 4zM18 19a2 2 0 100-4 2 2 0 000 4z',
    carrito:   'M3 4h2l2.5 11h11L21 7H6M9 20a1 1 0 100-2 1 1 0 000 2zM18 20a1 1 0 100-2 1 1 0 000 2z',
    billetera: 'M3 7h16a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7zm0 0l3-3h10M16 14h.01',
    ojo:       'M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
    ojotachado:'M17.9 17.9A10.9 10.9 0 0112 19c-7 0-11-7-11-7a19 19 0 014.2-5M9.9 5.2A10 10 0 0112 5c7 0 11 7 11 7a19 19 0 01-2.2 3.2M1 1l22 22',
    editar:    'M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z',
    papelera:  'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6',
    check:     'M5 13l4 4L19 7',
    mas:       'M12 5v14M5 12h14',
    buscar:    'M11 18a7 7 0 100-14 7 7 0 000 14zM21 21l-5-5',
    salir:     'M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9',
    menu:      'M3 6h18M3 12h18M3 18h18',
    cerrar:    'M6 6l12 12M18 6L6 18',
    candado:   'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
    obra:      'M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.5 2.5-2.5-.5-.5-2.5 2.5-2.5z'
};

SC.Icono = {
    props: { nombre: String, tam: { type: Number, default: 18 } },
    template: `<svg :width="tam" :height="tam" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                 <path :d="ruta"/>
               </svg>`,
    computed: { ruta() { return SC.ICONOS[this.nombre] || ''; } }
};
