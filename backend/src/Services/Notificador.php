<?php
/**
 * Mensajes automáticos al cliente (confirmación, comprobante, envío, entrega).
 *
 * Funciona en LOCAL, sin API de WhatsApp de pago: el sistema redacta el mensaje solo y deja
 * un enlace https://wa.me/<telefono>?text=... Al abrirlo (un clic del encargado) WhatsApp Web o la
 * app del celular abren el chat con el texto listo para enviar.
 *
 * generar() se llama dentro de las transacciones de pago y de logística, así el mensaje se crea
 * únicamente si el pago/entrega realmente ocurrió.
 */
final class Notificador
{
    public const TIPOS = ['confirmacion', 'comprobante', 'envio', 'entrega'];

    public static function generar(int $pedidoId, string $tipo): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT usuario_id, telefono FROM pedidos WHERE id = ?');
        $stmt->execute([$pedidoId]);
        $ped = $stmt->fetch();
        $stmt->closeCursor();
        if (!$ped) {
            return;
        }
        $c = Comprobante::datos($pedidoId);
        $nombre = explode(' ', trim($c['cliente']['nombre']))[0];
        $guia = $c['despacho']['guia'] ?? null;

        $mensaje = match ($tipo) {
            'confirmacion' => "¡Hola $nombre! ✅ Tu pedido #$pedidoId en " . TIENDA_NOMBRE . " fue *confirmado* y recibimos tu pago de "
                . Comprobante::bs($c['total']) . ".\nLo enviaremos a: " . $c['entrega']['direccion'] . ', ' . $c['entrega']['ciudad'] . '.'
                . ($guia ? "\nGuía de seguimiento: $guia" : ''),
            'comprobante'  => Comprobante::texto($c),
            'envio'        => "🚚 ¡Hola $nombre! Tu pedido #$pedidoId ya está *en camino*." . ($guia ? "\nGuía: $guia" : '')
                . "\nTe llevaremos tu compra a: " . $c['entrega']['direccion'] . '. Ten tu documento a mano para recibirla.',
            'entrega'      => "📦 ¡Hola $nombre! Tu pedido #$pedidoId fue *entregado*. ¡Gracias por comprar en " . TIENDA_NOMBRE
                . "! Si algo no está bien, escríbenos a este mismo número.",
        };

        $pdo->prepare(
            'INSERT INTO notificaciones (pedido_id, usuario_id, tipo, telefono, mensaje, creado_en) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pedidoId, $ped['usuario_id'], $tipo, $ped['telefono'], $mensaje, date('Y-m-d H:i:s')]);
    }

    /** Deja solo dígitos y antepone el código de país si falta (Bolivia: celulares de 8 dígitos) */
    public static function telefonoWa(string $tel): string
    {
        $d = preg_replace('/\D+/', '', $tel);
        $d = ltrim($d, '0');
        return strlen($d) <= 8 ? PAIS_TELEFONO . $d : $d;
    }

    /** URL base de la aplicación según la petición actual (para el enlace al comprobante) */
    public static function urlApp(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (['/api/', '/backend/public/index.php'] as $marca) {
            if (($pos = strpos($uri, $marca)) !== false) {
                return $https . '://' . $host . substr($uri, 0, $pos) . '/frontend/';
            }
        }
        return $https . '://' . $host . '/frontend/';
    }

    /** Fila de `notificaciones` + enlace wa.me listo para abrir */
    public static function formatear(array $n): array
    {
        $n['id'] = (int)$n['id'];
        $n['pedido_id'] = (int)$n['pedido_id'];
        $texto = $n['mensaje'];
        if (in_array($n['tipo'], ['confirmacion', 'comprobante'], true) && ($base = self::urlApp()) !== '') {
            $texto .= "\n\nVer comprobante: " . $base . '#/comprobante/' . $n['pedido_id'];
        }
        $n['enlace_wa'] = 'https://wa.me/' . self::telefonoWa($n['telefono']) . '?text=' . rawurlencode($texto);
        return $n;
    }
}
