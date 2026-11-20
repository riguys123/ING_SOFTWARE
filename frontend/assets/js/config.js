/*
 * Configuración del frontend.
 * API_BASE es la URL de la API REST, relativa a frontend/index.html.
 *  - Con mod_rewrite (XAMPP por defecto):  '../api'
 *  - Sin mod_rewrite:                      '../backend/public/index.php/api'
 */
window.SC_CONFIG = {
    API_BASE: '../api',
    // Celulares de la presentación (marca + modelo), en este orden. Si no hay ninguno en el catálogo se usan los más recientes.
    PRESENTACION: ['Apple iPhone 15 Pro', 'Google Pixel 8', 'Tecno Spark 20 Pro', 'Honor X7b', 'Motorola Moto G84 5G'],
    // Datos de cobro de la tienda (QR y depósito). Cambia estos valores por los reales del negocio.
    // Para usar tu QR del banco, reemplaza el archivo frontend/assets/img/qr-pago.png (si no existe, se muestra un QR de demostración).
    PAGO: {
        qr: 'assets/img/qr-pago.png',
        banco: 'Banco Demo S.A.',
        cuenta: '10000-123456-7',
        titular: 'SmartCell Commerce'
    }
};

/*
 * Retorno de PayPal: PayPal vuelve a /frontend/?paypal=ok&pedido=ID&token=ORDEN (query antes del #).
 * Como la app usa rutas con #, lo convertimos a una ruta hash antes de iniciar el router.
 */
(function () {
    try {
        var q = new URLSearchParams(window.location.search);
        var estado = q.get('paypal'), pedido = q.get('pedido');
        if (!estado || !/^\d+$/.test(pedido || '')) return;
        var ruta = estado === 'ok' && q.get('token')
            ? '#/paypal-retorno?pedido=' + pedido + '&token=' + encodeURIComponent(q.get('token'))
            : '#/pagar/' + pedido;
        history.replaceState(null, '', window.location.pathname + ruta);
    } catch (e) { /* sin retorno */ }
})();
