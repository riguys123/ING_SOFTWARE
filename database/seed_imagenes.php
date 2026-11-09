<?php
/**
 * Ilustraciones de ejemplo (SVG) para el catálogo y los banners del seeder.
 * Son dibujos genéricos generados con código (no son fotos reales ni logos de marcas):
 * sirven para que la tienda se vea completa desde el primer arranque.
 * Los productos reales se fotografían y se suben desde el panel (Productos → Fotos).
 */

function svg_envolver(string $cuerpo, int $w = 600, int $h = 600, string $c1 = '#eef2ff', string $c2 = '#dbe4ff'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 $w $h\" width=\"$w\" height=\"$h\">"
        . "<defs><linearGradient id=\"fondo\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"$c1\"/><stop offset=\"1\" stop-color=\"$c2\"/></linearGradient>"
        . '<linearGradient id="brillo" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".35"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>'
        . '<filter id="sombra" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="14" stdDeviation="14" flood-color="#1e293b" flood-opacity=".25"/></filter></defs>'
        . "<rect width=\"$w\" height=\"$h\" fill=\"url(#fondo)\"/>$cuerpo</svg>";
}

function svg_texto(string $t, int $x, int $y, int $tam, string $color, string $peso = '600', string $ancla = 'middle'): string
{
    $t = htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return "<text x=\"$x\" y=\"$y\" font-family=\"Segoe UI, Helvetica, Arial, sans-serif\" font-size=\"$tam\" font-weight=\"$peso\" fill=\"$color\" text-anchor=\"$ancla\">$t</text>";
}

