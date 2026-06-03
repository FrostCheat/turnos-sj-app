<?php
class AuthMiddleware {
    public static function handle() {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado. Por favor inicia sesión.']);
            exit;
        }
        return array(
            'id'   => (int)$_SESSION['user_id'],
            'role' => isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'user',
            'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '',
        );
    }

    public static function check() {
        return !empty($_SESSION['user_id']);
    }
}