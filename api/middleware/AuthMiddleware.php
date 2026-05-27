<?php
// ============================================================
// MIDDLEWARE DE AUTENTICACIÓN
// ============================================================

require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/../config/database.php';

class AuthMiddleware {

    /**
     * Valida el JWT y retorna los datos del usuario autenticado.
     * Si no es válido, responde con 401 y termina la ejecución.
     */
    public static function requerir(): array {
        $token = JWT::extraerDelHeader();

        if (!$token) {
            self::responderNoAutorizado('Token de autenticación requerido');
        }

        try {
            $payload = JWT::decode($token);
        } catch (RuntimeException $e) {
            self::responderNoAutorizado($e->getMessage());
        }

        // Verificar que el usuario aún existe y está activo
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, nombre, email, rol, activo FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$payload['sub']]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo']) {
            self::responderNoAutorizado('Usuario no encontrado o inactivo');
        }

        return $usuario;
    }

    /**
     * Igual que requerir() pero además valida que el rol sea 'admin'
     */
    public static function requerirAdmin(): array {
        $usuario = self::requerir();

        if ($usuario['rol'] !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Acceso denegado: se requiere rol de administrador']);
            exit;
        }

        return $usuario;
    }

    private static function responderNoAutorizado(string $mensaje): never {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => $mensaje]);
        exit;
    }
}
