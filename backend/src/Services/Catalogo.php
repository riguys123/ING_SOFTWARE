<?php
/**
 * Utilidades compartidas del catálogo: formato de un producto, precio con promoción vigente.
 */
final class Catalogo
{
    /**
     * Subconsulta SQL con el mayor descuento (%) vigente hoy para el producto de alias $p.
     * Aplica si la promoción apunta al producto, o a su categoría (cuando no apunta a un producto).
     */
    public static function descuentoSql(string $p = 'p'): string
    {
        return "COALESCE((SELECT MAX(pr.descuento_pct) FROM promociones pr
                  WHERE pr.activo = 1 AND pr.descuento_pct > 0
                    AND date('now','localtime') BETWEEN pr.fecha_inicio AND pr.fecha_fin
                    AND (pr.producto_id = $p.id
                         OR (pr.producto_id IS NULL AND pr.categoria_id IS NOT NULL AND pr.categoria_id = $p.categoria_id))), 0)";
    }

    public static function precioFinal(float $precio, int $descuentoPct): float
    {
        return round($precio * (1 - $descuentoPct / 100), 2);
    }

    /** Convierte una fila de `productos` a tipos correctos (int / float / array) */
    public static function formatear(array $p): array
    {
        foreach (['id', 'ram_gb', 'almacenamiento_gb', 'bateria_mah', 'stock_actual', 'stock_minimo', 'activo',
                  'categoria_id', 'requiere_imei'] as $k) {
            if (array_key_exists($k, $p) && $p[$k] !== null) {
                $p[$k] = (int)$p[$k];
            }
        }
        if (array_key_exists('precio', $p)) {
            $p['precio'] = (float)$p['precio'];
            // Si la consulta no trajo el descuento, se calcula aquí (listados sí lo traen en el SQL)
            if (!array_key_exists('descuento_pct', $p) && isset($p['id'])) {
                $stmt = Database::pdo()->prepare('SELECT ' . self::descuentoSql('p') . ' FROM productos p WHERE p.id = ?');
                $stmt->execute([$p['id']]);
                $p['descuento_pct'] = (int)$stmt->fetchColumn();
            }
            $p['descuento_pct'] = (int)($p['descuento_pct'] ?? 0);
            $p['precio_final'] = self::precioFinal($p['precio'], $p['descuento_pct']);
        }
        $p['fotos'] = self::fotos($p['fotos'] ?? '[]');
        if (array_key_exists('stock_actual', $p)) {
            $p['disponible'] = $p['stock_actual'] > 0;
        }
        return $p;
    }

    public static function fotos(string $json): array
    {
        $f = json_decode($json, true);
        return is_array($f) ? array_values(array_filter($f, 'is_string')) : [];
    }
}
