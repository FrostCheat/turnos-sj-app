<?php
class AdminMiddleware {
    public static function handle() {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autenticado.']);
            exit;
        }
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
            exit;
        }
        return array(
            'id'   => (int)$_SESSION['user_id'],
            'role' => 'admin',
            'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '',
        );
    }
}