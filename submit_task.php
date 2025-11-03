<?php
// ============================================================================
// FIXED: Only ONE student needs to submit for collaborative tasks
// Any team member can submit/resubmit on behalf of the entire team
// ============================================================================

include('connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    $submission_text = isset($_POST['submission_text']) ? trim($_POST['submission_text']) : '';
    $is_resubmission = isset($_POST['is_resubmission']) ? intval($_POST['is_resubmission']) : 0;
    
    if (empty($task_id) || empty($submission_text)) {
        $_SESSION['submission_error'] = "Please fill in all required fields.";
        header("Location: studentTask.php");
        exit();
    }
    
    if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['submission_error'] = "Please attach a file to your submission.";
        header("Location: studentTask.php");
        exit();
    }
    
    try {
        mysqli_begin_transaction($conn);
        
        // Find ALL collaborative tasks (same group)
        $collab_check = $conn->prepare("
            SELECT 
                t.task_id,
                t.student_id,
                t.task_title,
                CONCAT(s.first_name, ' ', s.last_name) as student_name
            FROM tasks t1
            JOIN tasks t ON t1.task_title = t.task_title 
                AND t1.supervisor_id = t.supervisor_id 
                AND t1.due_date = t.due_date
                AND t1.created_at = t.created_at
            JOIN students s ON t.student_id = s.id
            WHERE t1.task_id = ?
        ");
        $collab_check->bind_param("i", $task_id);
        $collab_check->execute();
        $collab_result = $collab_check->get_result();
        
        $collaborative_tasks = [];
        $current_student_task_id = null;
        
        while ($row = $collab_result->fetch_assoc()) {
            $collaborative_tasks[] = $row;
            if ($row['student_id'] == $user_id) {
                $current_student_task_id = $row['task_id'];
            }
        }
        $collab_check->close();
        
        $is_collaborative = (count($collaborative_tasks) > 1);
        
        if ($is_collaborative && !$current_student_task_id) {
            throw new Exception("Unable to find your task assignment.");
        }
        
        $target_task_id = $current_student_task_id ?? $task_id;
        
        // Verify task exists and belongs to user
        $verify_stmt = $conn->prepare("
            SELECT task_id, status, task_title 
            FROM tasks 
            WHERE task_id = ? AND student_id = ?
        ");
        $verify_stmt->bind_param("ii", $target_task_id, $user_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 0) {
            throw new Exception("Task not found or doesn't belong to you.");
        }
        
        $task_data = $verify_result->fetch_assoc();
        $verify_stmt->close();
        
        // ✅ REMOVED: No longer checking if team member already submitted
        // Any team member can submit/resubmit at any time
        
        // Handle File Upload
        $file = $_FILES['submission_file'];
        $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        $max_file_size = 10 * 1024 * 1024;
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception("Invalid file type. Allowed: PDF, DOC, DOCX, TXT, JPG, PNG");
        }
        
        if ($file['size'] > $max_file_size) {
            throw new Exception("File size exceeds 10MB limit.");
        }
        
        $upload_dir = 'uploads/task_submissions/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
        $file_path = $upload_dir . $file_name;
        
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception("Failed to upload file.");
        }
        
        // ============================================================
        // ✅ FIXED: Simplified submission logic
        // For collaborative tasks: ANY student can submit for the ENTIRE team
        // ============================================================
        if ($is_collaborative) {
            // Check if ANY team member has already submitted
            $check_existing = $conn->prepare("
                SELECT ts.submission_id, ts.attachment
                FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.task_id
                WHERE t.task_title = (SELECT task_title FROM tasks WHERE task_id = ?)
                    AND t.supervisor_id = (SELECT supervisor_id FROM tasks WHERE task_id = ?)
                    AND t.due_date = (SELECT due_date FROM tasks WHERE task_id = ?)
                    AND t.created_at = (SELECT created_at FROM tasks WHERE task_id = ?)
                LIMIT 1
            ");
            $check_existing->bind_param("iiii", $target_task_id, $target_task_id, $target_task_id, $target_task_id);
            $check_existing->execute();
            $existing_result = $check_existing->get_result();
            
            $has_existing_submission = ($existing_result->num_rows > 0);
            
            if ($has_existing_submission) {
                // Get old attachment to delete
                $old_data = $existing_result->fetch_assoc();
                if (!empty($old_data['attachment']) && file_exists($old_data['attachment'])) {
                    unlink($old_data['attachment']);
                }
                
                // UPDATE existing submissions for ALL team members
                $update_all = $conn->prepare("
                    UPDATE task_submissions ts
                    JOIN tasks t ON ts.task_id = t.task_id
                    SET ts.submission_description = ?,
                        ts.attachment = ?,
                        ts.status = 'Submitted',
                        ts.submitted_at = CURRENT_TIMESTAMP,
                        ts.feedback = NULL,
                        ts.reviewed_at = NULL
                    WHERE t.task_title = (SELECT task_title FROM tasks WHERE task_id = ?)
                        AND t.supervisor_id = (SELECT supervisor_id FROM tasks WHERE task_id = ?)
                        AND t.due_date = (SELECT due_date FROM tasks WHERE task_id = ?)
                        AND t.created_at = (SELECT created_at FROM tasks WHERE task_id = ?)
                ");
                $update_all->bind_param("ssiiii", $submission_text, $file_path, $target_task_id, $target_task_id, $target_task_id, $target_task_id);
                
                if (!$update_all->execute()) {
                    throw new Exception("Failed to update team submission.");
                }
                $update_all->close();
                
                $success_message = "Team submission updated successfully! Waiting for supervisor review.";
                
            } else {
                // INSERT new submission for ALL team members
                foreach ($collaborative_tasks as $collab_task) {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO task_submissions 
                        (task_id, student_id, submission_description, attachment, status, submitted_at) 
                        VALUES (?, ?, ?, ?, 'Submitted', CURRENT_TIMESTAMP)
                    ");
                    $insert_stmt->bind_param("iiss", $collab_task['task_id'], $collab_task['student_id'], $submission_text, $file_path);
                    
                    if (!$insert_stmt->execute()) {
                        throw new Exception("Failed to submit task for all team members.");
                    }
                    $insert_stmt->close();
                }
                
                $success_message = "Task submitted for your team! Waiting for supervisor review.";
            }
            $check_existing->close();
            
        } else {
            // Individual task submission (unchanged)
            if ($is_resubmission) {
                $check_submission = $conn->prepare("
                    SELECT submission_id, attachment 
                    FROM task_submissions 
                    WHERE task_id = ? AND student_id = ?
                ");
                $check_submission->bind_param("ii", $target_task_id, $user_id);
                $check_submission->execute();
                $submission_result = $check_submission->get_result();
                
                if ($submission_result->num_rows > 0) {
                    $old_submission = $submission_result->fetch_assoc();
                    
                    if (!empty($old_submission['attachment']) && file_exists($old_submission['attachment'])) {
                        unlink($old_submission['attachment']);
                    }
                    
                    $update_stmt = $conn->prepare("
                        UPDATE task_submissions 
                        SET submission_description = ?,
                            attachment = ?,
                            status = 'Submitted',
                            submitted_at = CURRENT_TIMESTAMP,
                            feedback = NULL,
                            reviewed_at = NULL
                        WHERE task_id = ? AND student_id = ?
                    ");
                    $update_stmt->bind_param("ssii", $submission_text, $file_path, $target_task_id, $user_id);
                    
                    if (!$update_stmt->execute()) {
                        throw new Exception("Failed to update submission.");
                    }
                    $update_stmt->close();
                    
                    $success_message = "Task resubmitted successfully! Waiting for supervisor review.";
                } else {
                    throw new Exception("No previous submission found to update.");
                }
                $check_submission->close();
                
            } else {
                $check_dup = $conn->prepare("
                    SELECT submission_id 
                    FROM task_submissions 
                    WHERE task_id = ? AND student_id = ?
                ");
                $check_dup->bind_param("ii", $target_task_id, $user_id);
                $check_dup->execute();
                $dup_result = $check_dup->get_result();
                
                if ($dup_result->num_rows > 0) {
                    throw new Exception("You have already submitted this task.");
                }
                $check_dup->close();
                
                $insert_stmt = $conn->prepare("
                    INSERT INTO task_submissions 
                    (task_id, student_id, submission_description, attachment, status, submitted_at) 
                    VALUES (?, ?, ?, ?, 'Submitted', CURRENT_TIMESTAMP)
                ");
                $insert_stmt->bind_param("iiss", $target_task_id, $user_id, $submission_text, $file_path);
                
                if (!$insert_stmt->execute()) {
                    throw new Exception("Failed to submit task.");
                }
                $insert_stmt->close();
                
                $success_message = "Task submitted successfully! Waiting for supervisor review.";
            }
        }
        
        mysqli_commit($conn);
        $_SESSION['submission_success'] = $success_message;
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        
        if (isset($file_path) && file_exists($file_path)) {
            unlink($file_path);
        }
        
        error_log("Submission error: " . $e->getMessage());
        $_SESSION['submission_error'] = "Error: " . $e->getMessage();
    }
    
} else {
    $_SESSION['submission_error'] = "Invalid request method.";
}

header("Location: studentTask.php");
exit();
?>