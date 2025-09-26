<?php
// notification_functions.php - Include this file where needed

/**
 * Create a notification for a student
 */
function createNotification($conn, $student_id, $title, $message, $type = 'general', $document_id = null) {
    $stmt = $conn->prepare("INSERT INTO student_notifications (student_id, title, message, type, document_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isssi", $student_id, $title, $message, $type, $document_id);
    return $stmt->execute();
}

/**
 * Get unread notification count for a student
 */
function getUnreadNotificationCount($conn, $student_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_notifications WHERE student_id = ? AND is_read = 0");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['count'];
}


function createEvaluationNotification($conn, $student_id, $supervisor_name, $overall_rating, $avg_rating) {
    $title = "New Evaluation Received";
    
    // Create message based on performance level
    if ($overall_rating >= 4) {
        $message = "Great work! You received an evaluation from $supervisor_name with an overall rating of $overall_rating/5 (Average: " . number_format($avg_rating, 1) . "/5). Keep up the excellent performance!";
    } elseif ($overall_rating == 3) {
        $message = "You received an evaluation from $supervisor_name with an overall rating of $overall_rating/5 (Average: " . number_format($avg_rating, 1) . "/5). Good work with room for improvement.";
    } else {
        $message = "You received an evaluation from $supervisor_name with an overall rating of $overall_rating/5 (Average: " . number_format($avg_rating, 1) . "/5). Please review the feedback and work on improvement areas.";
    }
    
$type = 'general'; // Instead of 'evaluation_received'    
    return createNotification($conn, $student_id, $title, $message, $type);
}
/**
 * Get notifications for a student (with pagination)
 */
function getStudentNotifications($conn, $student_id, $limit = 10, $offset = 0, $unread_only = false) {
    $where_clause = $unread_only ? "AND is_read = 0" : "";
    $stmt = $conn->prepare("
        SELECT n.*, sd.original_filename, dr.name as document_name 
        FROM student_notifications n
        LEFT JOIN student_documents sd ON n.document_id = sd.id
        LEFT JOIN document_requirements dr ON sd.document_id = dr.id
        WHERE n.student_id = ? $where_clause
        ORDER BY n.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iii", $student_id, $limit, $offset);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($conn, $notification_id, $student_id) {
    $stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND student_id = ?");
    $stmt->bind_param("ii", $notification_id, $student_id);
    return $stmt->execute();
}

/**
 * Mark all notifications as read for a student
 */
function markAllNotificationsAsRead($conn, $student_id) {
    $stmt = $conn->prepare("UPDATE student_notifications SET is_read = 1, read_at = NOW() WHERE student_id = ? AND is_read = 0");
    $stmt->bind_param("i", $student_id);
    return $stmt->execute();
}


function createDeploymentNotification($conn, $student_id, $company_name, $supervisor_name, $start_date, $end_date, $position) {
    $title = "Deployment Confirmed - Welcome to your OJT!";
    $message = "Congratulations! You have been successfully deployed to $company_name as $position. Your supervisor is $supervisor_name. Your OJT period runs from " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date)) . ". Best of luck with your internship journey!";
    $type = 'deployment_confirmed';
    
    return createNotification($conn, $student_id, $title, $message, $type);
}
/**
 * Delete old notifications (older than 30 days)
 */
function cleanupOldNotifications($conn) {
    $stmt = $conn->prepare("DELETE FROM student_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    return $stmt->execute();
}

/**
 * Create document status notification
 */
function createDocumentStatusNotification($conn, $student_id, $document_id, $status, $document_name, $feedback = '') {
    if ($status === 'approved') {
        $title = "Document Approved";
        $message = "Your document '$document_name' has been approved.";
        $type = 'document_approved';
        
        if (!empty($feedback)) {
            $message .= " Feedback: $feedback";
        }
    } elseif ($status === 'rejected') {
        $title = "Document Rejected";
        $message = "Your document '$document_name' has been rejected.";
        $type = 'document_rejected';
        
        if (!empty($feedback)) {
            $message .= " Feedback: $feedback";
        } else {
            $message .= " Please check the feedback and resubmit.";
        }
    } else {
        return false; // Don't create notifications for 'pending' status
    }
    
    return createNotification($conn, $student_id, $title, $message, $type, $document_id);
}
?>