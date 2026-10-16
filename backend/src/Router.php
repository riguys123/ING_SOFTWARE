<?php
/**
 * Enrutador mínimo con RBAC declarativo.
 *
 * $router->add('POST', '/usuarios', [Clase::class, 'metodo'], ['Admin']);
 *   - $roles = null  -> ruta pública (login)
 *   - $roles = []    -> cualquier usuario autenticado
 *   - $roles = [...] -> solo esos roles (requerirRol)
 */
final class Router
{
    private array $rutas = [];

    public function add(string $metodo, string $patron, callable|array $manejador, ?array $roles = []): void
    {
        $regex = '#^' . preg_replace('#:([a-z_]+)#', '(?P<$1>[0-9]+)', $patron) . '$#';
        $this->rutas[] = [$metodo, $regex, $manejador, $roles];
    }

    public function despachar(string $metodo, string $ruta): void
    {
        $rutaExiste = false;
        foreach ($this->rutas as [$m, $regex, $manejador, $roles]) {
            if (!preg_match($regex, $ruta, $coincidencias)) {
                continue;
            }
            $rutaExiste = true;
            if ($m !== $metodo) {
                continue;
            }
            $params = array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY);
            $params = array_map('intval', $params);

            // Middleware RBAC
            $yo = null;
            if ($roles !== null) {
                $yo = $roles === [] ? requerirLogin() : requerirRol($roles);
            }
            $manejador($yo, $params);
            return;
        }
        if ($rutaExiste) {
            Response::error(405, 'metodo_no_permitido', 'Método HTTP no permitido para esta ruta.');
        }
        Response::error(404, 'no_encontrado', 'La ruta solicitada no existe.');
    }
}
