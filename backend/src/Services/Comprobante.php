<?php
/**
 * Comprobante del pedido: se arma SIEMPRE desde la BD (nunca con datos del navegador).
 * datos()  -> estructura para la pantalla imprimible (y guardar como PDF desde el navegador).
 * texto()  -> versión en texto plano para WhatsApp.
 */
final class Comprobante
{
    public static function numero(int $pedidoId): string
    {
        return 'CP-' . str_pad((string)$pedidoId, 6, '0', STR_PAD_LEFT);
    }

    /** Formato de dinero como en la tienda: Bs 1.450,00 */
    public static function bs(float $n): string
    {
        return 'Bs ' . number_format($n, 2, ',', '.');
    }

    /** @throws ApiException 409 si el pedido aún no está pagado (no hay nada que comprobar) */
    public static function datos(int $pedidoId): array
    {
        $p = PedidoService::obtener($pedidoId);
        if (!$p) {
            throw new ApiException(404, 'no_encontrado', 'El pedido no existe.');
        }
        $pago = null;
        foreach ($p['pagos'] as $g) {
            if ($g['estado'] === 'Aprobado') {
                $pago = $g;
                break;
            }
        }
        if ($p['estado_pago'] !== 'Pagado' || !$pago) {
            throw new ApiException(409, 'sin_comprobante', 'El comprobante se emite cuando el pago está confirmado.');
        }

        // Una línea por producto (las unidades se desdoblaron en filas con su IMEI)
        $lineas = [];
        $listaTotal = 0.0;
        foreach ($p['items'] as $i) {
            $k = $i['producto_id'] . '|' . $i['precio_unitario'];
            if (!isset($lineas[$k])) {
                $lineas[$k] = [
                    'producto' => $i['marca'] . ' ' . $i['modelo'], 'cantidad' => 0,
                    'precio_lista' => $i['precio_lista'], 'descuento_pct' => $i['descuento_pct'],
                    'precio_unitario' => $i['precio_unitario'], 'subtotal' => 0.0, 'imeis' => [],
                ];
            }
            $lineas[$k]['cantidad'] += $i['cantidad'];
            $lineas[$k]['subtotal'] = round($lineas[$k]['subtotal'] + $i['subtotal'], 2);
            if ($i['imei_asignado']) {
                $lineas[$k]['imeis'][] = $i['imei_asignado'];
            }
            $listaTotal += $i['precio_lista'] * $i['cantidad'];
        }

        return [
            'numero'  => self::numero($pedidoId),
            'pedido_id' => $pedidoId,
            'emitido_en' => $pago['fecha'],
            'tienda'  => ['nombre' => TIENDA_NOMBRE, 'direccion' => TIENDA_DIRECCION,
                          'telefono' => TIENDA_TELEFONO, 'nit' => TIENDA_NIT],
            'cliente' => ['nombre' => $p['cliente_nombre'], 'email' => $p['cliente_email'], 'telefono' => $p['telefono']],
            'entrega' => ['direccion' => $p['direccion_envio'], 'ciudad' => $p['ciudad']],
            'pago'    => ['metodo' => $pago['metodo'], 'referencia' => $pago['referencia'],
                          'tarjeta_ultimos4' => $pago['tarjeta_ultimos4'], 'fecha' => $pago['fecha']],
            'items'   => array_values($lineas),
            'subtotal' => round($listaTotal, 2),
            'descuento' => round($listaTotal - $p['total'], 2),
            'total'   => $p['total'],
            'despacho' => $p['despacho'] ? ['guia' => $p['despacho']['codigo_guia'], 'estado' => $p['despacho']['estado']] : null,
        ];
    }

    /** Texto para WhatsApp (negritas con *asteriscos*) */
    public static function texto(array $c): string
    {
        $l = ['🧾 *Comprobante ' . $c['numero'] . '*', TIENDA_NOMBRE . ' · NIT ' . TIENDA_NIT, ''];
        foreach ($c['items'] as $i) {
            $l[] = '• ' . $i['cantidad'] . ' × ' . $i['producto'] . ' — ' . self::bs($i['subtotal'])
                 . ($i['descuento_pct'] > 0 ? ' (-' . $i['descuento_pct'] . '%)' : '');
            foreach ($i['imeis'] as $imei) {
                $l[] = '   IMEI: ' . $imei;
            }
        }
        $l[] = '';
        if ($c['descuento'] > 0) {
            $l[] = 'Descuento: -' . self::bs($c['descuento']);
        }
        $l[] = '*Total pagado: ' . self::bs($c['total']) . '*';
        $l[] = 'Método: ' . $c['pago']['metodo'] . ' · Ref. ' . $c['pago']['referencia'];
        $l[] = 'Fecha: ' . $c['emitido_en'];
        return implode("\n", $l);
    }
}
