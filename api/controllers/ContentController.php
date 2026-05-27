<?php
// ============================================================
// CONTROLADOR DE CONTENIDO
// ============================================================

class ContentController {
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function listar(array $params, array $body): void {
        $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina = ITEMS_PER_PAGE;
        $offset    = ($pagina - 1) * $porPagina;
        $categoria = $_GET['categoria'] ?? '';
        $busqueda  = $_GET['q'] ?? '';
        $tipo      = $_GET['tipo'] ?? '';
        $destacado = $_GET['destacado'] ?? '';

        $where   = ['c.activo = 1'];
        $valores = [];

        if ($categoria) { $where[] = 'cat.slug = ?'; $valores[] = $categoria; }
        if ($busqueda)  { $where[] = '(c.titulo LIKE ? OR c.descripcion LIKE ?)'; $valores[] = "%$busqueda%"; $valores[] = "%$busqueda%"; }
        if ($tipo)      { $where[] = 'c.tipo = ?'; $valores[] = $tipo; }
        if ($destacado) { $where[] = 'c.destacado = 1'; }

        $sql = 'SELECT c.id, c.titulo, c.slug, c.descripcion, c.imagen_url, c.tipo,
                       c.dificultad, c.duracion_minutos, c.puntuacion, c.vistas, c.destacado,
                       cat.nombre AS categoria, cat.color AS categoria_color, cat.icono AS categoria_icono
                FROM content c
                LEFT JOIN categorias cat ON c.categoria_id = cat.id
                WHERE ' . implode(' AND ', $where) .
               ' ORDER BY c.destacado DESC, c.vistas DESC
                LIMIT ? OFFSET ?';

        $valores[] = $porPagina;
        $valores[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($valores);
        $items = $stmt->fetchAll();

        // Total para paginación
        $sqlCount = 'SELECT COUNT(*) FROM content c LEFT JOIN categorias cat ON c.categoria_id = cat.id WHERE ' . implode(' AND ', $where);
        $valoresCount = array_slice($valores, 0, -2);
        $total = (int)$this->db->prepare($sqlCount)->execute($valoresCount) ? 
                 $this->db->query(str_replace('SELECT COUNT(*)', 'SELECT COUNT(*) as n', $sqlCount))->fetch()['n'] ?? 0 : 0;

        // Recuento más confiable
        $stmtTotal = $this->db->prepare(str_replace('SELECT c.id', 'SELECT COUNT(*) as n', explode('ORDER', $sql)[0]));
        $stmtTotal->execute(array_slice($valores, 0, -2));
        $totalFila = $stmtTotal->fetch();
        $total = (int)($totalFila['n'] ?? 0);

        http_response_code(200);
        echo json_encode([
            'items'       => $items,
            'total'       => $total,
            'pagina'      => $pagina,
            'por_pagina'  => $porPagina,
            'total_paginas' => ceil($total / $porPagina),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function obtener(array $params, array $body): void {
        $id = (int)($params['id'] ?? 0);

        $stmt = $this->db->prepare(
            'SELECT c.*, cat.nombre AS categoria, cat.color AS categoria_color,
                    u.nombre AS autor_nombre
             FROM content c
             LEFT JOIN categorias cat ON c.categoria_id = cat.id
             LEFT JOIN users u ON c.autor_id = u.id
             WHERE c.id = ? AND c.activo = 1'
        );
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Contenido no encontrado']);
            return;
        }

        // Incrementar vistas
        $this->db->prepare('UPDATE content SET vistas = vistas + 1 WHERE id = ?')->execute([$id]);

        http_response_code(200);
        echo json_encode($item, JSON_UNESCAPED_UNICODE);
    }
}
