<?php
include('connect.php');
session_start();

// ADD CACHE CONTROL HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_id'])) {
    $task_id = intval($_POST['task_id']);
    
    try {
        // Verify that the task belongs to the current user and is in Pending status
        $verify_stmt = $conn->prepare("SELECT task_id, status, task_title FROM tasks WHERE task_id = ? AND student_id = ? AND status = 'Pending'");
        $verify_stmt->bind_param("ii", $task_id, $user_id);
        $verify_stmt->execute();
        $result = $verify_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $task = $result->fetch_assoc();
            
            // Update task status to In Progress
            $update_stmt = $conn->prepare("UPDATE tasks SET status = 'In Progress', updated_at = CURRENT_TIMESTAMP WHERE task_id = ? AND student_id = ?");
            $update_stmt->bind_param("ii", $task_id, $user_id);
            
            if ($update_stmt->execute()) {
                // Get student info for notification
                $student_stmt = $conn->prepare("SELECT first_name, last_name, student_id FROM students WHERE id = ?");
                $student_stmt->bind_param("i", $user_id);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                $student = $student_result->fetch_assoc();
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $student_id_num = $student['student_id'];
                $student_stmt->close();
                
                // Insert notification for supervisor (if notifications table exists)
                try {
                    $notification_stmt = $conn->prepare("
                        INSERT INTO notifications (user_type, user_id, title, message, type, related_id, created_at) 
                        SELECT 'supervisor', t.supervisor_id, 'Task Started', 
                               CONCAT('Student ', ?, ' (', ?, ') has started working on task: ', t.task_title), 
                               'task_started', t.task_id, NOW()
                        FROM tasks t WHERE t.task_id = ?
                    ");
                    $notification_stmt->bind_param("ssi", $student_name, $student_id_num, $task_id);
                    $notification_stmt->execute();
                    $notification_stmt->close();
                } catch (Exception $e) {
                    // Notification failed, but don't stop the process
                    error_log("Notification error: " . $e->getMessage());
                }
                
                $_SESSION['submission_success'] = "Task started successfully! You can now work on this task and submit it when ready.";
            } else {
                $_SESSION['submission_error'] = "Failed to start task. Please try again.";
            }
            $update_stmt->close();
        } else {
            $_SESSION['submission_error'] = "Task not found or cannot be started. Task may already be in progress or completed.";
        }
        $verify_stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['submission_error'] = "Error starting task: " . $e->getMessage();
        error_log("Start task error: " . $e->getMessage());
    }
} else {
    $_SESSION['submission_error'] = "Invalid request. Please try again.";
}

// Redirect back to tasks page
header("Location: studentTask.php");
exit();
?>