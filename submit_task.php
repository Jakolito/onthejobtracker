<?php
// submit_task.php - Fixed version to handle the submission description properly

include('connect.php');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = $_POST['task_id'] ?? null;
    $submission_description = $_POST['submission_text'] ?? null; // Changed from submission_description to submission_text to match form
    
    // Validate required fields
    if (empty($task_id) || empty($submission_description)) {
        $_SESSION['submission_error'] = "Task ID and description are required.";
        header("Location: studentTask.php");
        exit();
    }
    
    // Handle file upload
    $attachment_path = null;
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/submissions/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION);
        $file_name = 'submission_' . $task_id . '_' . $user_id . '_' . time() . '.' . $file_extension;
        $attachment_path = $upload_dir . $file_name;
        
        // Validate file type and size
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        if (!in_array(strtolower($file_extension), $allowed_types)) {
            $_SESSION['submission_error'] = "Invalid file type. Please upload PDF, DOC, DOCX, TXT, JPG, or PNG files only.";
            header("Location: studentTask.php");
            exit();
        }
        
        if ($_FILES['submission_file']['size'] > $max_size) {
            $_SESSION['submission_error'] = "File size too large. Maximum size is 10MB.";
            header("Location: studentTask.php");
            exit();
        }
        
        if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $attachment_path)) {
            $_SESSION['submission_error'] = "Failed to upload file. Please try again.";
            header("Location: studentTask.php");
            exit();
        }
    }
    
    try {
        // Check if submission already exists
        $check_stmt = $conn->prepare("SELECT submission_id FROM task_submissions WHERE task_id = ? AND student_id = ?");
        $check_stmt->bind_param("ii", $task_id, $user_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($existing) {
            // Update existing submission
            $update_query = "UPDATE task_submissions SET submission_description = ?, attachment = ?, status = 'Submitted', submitted_at = CURRENT_TIMESTAMP WHERE task_id = ? AND student_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ssii", $submission_description, $attachment_path, $task_id, $user_id);
        } else {
            // Insert new submission
            $insert_query = "INSERT INTO task_submissions (task_id, student_id, submission_description, attachment, status, submitted_at) VALUES (?, ?, ?, ?, 'Submitted', CURRENT_TIMESTAMP)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iiss", $task_id, $user_id, $submission_description, $attachment_path);
        }
        
        if ($stmt->execute()) {
            // Update task status to 'In Progress' if it's still 'Pending'
            $task_update_stmt = $conn->prepare("UPDATE tasks SET status = 'In Progress', updated_at = CURRENT_TIMESTAMP WHERE task_id = ? AND status = 'Pending'");
            $task_update_stmt->bind_param("i", $task_id);
            $task_update_stmt->execute();
            $task_update_stmt->close();
            
            $_SESSION['submission_success'] = $existing ? "Task submission updated successfully!" : "Task submitted successfully!";
        } else {
            throw new Exception("Database error: " . $stmt->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Submission error: " . $e->getMessage());
        $_SESSION['submission_error'] = "An error occurred while submitting the task. Please try again.";
        
        // Clean up uploaded file if there was an error
        if ($attachment_path && file_exists($attachment_path)) {
            unlink($attachment_path);
        }
    }
    
    header("Location: studentTask.php");
    exit();
}

// If not POST request, redirect back
header("Location: studentTask.php");
exit();
?>