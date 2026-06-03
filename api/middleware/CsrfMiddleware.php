<?php
class CsrfMiddleware {
    private static array $exempt = ['login', 'register', 'get_queue', 'get_turn', 'get_user', 'get_users', 'get_dashboard', 'sse_queue', 'get_services', 'csrf_token'];

    public static function handle(string $action): void {
        if (in_array($action, self::$exempt, true)) return;
        if ($_SERVER['REQUEST_METHOD'] === 'GET') return;

        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? (json_decode(file_get_contents('php://input'), true)['csrf_token'] ?? '');

        if (!verifyCsrfToken((string)$token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido.']);
            exit;
        }
    }
}
