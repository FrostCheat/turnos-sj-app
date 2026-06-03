<?php
class DashboardController {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getDashboard(): array {
        AdminMiddleware::handle();

        $totalUsers    = (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $totalTurns    = (int)$this->db->query('SELECT COUNT(*) FROM turns')->fetchColumn();
        $waitingTurns  = (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status='waiting'")->fetchColumn();
        $activeTurns   = (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status='active'")->fetchColumn();
        $completedTurns= (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status='completed'")->fetchColumn();
        $cancelledTurns= (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE status='cancelled'")->fetchColumn();

        $todayTotal    = (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $todayCompleted= (int)$this->db->query("SELECT COUNT(*) FROM turns WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn();

        $recentTurns = $this->db->query("
            SELECT t.id, t.turn_number, t.service, t.status, t.created_at, u.name as user_name
            FROM turns t JOIN users u ON u.id = t.user_id
            ORDER BY t.created_at DESC LIMIT 5
        ")->fetchAll();

        $byService = $this->db->query("
            SELECT service, COUNT(*) as total,
                   SUM(status='completed') as completed,
                   SUM(status='waiting') as waiting,
                   SUM(status='cancelled') as cancelled
            FROM turns GROUP BY service ORDER BY total DESC
        ")->fetchAll();

        $state = $this->db->query('SELECT current_turn FROM queue_state WHERE id = 1')->fetch();

        return [
            'success'         => true,
            'stats'           => [
                'total_users'     => $totalUsers,
                'total_turns'     => $totalTurns,
                'waiting_turns'   => $waitingTurns,
                'active_turns'    => $activeTurns,
                'completed_turns' => $completedTurns,
                'cancelled_turns' => $cancelledTurns,
                'today_total'     => $todayTotal,
                'today_completed' => $todayCompleted,
                'current_turn'    => $state ? (int)$state['current_turn'] : 0,
            ],
            'recent_turns'    => $recentTurns,
            'by_service'      => $byService,
        ];
    }
}
