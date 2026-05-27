<?php
// ============================================================
// CONTROLADOR DE FAVORITOS
// ============================================================
class FavoritesController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function listar(array $params, array $body): void {
        $usuario = AuthMiddleware::requerir();
        $stmt = $this->db->prepare(
            'SELECT c.id, c.titulo, c.descripcion, c.imagen_url, c.tipo, c.puntuacion,
                    cat.nombre AS categoria, cat.color AS categoria_color, f.creado_en AS guardado_en
             FROM favorites f
             JOIN content c ON f.content_id = c.id
             LEFT JOIN categorias cat ON c.categoria_id = cat.id
             WHERE f.user_id = ? AND c.activo = 1
             ORDER BY f.creado_en DESC'
        );
        $stmt->execute([$usuario['id']]);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    public function agregar(array $params, array $body): void {
        $usuario    = AuthMiddleware::requerir();
        $content_id = (int)($body['content_id'] ?? 0);

        if (!$content_id) {
            http_response_code(400);
            echo json_encode(['error' => 'content_id es requerido']);
            return;
        }

        try {
            $this->db->prepare('INSERT INTO favorites (user_id, content_id) VALUES (?, ?)')->execute([$usuario['id'], $content_id]);
            http_response_code(201);
            echo json_encode(['mensaje' => 'Agregado a favoritos'], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(['error' => 'Ya está en tus favoritos']);
            } else { throw $e; }
        }
    }

    public function eliminar(array $params, array $body): void {
        $usuario    = AuthMiddleware::requerir();
        $content_id = (int)($params['id'] ?? 0);
        $stmt = $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND content_id = ?');
        $stmt->execute([$usuario['id'], $content_id]);

        if ($stmt->rowCount()) {
            echo json_encode(['mensaje' => 'Eliminado de favoritos'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Favorito no encontrado']);
        }
    }
}

// ============================================================
// CONTROLADOR DE NOTIFICACIONES
// ============================================================
class NotificationsController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function listar(array $params, array $body): void {
        $usuario = AuthMiddleware::requerir();
        $stmt = $this->db->prepare(
            'SELECT id, titulo, mensaje, tipo, leida, creado_en FROM notifications
             WHERE user_id = ? ORDER BY creado_en DESC LIMIT 20'
        );
        $stmt->execute([$usuario['id']]);
        $notifs = $stmt->fetchAll();

        $noLeidas = array_filter($notifs, fn($n) => !$n['leida']);
        echo json_encode(['items' => $notifs, 'no_leidas' => count($noLeidas)], JSON_UNESCAPED_UNICODE);
    }

    public function marcarLeida(array $params, array $body): void {
        $usuario = AuthMiddleware::requerir();
        $id      = (int)($params['id'] ?? 0);
        $this->db->prepare('UPDATE notifications SET leida = 1 WHERE id = ? AND user_id = ?')->execute([$id, $usuario['id']]);
        echo json_encode(['mensaje' => 'Notificación marcada como leída'], JSON_UNESCAPED_UNICODE);
    }
}