function svg_oscurecer(string $hex, float $f): string
{
    [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    return sprintf('#%02x%02x%02x', (int)($r * $f), (int)($g * $f), (int)($b * $f));
}

/** Teléfono visto de frente (pantalla) o de espaldas (cámaras) */
function svg_telefono(string $color, string $marca, string $modelo, bool $frente): string
{
    $oscuro = svg_oscurecer($color, .55);
    $cuerpo = '<g filter="url(#sombra)"><rect x="205" y="70" width="190" height="400" rx="34" fill="' . ($frente ? '#0f172a' : $color) . '"/>';
    if ($frente) {
        $cuerpo .= '<rect x="215" y="82" width="170" height="376" rx="26" fill="' . $oscuro . '"/>'
            . '<rect x="215" y="82" width="170" height="376" rx="26" fill="url(#brillo)"/>'
            . '<circle cx="300" cy="104" r="6" fill="#0f172a"/>'
            . '<rect x="240" y="200" width="120" height="14" rx="7" fill="#fff" opacity=".85"/>'
            . '<rect x="262" y="226" width="76" height="8" rx="4" fill="#fff" opacity=".45"/>';
    } else {
        $cuerpo .= '<rect x="205" y="70" width="190" height="400" rx="34" fill="url(#brillo)"/>'
            . '<rect x="225" y="92" width="86" height="96" rx="22" fill="' . $oscuro . '"/>'
            . '<circle cx="251" cy="119" r="15" fill="#0b1220"/><circle cx="251" cy="119" r="7" fill="#334155"/>'
            . '<circle cx="251" cy="162" r="15" fill="#0b1220"/><circle cx="251" cy="162" r="7" fill="#334155"/>'
            . '<circle cx="288" cy="140" r="9" fill="#0b1220"/>'
            . svg_texto(strtoupper($marca), 300, 330, 22, '#ffffff', '700');
    }
    $cuerpo .= '</g>' . svg_texto($marca . ' ' . $modelo, 300, 530, 30, '#1e293b', '700')
        . svg_texto($frente ? 'Vista frontal' : 'Vista posterior', 300, 562, 18, '#64748b', '500');
    return svg_envolver($cuerpo);
}

/** Accesorios y complementos: un dibujo simple según el tipo */
function svg_accesorio(string $tipo, string $color, string $marca, string $modelo, string $fondo1, string $fondo2): string
{
    $o = svg_oscurecer($color, .6);
    $g = '<g filter="url(#sombra)">';
    switch ($tipo) {
        case 'cargador':
            $g .= '<rect x="240" y="150" width="120" height="170" rx="22" fill="' . $color . '"/><rect x="240" y="150" width="120" height="170" rx="22" fill="url(#brillo)"/>'
                . '<rect x="268" y="100" width="12" height="55" rx="3" fill="#94a3b8"/><rect x="320" y="100" width="12" height="55" rx="3" fill="#94a3b8"/>'
                . '<rect x="276" y="330" width="48" height="26" rx="6" fill="' . $o . '"/><path d="M300 356 C300 410 360 400 360 450" stroke="' . $o . '" stroke-width="10" fill="none" stroke-linecap="round"/>';
            break;
        case 'cable':
            $g .= '<path d="M150 400 C150 220 300 220 300 320 S450 420 450 230" stroke="' . $color . '" stroke-width="16" fill="none" stroke-linecap="round"/>'
                . '<rect x="128" y="400" width="44" height="64" rx="8" fill="#334155"/><rect x="428" y="170" width="44" height="64" rx="8" fill="#334155"/>';
            break;
        case 'funda':
            $g .= '<rect x="205" y="70" width="190" height="400" rx="40" fill="' . $color . '"/><rect x="205" y="70" width="190" height="400" rx="40" fill="url(#brillo)"/>'
                . '<rect x="225" y="92" width="92" height="100" rx="26" fill="#0f172a" opacity=".85"/><circle cx="254" cy="122" r="15" fill="#334155"/><circle cx="254" cy="166" r="15" fill="#334155"/>'
                . '<circle cx="300" cy="330" r="34" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="6"/>';
            break;
        case 'vidrio':
            $g .= '<rect x="205" y="70" width="190" height="400" rx="30" fill="#bae6fd" fill-opacity=".55" stroke="#7dd3fc" stroke-width="5"/>'
                . '<path d="M235 110 L330 110 L235 260 Z" fill="#fff" fill-opacity=".5"/><path d="M365 330 L365 430 L300 430 Z" fill="#fff" fill-opacity=".35"/>';
            break;
        case 'audifonos':
            $g .= '<ellipse cx="225" cy="270" rx="60" ry="72" fill="' . $color . '"/><ellipse cx="375" cy="270" rx="60" ry="72" fill="' . $color . '"/>'
                . '<rect x="205" y="318" width="30" height="110" rx="14" fill="' . $o . '"/><rect x="365" y="318" width="30" height="110" rx="14" fill="' . $o . '"/>'
                . '<ellipse cx="225" cy="260" rx="60" ry="72" fill="url(#brillo)"/>';
            break;
        case 'powerbank':
            $g .= '<rect x="195" y="140" width="210" height="300" rx="32" fill="' . $color . '"/><rect x="195" y="140" width="210" height="300" rx="32" fill="url(#brillo)"/>'
                . '<rect x="235" y="190" width="130" height="14" rx="7" fill="#fff" opacity=".8"/><rect x="235" y="222" width="90" height="14" rx="7" fill="#fff" opacity=".5"/>'
                . '<rect x="262" y="140" width="76" height="14" rx="4" fill="' . $o . '"/>' . svg_texto('10000 mAh', 300, 380, 28, '#ffffff', '700');
            break;
        case 'reloj':
            $g .= '<rect x="262" y="60" width="76" height="140" rx="14" fill="' . $o . '"/><rect x="262" y="360" width="76" height="140" rx="14" fill="' . $o . '"/>'
                . '<rect x="212" y="170" width="176" height="210" rx="46" fill="#0f172a"/><rect x="224" y="182" width="152" height="186" rx="36" fill="' . $color . '"/>'
                . '<rect x="224" y="182" width="152" height="186" rx="36" fill="url(#brillo)"/>' . svg_texto('10:09', 300, 292, 46, '#ffffff', '700')
                . svg_texto('♥ 72', 300, 332, 22, '#ffffff', '500');
            break;
        case 'parlante':
            $g .= '<rect x="170" y="220" width="260" height="160" rx="70" fill="' . $color . '"/><rect x="170" y="220" width="260" height="160" rx="70" fill="url(#brillo)"/>'
                . '<circle cx="250" cy="300" r="40" fill="' . $o . '"/><circle cx="350" cy="300" r="40" fill="' . $o . '"/><circle cx="250" cy="300" r="16" fill="#0f172a"/><circle cx="350" cy="300" r="16" fill="#0f172a"/>';
            break;
        case 'soporte':
            $g .= '<rect x="270" y="320" width="60" height="150" rx="18" fill="' . $o . '"/><rect x="170" y="440" width="260" height="30" rx="15" fill="#334155"/>'
                . '<rect x="215" y="90" width="170" height="250" rx="22" fill="' . $color . '"/><rect x="240" y="116" width="120" height="198" rx="14" fill="#0f172a" opacity=".85"/>';
            break;
        default: // tripode
            $g .= '<rect x="285" y="90" width="30" height="190" rx="14" fill="' . $color . '"/><rect x="250" y="70" width="100" height="50" rx="14" fill="#334155"/>'
                . '<path d="M300 280 L205 470 M300 280 L300 480 M300 280 L395 470" stroke="' . $o . '" stroke-width="16" stroke-linecap="round" fill="none"/>';
    }
    $g .= '</g>' . svg_texto($marca . ' ' . $modelo, 300, 536, 28, '#1e293b', '700');
    return svg_envolver($g, 600, 600, $fondo1, $fondo2);
}

/** Banner horizontal de promoción */
function svg_banner(string $titulo, string $subtitulo, string $etiqueta, string $c1, string $c2, string $forma): string
{
    $deco = '<circle cx="1010" cy="200" r="190" fill="#fff" fill-opacity=".10"/><circle cx="1110" cy="330" r="120" fill="#fff" fill-opacity=".10"/>';
    if ($forma === 'telefono') {
        $deco .= '<g transform="rotate(12 960 200)" filter="url(#sombra)"><rect x="880" y="40" width="150" height="320" rx="28" fill="#0f172a"/><rect x="890" y="52" width="130" height="296" rx="20" fill="' . $c2 . '"/><rect x="890" y="52" width="130" height="296" rx="20" fill="url(#brillo)"/></g>';
    } elseif ($forma === 'auricular') {
        $deco .= '<g filter="url(#sombra)"><ellipse cx="930" cy="180" rx="62" ry="78" fill="#fff"/><ellipse cx="1060" cy="220" rx="62" ry="78" fill="#fff" fill-opacity=".9"/></g>';
    } else {
        $deco .= '<g filter="url(#sombra)"><rect x="900" y="110" width="150" height="190" rx="42" fill="#0f172a"/><rect x="910" y="120" width="130" height="170" rx="32" fill="' . $c2 . '"/></g>';
    }
    $cuerpo = $deco
        . '<rect x="70" y="70" width="190" height="42" rx="21" fill="#fff" fill-opacity=".22"/>' . svg_texto($etiqueta, 165, 99, 22, '#ffffff', '700')
        . svg_texto($titulo, 70, 202, 62, '#ffffff', '800', 'start')
        . svg_texto($subtitulo, 70, 258, 28, '#ffffff', '500', 'start');
    return svg_envolver($cuerpo, 1200, 400, $c1, $c2);
}
