<?php
// get_notifications.php - AJAX handler for notification operations
include('connect.php');
include_once('notification_functions.php');
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    switch ($action) {
        case 'get_recent_notifications':
            // Get recent notifications (limit to 10 for dropdown)
            $stmt = $conn->prepare("
                SELECT id, title, message, type, is_read, created_at 
                FROM student_notifications 
                WHERE student_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'type' => $row['type'],
                    'is_read' => $row['is_read'],
                    'created_at' => $row['created_at']
                ];
            }
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications
            ]);
            break;
            
        case 'get_unread_count':
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM student_notifications 
                WHERE student_id = ? AND is_read = 0
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'count' => (int)$row['count']
            ]);
            break;
            
        case 'mark_as_read':
            $notification_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            
            if ($notification_id > 0) {
                $stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1 WHERE id = ? AND student_id = ?");
                $stmt->bind_param("ii", $notification_id, $user_id);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update notification']);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            break;
            
        case 'mark_all_as_read':
            $stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1 WHERE student_id = ? AND is_read = 0");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to mark all as read']);
            }
            $stmt->close();
            break;
            
        case 'get_all_notifications':
            // For the notifications.php page
            $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            
            $stmt = $conn->prepare("
                SELECT id, title, message, type, is_read, created_at 
                FROM student_notifications 
                WHERE student_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("iii", $user_id, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }
            $stmt->close();
            
            // Get total count for pagination
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_notifications WHERE student_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $total = $result->fetch_assoc()['total'];
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'total' => $total,
                'page' => $page,
                'total_pages' => ceil($total / $limit)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>