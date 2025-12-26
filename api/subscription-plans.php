

<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/session-auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    error_log('DB connection error: ' . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Only admin can manage plans


switch ($method) {
    case 'GET':
        // List all active plans for public, all plans for admin
        try {
            if (isset($_GET['id'])) {
                $stmt = $db->prepare('SELECT * FROM sprakapp_subscription_plans WHERE id = ?');
                $stmt->execute([$_GET['id']]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($plan) {
                    echo json_encode($plan);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Plan not found']);
                }
            } else {
                $user = SessionAuth::getUser();
                if ($user && isset($user->role) && $user->role === 'admin') {
                    $stmt = $db->query('SELECT * FROM sprakapp_subscription_plans ORDER BY price_monthly ASC');
                } else {
                    $stmt = $db->prepare('SELECT * FROM sprakapp_subscription_plans WHERE is_active=1 ORDER BY price_monthly ASC');
                    $stmt->execute();
                }
                $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($plans);
            }
        } catch (Exception $e) {
            http_response_code(500);
            error_log('GET subscription-plans error: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to fetch plans']);
        }
        break;
    case 'POST':
        // Create or update plan (admin only)
        SessionAuth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'));
        try {
            if (!isset($data->name, $data->num_courses, $data->price_monthly, $data->currency)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields', 'data' => $data]);
                exit;
            }
            if (isset($data->id)) {
                // Update
                $stmt = $db->prepare('UPDATE sprakapp_subscription_plans SET name=?, description=?, num_courses=?, price_monthly=?, currency=?, is_active=?, stripe_price_id=? WHERE id=?');
                $stmt->execute([
                    $data->name,
                    $data->description ?? '',
                    $data->num_courses,
                    $data->price_monthly,
                    $data->currency,
                    $data->is_active ?? 1,
                    $data->stripe_price_id ?? null,
                    $data->id
                ]);
            } else {
                // Create
                $stmt = $db->prepare('INSERT INTO sprakapp_subscription_plans (name, description, num_courses, price_monthly, currency, is_active, stripe_price_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $data->name,
                    $data->description ?? '',
                    $data->num_courses,
                    $data->price_monthly,
                    $data->currency,
                    $data->is_active ?? 1,
                    $data->stripe_price_id ?? null
                ]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            error_log('POST subscription-plans error: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to save plan', 'details' => $e->getMessage(), 'data' => $data]);
        }
        break;
    case 'DELETE':
        // Delete plan (admin only)
        SessionAuth::requireAdmin();
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id']);
            exit;
        }
        $stmt = $db->prepare('DELETE FROM sprakapp_subscription_plans WHERE id=?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
