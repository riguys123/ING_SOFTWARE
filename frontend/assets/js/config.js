/*
 * Configuración del frontend.
 * API_BASE es la URL de la API REST, relativa a frontend/index.html.
 *  - Con mod_rewrite (XAMPP por defecto):  '../api'
 *  - Sin mod_rewrite:                      '../backend/public/index.php/api'
 */
window.SC_CONFIG = {
    API_BASE: '../api'
};
