<?php
// ============================================================
// CONTROLADOR DE ADMINISTRACIÓN
// ============================================================

class AdminController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    // GET /admin/stats
    public function estadisticas(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();

        $stats = [
            'total_usuarios'  => (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'usuarios_activos'=> (int)$this->db->query('SELECT COUNT(*) FROM users WHERE activo = 1')->fetchColumn(),
            'total_contenido' => (int)$this->db->query('SELECT COUNT(*) FROM content WHERE activo = 1')->fetchColumn(),
            'total_favoritos' => (int)$this->db->query('SELECT COUNT(*) FROM favorites')->fetchColumn(),
            'vistas_totales'  => (int)$this->db->query('SELECT SUM(vistas) FROM content')->fetchColumn(),
            'usuarios_recientes' => $this->db->query(
                'SELECT id, nombre, email, rol, creado_en FROM users ORDER BY creado_en DESC LIMIT 5'
            )->fetchAll(),
            'contenido_popular' => $this->db->query(
                'SELECT id, titulo, vistas, puntuacion FROM content ORDER BY vistas DESC LIMIT 5'
            )->fetchAll(),
        ];

        echo json_encode($stats, JSON_UNESCAPED_UNICODE);
    }

    // GET /admin/users
    public function listarUsuarios(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();

        $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
        $offset    = ($pagina - 1) * ITEMS_PER_PAGE;
        $busqueda  = $_GET['q'] ?? '';

        $where  = ['1=1'];
        $valores = [];
        if ($busqueda) {
            $where[] = '(nombre LIKE ? OR email LIKE ?)';
            $valores[] = "%$busqueda%";
            $valores[] = "%$busqueda%";
        }

        $valores[] = ITEMS_PER_PAGE;
        $valores[] = $offset;

        $stmt = $this->db->prepare(
            'SELECT id, nombre, email, rol, activo, creado_en, ultimo_login FROM users
             WHERE ' . implode(' AND ', $where) . ' ORDER BY creado_en DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute($valores);
        $usuarios = $stmt->fetchAll();

        $total = (int)$this->db->query(
            'SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $where)
        )->fetchColumn();

        echo json_encode(['items' => $usuarios, 'total' => $total], JSON_UNESCAPED_UNICODE);
    }

    // PUT /admin/users/{id}
    public function actualizarUsuario(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();
        $id = (int)($params['id'] ?? 0);

        $campos  = [];
        $valores = [];

        if (isset($body['activo']))  { $campos[] = 'activo = ?'; $valores[] = (int)$body['activo']; }
        if (isset($body['rol']))     { $campos[] = 'rol = ?';    $valores[] = $body['rol']; }
        if (isset($body['nombre']))  { $campos[] = 'nombre = ?'; $valores[] = $body['nombre']; }

        if (empty($campos)) { http_response_code(400); echo json_encode(['error' => 'Sin campos']); return; }

        $valores[] = $id;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($valores);
        echo json_encode(['mensaje' => 'Usuario actualizado'], JSON_UNESCAPED_UNICODE);
    }

    // DELETE /admin/users/{id}
    public function eliminarUsuario(array $params, array $body): void {
        $admin = AuthMiddleware::requerirAdmin();
        $id    = (int)($params['id'] ?? 0);

        if ($id === $admin['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'No puedes eliminarte a ti mismo']);
            return;
        }

        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        echo json_encode(['mensaje' => 'Usuario eliminado'], JSON_UNESCAPED_UNICODE);
    }

    // GET /admin/content
    public function listarContenido(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();

        $stmt = $this->db->query(
            'SELECT c.id, c.titulo, c.tipo, c.vistas, c.puntuacion, c.activo, c.creado_en,
                    cat.nombre AS categoria
             FROM content c
             LEFT JOIN categorias cat ON c.categoria_id = cat.id
             ORDER BY c.creado_en DESC LIMIT 50'
        );
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    // POST /admin/content
    public function crearContenido(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();

        $requeridos = ['titulo', 'descripcion', 'tipo'];
        foreach ($requeridos as $campo) {
            if (empty($body[$campo])) {
                http_response_code(422);
                echo json_encode(['error' => "El campo '$campo' es requerido"]);
                return;
            }
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $body['titulo'])) . '-' . time();

        $stmt = $this->db->prepare(
            'INSERT INTO content (titulo, slug, descripcion, imagen_url, categoria_id, tipo, dificultad, duracion_minutos, activo, destacado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $body['titulo'], $slug, $body['descripcion'],
            $body['imagen_url'] ?? null, $body['categoria_id'] ?? null,
            $body['tipo'], $body['dificultad'] ?? 'principiante',
            $body['duracion_minutos'] ?? 0,
            isset($body['activo']) ? (int)$body['activo'] : 1,
            isset($body['destacado']) ? (int)$body['destacado'] : 0,
        ]);

        http_response_code(201);
        echo json_encode(['mensaje' => 'Contenido creado', 'id' => $this->db->lastInsertId()], JSON_UNESCAPED_UNICODE);
    }

    // PUT /admin/content/{id}
    public function actualizarContenido(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();
        $id      = (int)($params['id'] ?? 0);
        $campos  = [];
        $valores = [];

        $permitidos = ['titulo', 'descripcion', 'imagen_url', 'tipo', 'dificultad', 'duracion_minutos', 'activo', 'destacado'];
        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $body)) {
                $campos[]  = "$campo = ?";
                $valores[] = $body[$campo];
            }
        }

        if (empty($campos)) { http_response_code(400); echo json_encode(['error' => 'Sin campos']); return; }

        $valores[] = $id;
        $this->db->prepare('UPDATE content SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($valores);
        echo json_encode(['mensaje' => 'Contenido actualizado'], JSON_UNESCAPED_UNICODE);
    }

    // DELETE /admin/content/{id}
    public function eliminarContenido(array $params, array $body): void {
        AuthMiddleware::requerirAdmin();
        $id = (int)($params['id'] ?? 0);
        $this->db->prepare('UPDATE content SET activo = 0 WHERE id = ?')->execute([$id]);
        echo json_encode(['mensaje' => 'Contenido desactivado'], JSON_UNESCAPED_UNICODE);
    }
}
