<?php
// message_functions.php - Helper functions for messaging system

/**
 * Get unread message count for a specific user
 */
function getUnreadMessageCount($conn, $user_id, $user_type = 'student') {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM messages 
            WHERE recipient_id = ? AND recipient_type = ? AND is_read = 0
        ");
        $stmt->bind_param("is", $user_id, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        return $data['count'];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Mark messages as read
 */
function markMessagesAsRead($conn, $sender_id, $sender_type, $recipient_id, $recipient_type = 'student') {
    try {
        $stmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE sender_id = ? AND sender_type = ? AND recipient_id = ? AND recipient_type = ?
        ");
        $stmt->bind_param("isis", $sender_id, $sender_type, $recipient_id, $recipient_type);
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Send a message
 */
function sendMessage($conn, $sender_id, $sender_type, $recipient_id, $recipient_type, $message, $message_type = 'text') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, message, message_type, sent_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isisss", $sender_id, $sender_type, $recipient_id, $recipient_type, $message, $message_type);
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        return false;
    } catch (Exception $e) {
        error_log("Error sending message: " . $e->getMessage());
        return false;
    }
}

/**
 * Get conversation messages between two users
 */
function getConversationMessages($conn, $user1_id, $user1_type, $user2_id, $user2_type, $limit = 50) {
    try {
        $stmt = $conn->prepare("
            SELECT m.*, 
                   CASE 
                       WHEN m.sender_type = 'student' THEN CONCAT(s.first_name, ' ', s.last_name)
                       WHEN m.sender_type = 'adviser' THEN aa.name
                       WHEN m.sender_type = 'supervisor' THEN cs.full_name
                   END as sender_name,
                   CASE 
                       WHEN m.sender_type = 'student' THEN s.profile_picture
                       ELSE NULL
                   END as sender_avatar
            FROM messages m
            LEFT JOIN students s ON m.sender_id = s.id AND m.sender_type = 'student'
            LEFT JOIN academic_adviser aa ON m.sender_id = aa.id AND m.sender_type = 'adviser'
            LEFT JOIN company_supervisors cs ON m.sender_id = cs.supervisor_id AND m.sender_type = 'supervisor'
            WHERE ((m.sender_id = ? AND m.sender_type = ? AND m.recipient_id = ? AND m.recipient_type = ?)
                   OR (m.sender_id = ? AND m.sender_type = ? AND m.recipient_id = ? AND m.recipient_type = ?))
                  AND m.is_deleted_by_sender = 0 
                  AND m.is_deleted_by_recipient = 0
            ORDER BY m.sent_at ASC
            LIMIT ?
        ");
        
        $stmt->bind_param("isisisisi", 
            $user1_id, $user1_type, $user2_id, $user2_type,
            $user2_id, $user2_type, $user1_id, $user1_type,
            $limit
        );
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => $row['id'],
                'message' => $row['message'],
                'sent_at' => $row['sent_at'],
                'sender_name' => $row['sender_name'],
                'sender_avatar' => $row['sender_avatar'],
                'sender_type' => $row['sender_type'],
                'message_type' => $row['message_type'],
                'is_own' => ($row['sender_type'] === $user1_type && $row['sender_id'] == $user1_id),
                'is_read' => $row['is_read']
            ];
        }
        
        return $messages;
    } catch (Exception $e) {
        error_log("Error getting conversation messages: " . $e->getMessage());
        return [];
    }
}

/**
 * Get available contacts for a student
 */
function getAvailableContacts($conn, $student_id) {
    $contacts = [];
    
    // Always add academic advisers
    try {
        $adviser_stmt = $conn->prepare("SELECT id, name, email FROM academic_adviser ORDER BY name ASC");
        $adviser_stmt->execute();
        $adviser_result = $adviser_stmt->get_result();
        
        while ($row = $adviser_result->fetch_assoc()) {
            $unread_count = getUnreadMessageCount($conn, $student_id, 'student');
            $contacts[] = [
                'id' => 'adviser_' . $row['id'],
                'name' => $row['name'],
                'role' => 'Academic Adviser',
                'email' => $row['email'],
                'type' => 'adviser',
                'available' => true,
                'unread_count' => $unread_count
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting advisers: " . $e->getMessage());
    }
    
    // Add company supervisor if student is deployed
    try {
        $deploy_stmt = $conn->prepare("
            SELECT sd.*, cs.full_name, cs.email, cs.position, cs.company_name 
            FROM student_deployments sd 
            LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id 
            WHERE sd.student_id = ? AND sd.status = 'Active'
        ");
        $deploy_stmt->bind_param("i", $student_id);
        $deploy_stmt->execute();
        $deploy_result = $deploy_stmt->get_result();
        
        if ($deploy_result->num_rows > 0) {
            $deployment = $deploy_result->fetch_assoc();
            if ($deployment['supervisor_id']) {
                $unread_count = getUnreadMessageCount($conn, $student_id, 'student');
                $contacts[] = [
                    'id' => 'supervisor_' . $deployment['supervisor_id'],
                    'name' => $deployment['full_name'],
                    'role' => $deployment['position'] . ' at ' . $deployment['company_name'],
                    'email' => $deployment['email'],
                    'type' => 'supervisor',
                    'available' => true,
                    'unread_count' => $unread_count
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error getting supervisor: " . $e->getMessage());
    }
    
    return $contacts;
}

/**
 * Check if student can message a specific contact
 */
function canMessageContact($conn, $student_id, $contact_type, $contact_id) {
    // Students can always message academic advisers
    if ($contact_type === 'adviser') {
        return true;
    }
    
    // Students can only message supervisors if they are deployed
    if ($contact_type === 'supervisor') {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM student_deployments 
                WHERE student_id = ? AND supervisor_id = ? AND status = 'Active'
            ");
            $stmt->bind_param("ii", $student_id, $contact_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            return $data['count'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Sanitize message content
 */
function sanitizeMessage($message) {
    // Remove any potentially harmful content
    $message = trim($message);
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    
    // Preserve line breaks
    $message = nl2br($message);
    
    // Remove excessive whitespace
    $message = preg_replace('/\s+/', ' ', $message);
    
    // Limit message length (optional - adjust as needed)
    if (strlen($message) > 1000) {
        $message = substr($message, 0, 1000) . '...';
    }
    
    return $message;
}

/**
 * Get recent conversations for a user
 */
function getRecentConversations($conn, $user_id, $user_type, $limit = 10) {
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                CASE 
                    WHEN sender_id = ? AND sender_type = ? THEN recipient_id 
                    ELSE sender_id 
                END as contact_id,
                CASE 
                    WHEN sender_id = ? AND sender_type = ? THEN recipient_type 
                    ELSE sender_type 
                END as contact_type,
                MAX(sent_at) as last_message_time,
                (SELECT message FROM messages m2 
                 WHERE ((m2.sender_id = ? AND m2.sender_type = ? AND m2.recipient_id = contact_id AND m2.recipient_type = contact_type)
                        OR (m2.sender_id = contact_id AND m2.sender_type = contact_type AND m2.recipient_id = ? AND m2.recipient_type = ?))
                 ORDER BY m2.sent_at DESC LIMIT 1) as last_message
            FROM messages m
            WHERE (sender_id = ? AND sender_type = ?) OR (recipient_id = ? AND recipient_type = ?)
            GROUP BY contact_id, contact_type
            ORDER BY last_message_time DESC
            LIMIT ?
        ");
        
        $stmt->bind_param("isisisississi", 
            $user_id, $user_type, $user_id, $user_type,
            $user_id, $user_type, $user_id, $user_type,
            $user_id, $user_type, $user_id, $user_type,
            $limit
        );
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            $conversations[] = [
                'contact_id' => $row['contact_id'],
                'contact_type' => $row['contact_type'],
                'last_message' => $row['last_message'],
                'last_message_time' => $row['last_message_time']
            ];
        }
        
        return $conversations;
    } catch (Exception $e) {
        error_log("Error getting recent conversations: " . $e->getMessage());
        return [];
    }
}

/**
 * Delete a message (soft delete)
 */
function deleteMessage($conn, $message_id, $user_id, $user_type) {
    try {
        // Check if user is sender or recipient
        $stmt = $conn->prepare("
            SELECT sender_id, sender_type, recipient_id, recipient_type 
            FROM messages 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return false;
        }
        
        $message = $result->fetch_assoc();
        
        // Determine which field to update
        if ($message['sender_id'] == $user_id && $message['sender_type'] === $user_type) {
            $update_stmt = $conn->prepare("UPDATE messages SET is_deleted_by_sender = 1 WHERE id = ?");
        } elseif ($message['recipient_id'] == $user_id && $message['recipient_type'] === $user_type) {
            $update_stmt = $conn->prepare("UPDATE messages SET is_deleted_by_recipient = 1 WHERE id = ?");
        } else {
            return false; // User is neither sender nor recipient
        }
        
        $update_stmt->bind_param("i", $message_id);
        return $update_stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error deleting message: " . $e->getMessage());
        return false;
    }
}

/**
 * Format message time for display
 */
function formatMessageTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

?>