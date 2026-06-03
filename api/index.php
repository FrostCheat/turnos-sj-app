<?php
if (ob_get_level() === 0) ob_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/middleware/CorsMiddleware.php';
require_once __DIR__ . '/middleware/CsrfMiddleware.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/AdminMiddleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/QueueController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/NotificationController.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '1');

session_name(SESSION_NAME);
session_start();

setSecureHeaders();
CorsMiddleware::handle();

$action = trim($_GET['action'] ?? '');

if ($action !== 'sse_queue') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

CsrfMiddleware::handle($action);

if (!$action) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Acción requerida.']);
    exit;
}

try {
    $db     = getDbConnection();
    $auth   = new AuthController($db);
    $user   = new UserController($db);
    $queue  = new QueueController($db);
    $dash   = new DashboardController($db);
    $notify = new NotificationController($db);

    $result = null;
    switch ($action) {
        case 'login':         $result = $auth->login(); break;
        case 'register':      $result = $auth->register(); break;
        case 'logout':        $result = $auth->logout(); break;
        case 'get_session':   $result = $auth->getSession(); break;
        case 'csrf_token':    $result = $auth->getCsrfToken(); break;
        case 'get_user':      $result = $user->getUser(); break;
        case 'get_users':     $result = $user->getUsers(); break;
        case 'create_user':   $result = $user->createUser(); break;
        case 'update_user':   $result = $user->updateUser(); break;
        case 'delete_user':   $result = $user->deleteUser(); break;
        case 'get_queue':     $result = $queue->getQueue(); break;
        case 'get_turn':      $result = $queue->getTurn(); break;
        case 'get_turns':     $result = $queue->getTurns(); break;
        case 'create_turn':   $result = $queue->createTurn(); break;
        case 'update_turn':   $result = $queue->updateTurn(); break;
        case 'delete_turn':   $result = $queue->deleteTurn(); break;
        case 'activate_next': $result = $queue->activateNext(); break;
        case 'complete_turn': $result = $queue->completeTurn(); break;
        case 'cancel_turn':   $result = $queue->cancelTurn(); break;
        case 'reorder_queue': $result = $queue->reorderQueue(); break;
        case 'increase_turn': $result = $queue->advanceTurn(); break;
        case 'decrease_turn': $result = $queue->regressTurn(); break;
        case 'get_services':  $result = $queue->getServices(); break;
        case 'get_dashboard': $result = $dash->getDashboard(); break;
        case 'sse_queue':
            ob_end_clean();
            $notify->sseQueue();
            exit;
        default:
            $result = ['success' => false, 'message' => 'Accion no reconocida.'];
    }

    ob_end_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()]);
}