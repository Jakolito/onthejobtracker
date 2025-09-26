<?php
include('connect.php');
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user has active deployment
function checkDeploymentStatus($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            SELECT deployment_id, ojt_status, start_date, end_date 
            FROM student_deployments 
            WHERE student_id = ? 
            AND ojt_status = 'Active' 
            AND start_date <= CURDATE() 
            AND (end_date >= CURDATE() OR end_date IS NULL)
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

// Check deployment status
$deployment = checkDeploymentStatus($conn, $user_id);
if (!$deployment) {
    echo json_encode(['success' => false, 'message' => 'No active deployment found. You must be deployed to record attendance.']);
    exit();
}

// Process the request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $today = date('Y-m-d');
    $current_time = date('H:i:s');
    
    try {
        if ($action === 'time_in') {
            // Check if already timed in today
            $check_stmt = $conn->prepare("SELECT attendance_id FROM student_attendance WHERE student_id = ? AND date = ?");
            $check_stmt->bind_param("is", $user_id, $today);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'You have already timed in today.']);
                exit();
            }
            
            // Insert new attendance record
            $stmt = $conn->prepare("INSERT INTO student_attendance (student_id, deployment_id, date, time_in, status) VALUES (?, ?, ?, ?, 'Present')");
            $stmt->bind_param("iiss", $user_id, $deployment['deployment_id'], $today, $current_time);
            
            if ($stmt->execute()) {
                $_SESSION['attendance_success'] = 'Time in recorded successfully at ' . date('g:i A');
                echo json_encode(['success' => true, 'message' => 'Time in recorded successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to record time in']);
            }
            
        } elseif ($action === 'time_out') {
            // Check if timed in today
            $check_stmt = $conn->prepare("SELECT attendance_id, time_in FROM student_attendance WHERE student_id = ? AND date = ? AND time_in IS NOT NULL");
            $check_stmt->bind_param("is", $user_id, $today);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'You must time in first before timing out.']);
                exit();
            }
            
            $attendance = $result->fetch_assoc();
            
            // Check if already timed out
            $timeout_check = $conn->prepare("SELECT time_out FROM student_attendance WHERE attendance_id = ?");
            $timeout_check->bind_param("i", $attendance['attendance_id']);
            $timeout_check->execute();
            $timeout_result = $timeout_check->get_result();
            $timeout_data = $timeout_result->fetch_assoc();
            
            if ($timeout_data['time_out']) {
                echo json_encode(['success' => false, 'message' => 'You have already timed out today.']);
                exit();
            }
            
            // Calculate total hours
            $time_in = new DateTime($attendance['time_in']);
            $time_out = new DateTime($current_time);
            $interval = $time_in->diff($time_out);
            $total_hours = $interval->h + ($interval->i / 60);
            
            // Update attendance record with time out and total hours
            $stmt = $conn->prepare("UPDATE student_attendance SET time_out = ?, total_hours = ? WHERE attendance_id = ?");
            $stmt->bind_param("sdi", $current_time, $total_hours, $attendance['attendance_id']);
            
            if ($stmt->execute()) {
                // Update completed hours in deployment
                $update_deployment = $conn->prepare("UPDATE student_deployments SET completed_hours = completed_hours + ? WHERE deployment_id = ?");
                $update_deployment->bind_param("di", $total_hours, $deployment['deployment_id']);
                $update_deployment->execute();
                
                $_SESSION['attendance_success'] = 'Time out recorded successfully at ' . date('g:i A') . '. Total hours: ' . number_format($total_hours, 2);
                echo json_encode(['success' => true, 'message' => 'Time out recorded successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to record time out']);
            }
            
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>