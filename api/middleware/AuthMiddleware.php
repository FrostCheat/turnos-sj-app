<?php
class AuthMiddleware {
    public static function handle(): array {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado. Por favor inicia sesión.']);
            exit;
        }
        return [
            'id'   => (int)$_SESSION['user_id'],
            'role' => $_SESSION['user_role'] ?? 'user',
            'name' => $_SESSION['user_name'] ?? '',
        ];
    }

    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }
}
