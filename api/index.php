<?php
// ============================================================
// PUNTO DE ENTRADA - API REST NovaSphere
// index.php (raíz del backend)
// ============================================================

// Cabeceras CORS y tipo de respuesta
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/JWT.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// Cargar todos los controladores
require_once __DIR__ . '/api/AuthController.php';
require_once __DIR__ . '/api/UserController.php';
require_once __DIR__ . '/api/ContentController.php';
require_once __DIR__ . '/api/FavoritesController.php';
require_once __DIR__ . '/api/NotificationsController.php';
require_once __DIR__ . '/api/AdminController.php';

// ---- Router simple ----
$metodo = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Eliminar prefijo /api si existe
$uri = preg_replace('#^/api#', '', $uri);
$uri = rtrim($uri, '/') ?: '/';

// Leer body JSON
$bodyRaw  = file_get_contents('php://input');
$body     = json_decode($bodyRaw, true) ?? [];

// ---- Tabla de rutas ----
$rutas = [
    // Autenticación
    'POST /login'    => [AuthController::class, 'login'],
    'POST /register' => [AuthController::class, 'register'],
    'POST /logout'   => [AuthController::class, 'logout'],

    // Perfil de usuario
    'GET /profile'   => [UserController::class, 'obtener'],
    'PUT /profile'   => [UserController::class, 'actualizar'],

    // Contenido público
    'GET /content'        => [ContentController::class, 'listar'],
    'GET /content/{id}'   => [ContentController::class, 'obtener'],

    // Favoritos
    'GET /favorites'           => [FavoritesController::class, 'listar'],
    'POST /favorites'          => [FavoritesController::class, 'agregar'],
    'DELETE /favorites/{id}'   => [FavoritesController::class, 'eliminar'],

    // Notificaciones
    'GET /notifications'        => [NotificationsController::class, 'listar'],
    'PUT /notifications/{id}'   => [NotificationsController::class, 'marcarLeida'],

    // Admin
    'GET /admin/stats'           => [AdminController::class, 'estadisticas'],
    'GET /admin/users'           => [AdminController::class, 'listarUsuarios'],
    'PUT /admin/users/{id}'      => [AdminController::class, 'actualizarUsuario'],
    'DELETE /admin/users/{id}'   => [AdminController::class, 'eliminarUsuario'],
    'GET /admin/content'         => [AdminController::class, 'listarContenido'],
    'POST /admin/content'        => [AdminController::class, 'crearContenido'],
    'PUT /admin/content/{id}'    => [AdminController::class, 'actualizarContenido'],
    'DELETE /admin/content/{id}' => [AdminController::class, 'eliminarContenido'],
];

// ---- Resolver ruta ----
$params = [];
$handlerEncontrado = null;

foreach ($rutas as $patron => $handler) {
    [$metodoPat, $uriPat] = explode(' ', $patron, 2);

    if ($metodoPat !== $metodo) continue;

    // Convertir {id} a regex
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $uriPat) . '$#';

    if (preg_match($regex, $uri, $matches)) {
        // Extraer parámetros nombrados
        foreach ($matches as $k => $v) {
            if (is_string($k)) $params[$k] = $v;
        }
        $handlerEncontrado = $handler;
        break;
    }
}

if (!$handlerEncontrado) {
    http_response_code(404);
    echo json_encode(['error' => "Ruta no encontrada: $metodo $uri"]);
    exit;
}

// ---- Ejecutar controlador ----
try {
    [$clase, $metodoCtrl] = $handlerEncontrado;
    $controlador = new $clase();
    $controlador->$metodoCtrl($params, $body);
} catch (Throwable $e) {
    http_response_code(500);
    $respuesta = ['error' => 'Error interno del servidor'];
    if (DEBUG) $respuesta['detalle'] = $e->getMessage();
    echo json_encode($respuesta);
}
