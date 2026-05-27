<?php
// ============================================================
// CONTROLADOR DE USUARIO
// ============================================================

class UserController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function obtener(array $params, array $body): void {
        $usuario = AuthMiddleware::requerir();
        $stmt = $this->db->prepare(
            'SELECT id, nombre, email, rol, avatar_url, creado_en, ultimo_login FROM users WHERE id = ?'
        );
        $stmt->execute([$usuario['id']]);
        $perfil = $stmt->fetch();

        // Contar favoritos
        $stmt2 = $this->db->prepare('SELECT COUNT(*) as total FROM favorites WHERE user_id = ?');
        $stmt2->execute([$usuario['id']]);
        $perfil['total_favoritos'] = (int)$stmt2->fetch()['total'];

        http_response_code(200);
        echo json_encode($perfil, JSON_UNESCAPED_UNICODE);
    }

    public function actualizar(array $params, array $body): void {
        $usuario = AuthMiddleware::requerir();
        $nombre     = trim($body['nombre'] ?? '');
        $avatar_url = trim($body['avatar_url'] ?? '');

        if ($nombre && strlen($nombre) < 2) {
            http_response_code(422);
            echo json_encode(['error' => 'El nombre debe tener al menos 2 caracteres']);
            return;
        }

        $campos = [];
        $valores = [];
        if ($nombre)     { $campos[] = 'nombre = ?';     $valores[] = $nombre; }
        if ($avatar_url) { $campos[] = 'avatar_url = ?'; $valores[] = $avatar_url; }

        if (empty($campos)) {
            http_response_code(400);
            echo json_encode(['error' => 'No hay campos para actualizar']);
            return;
        }

        $valores[] = $usuario['id'];
        $this->db->prepare('UPDATE users SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($valores);

        http_response_code(200);
        echo json_encode(['mensaje' => 'Perfil actualizado'], JSON_UNESCAPED_UNICODE);
    }
}
