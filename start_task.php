<?php
include('connect.php');
session_start();

// ADD CACHE CONTROL HEADERS
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the request
error_log("START TASK REQUEST - User: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("POST data: " . print_r($_POST, true));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: User not logged in");
    $_SESSION['submission_error'] = "You must be logged in to start a task.";
    header("Location: login.php");
    exit();
}

// Check if task_id is provided
if (!isset($_POST['task_id'])) {
    error_log("ERROR: Task ID missing from POST");
    $_SESSION['submission_error'] = "Invalid request. Task ID is missing.";
    header("Location: studentTask.php");
    exit();
}

$task_id = intval($_POST['task_id']);
$user_id = $_SESSION['user_id'];

error_log("Processing start task - Task ID: $task_id, User ID: $user_id");

try {
    // Verify database connection
    if (!$conn || $conn->connect_error) {
        throw new Exception("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    }
    
    // Verify task exists and belongs to this student
    $check_stmt = $conn->prepare("
        SELECT task_id, status, student_id 
        FROM tasks 
        WHERE task_id = ? AND student_id = ?
    ");
    
    if (!$check_stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    
    $check_stmt->bind_param("ii", $task_id, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        error_log("ERROR: Task not found - Task ID: $task_id, User ID: $user_id");
        $_SESSION['submission_error'] = "Task not found or you don't have permission to start this task.";
        $check_stmt->close();
        header("Location: studentTask.php");
        exit();
    }
    
    $task = $result->fetch_assoc();
    error_log("Task found - Current status: " . $task['status']);
    $check_stmt->close();
    
    // Check if task is in Pending status
    if ($task['status'] !== 'Pending') {
        error_log("ERROR: Task status is not Pending - Current status: " . $task['status']);
        $_SESSION['submission_error'] = "This task is already " . strtolower($task['status']) . " and cannot be started again.";
        header("Location: studentTask.php");
        exit();
    }
    
    // Update task status to "In Progress"
    $update_stmt = $conn->prepare("
        UPDATE tasks 
        SET status = 'In Progress', 
            updated_at = NOW() 
        WHERE task_id = ? AND student_id = ?
    ");
    
    if (!$update_stmt) {
        throw new Exception("Prepare update statement failed: " . $conn->error);
    }
    
    $update_stmt->bind_param("ii", $task_id, $user_id);
    
    if ($update_stmt->execute()) {
        $affected_rows = $update_stmt->affected_rows;
        error_log("Task status updated successfully - Affected rows: $affected_rows");
        
        // Get supervisor_id for notification
        $supervisor_stmt = $conn->prepare("SELECT supervisor_id, task_title FROM tasks WHERE task_id = ?");
        
        if ($supervisor_stmt) {
            $supervisor_stmt->bind_param("i", $task_id);
            $supervisor_stmt->execute();
            $supervisor_result = $supervisor_stmt->get_result();
            $supervisor_data = $supervisor_result->fetch_assoc();
            $supervisor_stmt->close();
            
            // Create notification for supervisor (if notification system exists)
            if (!empty($supervisor_data['supervisor_id'])) {
                error_log("Attempting to create notification for supervisor: " . $supervisor_data['supervisor_id']);
                
                // Check if notification_functions.php exists
                if (file_exists('notification_functions.php')) {
                    include_once('notification_functions.php');
                    
                    if (function_exists('createNotification')) {
                        try {
                            createNotification(
                                $conn,
                                $supervisor_data['supervisor_id'],
                                'supervisor',
                                'Task Started',
                                'A student has started working on task: ' . $supervisor_data['task_title'],
                                'task',
                                $task_id
                            );
                            error_log("Notification created successfully");
                        } catch (Exception $notif_error) {
                            error_log("Notification creation failed: " . $notif_error->getMessage());
                            // Continue anyway - notification failure shouldn't stop task start
                        }
                    } else {
                        error_log("WARNING: createNotification function not found");
                    }
                } else {
                    error_log("WARNING: notification_functions.php not found");
                }
            }
        }
        
        $_SESSION['submission_success'] = "Task started successfully! You can now work on this task and submit when ready.";
        error_log("SUCCESS: Task started - redirecting to studentTask.php");
    } else {
        error_log("ERROR: Execute update failed - Error: " . $update_stmt->error);
        $_SESSION['submission_error'] = "Failed to start task. Database error: " . $update_stmt->error;
    }
    
    $update_stmt->close();
    
} catch (Exception $e) {
    error_log("EXCEPTION: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $_SESSION['submission_error'] = "An error occurred: " . $e->getMessage();
}

// Redirect back to tasks page
error_log("Redirecting to studentTask.php");
header("Location: studentTask.php");
exit();
?>