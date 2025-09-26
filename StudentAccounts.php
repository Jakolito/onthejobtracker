<?php
include('connect.php');
session_start();

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}
include_once('notification_functions.php'); // Include the notification functions

// Get adviser information
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'];
$adviser_email = $_SESSION['email'];

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';
$section_filter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : ''; // Added missing variable
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Pagination settings
$records_per_page = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

try {
    // Get total counts for summary cards - INCLUDE ALL students
$total_students_query = "SELECT COUNT(*) as total FROM students";
$total_students_result = mysqli_query($conn, $total_students_query);
$total_students = mysqli_fetch_assoc($total_students_result)['total'];

// Get blocked students count
$blocked_students_query = "SELECT COUNT(*) as total FROM students WHERE (status = 'Blocked' OR login_attempts >= 3)";
$blocked_students_result = mysqli_query($conn, $blocked_students_query);
$blocked_students = mysqli_fetch_assoc($blocked_students_result)['total'];

// Get unverified students count
$unverified_students_query = "SELECT COUNT(*) as total FROM students WHERE verified = 0";
$unverified_students_result = mysqli_query($conn, $unverified_students_query);
$unverified_students = mysqli_fetch_assoc($unverified_students_result)['total'];

// Get deployed students count - NEW CARD
$deployed_students_query = "SELECT COUNT(*) as total FROM students WHERE id IN (SELECT student_id FROM student_deployments)";
$deployed_students_result = mysqli_query($conn, $deployed_students_query);
$deployed_students = mysqli_fetch_assoc($deployed_students_result)['total'];

    // Get unique departments for filter dropdown - INCLUDE ALL students
    $departments_query = "SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' ORDER BY department";
    $departments_result = mysqli_query($conn, $departments_query);

    // Build WHERE conditions - REMOVE the ready_for_deployment filter
    $where_conditions = array();
    
   if (!empty($search)) {
    $where_conditions[] = "(s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%' OR s.email LIKE '%$search%' OR s.student_id LIKE '%$search%')";
}

if (!empty($department_filter)) {
    $where_conditions[] = "s.department = '$department_filter'";
}

if (!empty($section_filter)) {
    $where_conditions[] = "s.section = '$section_filter'";
}

if (!empty($status_filter)) {
    if ($status_filter === 'verified') {
        $where_conditions[] = "s.verified = 1 AND s.ready_for_deployment = 0 AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE student_id IS NOT NULL)";
    } elseif ($status_filter === 'unverified') {
        $where_conditions[] = "s.verified = 0";
    } elseif ($status_filter === 'blocked') {
        $where_conditions[] = "(s.status = 'Blocked' OR s.login_attempts >= 3)";
    } elseif ($status_filter === 'deployed') {
        $where_conditions[] = "s.id IN (SELECT student_id FROM student_deployments WHERE student_id IS NOT NULL)";
    } elseif ($status_filter === 'ready_for_deployment') {
        $where_conditions[] = "s.ready_for_deployment = 1 AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE student_id IS NOT NULL)";
    } else {
        $where_conditions[] = "s.status = '$status_filter'";
    }
}
// Get unique sections for filter dropdown
$sections_query = "SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section != '' ORDER BY section";
$sections_result = mysqli_query($conn, $sections_query);
    // Build WHERE clause
$where_clause = count($where_conditions) > 0 ? implode(' AND ', $where_conditions) : "1=1";

    // Count total records for pagination - INCLUDE ALL students
    $count_query = "SELECT COUNT(*) as total FROM students s WHERE $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

 $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];
    // Get students with pagination - INCLUDE ALL students
// NEW QUERY:
$students_query = "
    SELECT 
        s.id,
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.email,
        s.department,
        s.program,
        s.year_level,
        s.section,
        s.status,
        s.verified,
        s.login_attempts,
        s.created_at,
        s.last_login,
        s.ready_for_deployment,
        sd.deployment_id,
        sd.company_name,
        sd.position as deployment_position,
        sd.start_date as deployment_start,
        sd.end_date as deployment_end,
        sd.status as deployment_status
    FROM students s
    LEFT JOIN student_deployments sd ON s.id = sd.student_id
    WHERE $where_clause
    ORDER BY 
        CASE 
            WHEN sd.student_id IS NOT NULL THEN 0 
            WHEN s.ready_for_deployment = 1 THEN 1 
            ELSE 2 
        END, 
        s.created_at DESC
    LIMIT $records_per_page OFFSET $offset
";
    $students_result = mysqli_query($conn, $students_query);

} catch (Exception $e) {
    $error_message = "Error fetching student data: " . $e->getMessage();
}

// Handle AJAX requests for student actions and document management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'verify':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET verified = 1 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student verified successfully' : 'Failed to verify student']);
                break;
                
            case 'block':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Blocked', login_attempts = 3 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student blocked successfully' : 'Failed to block student']);
                break;
                
            case 'unblock':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Active', login_attempts = 0 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student unblocked successfully' : 'Failed to unblock student']);
                break;
                
            case 'activate':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET status = 'Active', login_attempts = 0 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                echo json_encode(['success' => $success, 'message' => $success ? 'Student activated successfully' : 'Failed to activate student']);
                break;

            case 'ready_for_deployment':
                $student_id = (int)$_POST['student_id'];
                
                // First, verify that ALL required documents are submitted and approved
                $validation_query = "
                    SELECT 
                        dr.id as req_id,
                        dr.name as req_name,
                        sd.status as doc_status
                    FROM document_requirements dr
                    LEFT JOIN student_documents sd ON dr.id = sd.document_id AND sd.student_id = ?
                    WHERE dr.is_required = 1
                ";
                
                $stmt = mysqli_prepare($conn, $validation_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $requirements = mysqli_fetch_all($result, MYSQLI_ASSOC);
                
                $missing_requirements = [];
                $pending_requirements = [];
                $rejected_requirements = [];
                
                foreach ($requirements as $req) {
                    if ($req['doc_status'] === null) {
                        $missing_requirements[] = $req['req_name'];
                    } elseif ($req['doc_status'] === 'pending') {
                        $pending_requirements[] = $req['req_name'];
                    } elseif ($req['doc_status'] === 'rejected') {
                        $rejected_requirements[] = $req['req_name'];
                    }
                }
                
                // If there are any missing, pending, or rejected required documents, don't allow deployment
                if (!empty($missing_requirements) || !empty($pending_requirements) || !empty($rejected_requirements)) {
                    $error_message = "Cannot mark student as ready for deployment. ";
                    
                    if (!empty($missing_requirements)) {
                        $error_message .= "Missing required documents: " . implode(', ', $missing_requirements) . ". ";
                    }
                    if (!empty($pending_requirements)) {
                        $error_message .= "Documents pending review: " . implode(', ', $pending_requirements) . ". ";
                    }
                    if (!empty($rejected_requirements)) {
                        $error_message .= "Rejected documents that need resubmission: " . implode(', ', $rejected_requirements) . ". ";
                    }
                    
                    echo json_encode([
                        'success' => false, 
                        'message' => $error_message
                    ]);
                    break;
                }
                
                // All requirements are met, proceed with marking as ready for deployment
                $update_query = "UPDATE students SET ready_for_deployment = 1 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                
                if ($success) {
                    // Log this action for audit purposes
                    $log_query = "INSERT INTO deployment_log (student_id, action, performed_by, performed_at) VALUES (?, 'marked_ready', ?, NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "ii", $student_id, $adviser_id);
                    mysqli_stmt_execute($log_stmt);
                }
                
                echo json_encode([
                    'success' => $success, 
                    'message' => $success ? 'Student marked as ready for deployment! Student status has been updated.' : 'Failed to update deployment status',
                    'reload' => $success
                ]);
                break;

            case 'unmark_deployment':
                $student_id = (int)$_POST['student_id'];
                $update_query = "UPDATE students SET ready_for_deployment = 0 WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                $success = mysqli_stmt_execute($stmt);
                
                if ($success) {
                    // Log this action for audit purposes
                    $log_query = "INSERT INTO deployment_log (student_id, action, performed_by, performed_at) VALUES (?, 'unmarked_ready', ?, NOW())";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "ii", $student_id, $adviser_id);
                    mysqli_stmt_execute($log_stmt);
                }
                
                echo json_encode([
                    'success' => $success, 
                    'message' => $success ? 'Student removed from deployment queue successfully' : 'Failed to update deployment status',
                    'reload' => $success
                ]);
                break;

            case 'get_details':
                $student_id = (int)$_POST['student_id'];
                $details_query = "SELECT * FROM students WHERE id = ?";
                $stmt = mysqli_prepare($conn, $details_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $student = mysqli_fetch_assoc($result);
                
                if ($student) {
                    echo json_encode(['success' => true, 'student' => $student]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                }
                break;

            case 'get_documents':
                $student_id = (int)$_POST['student_id'];
                // Add search parameter if provided
                $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
                
                // Get all required documents
                $required_docs_query = "SELECT * FROM document_requirements WHERE is_required = 1 ORDER BY name";
                $required_docs_result = mysqli_query($conn, $required_docs_query);
                $required_documents = mysqli_fetch_all($required_docs_result, MYSQLI_ASSOC);
                
                // Get student's submitted documents
                $documents_query = "
                    SELECT sd.*, s.first_name, s.last_name, s.student_id as student_number, dr.name as document_name, dr.description as document_description
                    FROM student_documents sd
                    JOIN students s ON sd.student_id = s.id
                    LEFT JOIN document_requirements dr ON sd.document_id = dr.id
                    WHERE sd.student_id = ?
                ";
                
                // Add search condition if search term is provided
                if (!empty($search)) {
                    $documents_query .= " AND (sd.name LIKE '%$search%' OR sd.description LIKE '%$search%' OR sd.original_filename LIKE '%$search%' OR s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%')";
                }
                
                $documents_query .= " ORDER BY sd.submitted_at DESC";
                
                $stmt = mysqli_prepare($conn, $documents_query);
                mysqli_stmt_bind_param($stmt, "i", $student_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $submitted_documents = mysqli_fetch_all($result, MYSQLI_ASSOC);
                
                // Check if ALL required documents are submitted AND approved
                $all_requirements_met = true;
                $missing_documents = [];
                $pending_documents = [];
                $rejected_documents = [];
                
                foreach ($required_documents as $required_doc) {
                    // Check if this required document has been submitted
                    $submitted = false;
                    $approved = false;
                    
                    foreach ($submitted_documents as $submitted_doc) {
                        if ($submitted_doc['document_id'] == $required_doc['id']) {
                            $submitted = true;
                            if ($submitted_doc['status'] === 'approved') {
                                $approved = true;
                            } elseif ($submitted_doc['status'] === 'pending') {
                                $pending_documents[] = $required_doc['name'];
                            } elseif ($submitted_doc['status'] === 'rejected') {
                                $rejected_documents[] = $required_doc['name'];
                            }
                            break;
                        }
                    }
                    
                    if (!$submitted) {
                        $missing_documents[] = $required_doc['name'];
                        $all_requirements_met = false;
                    } elseif (!$approved) {
                        $all_requirements_met = false;
                    }
                }
                
                // Calculate statistics
                $total_required = count($required_documents);
                $total_submitted = count($submitted_documents);
                $total_approved = count(array_filter($submitted_documents, function($doc) {
                    return $doc['status'] === 'approved';
                }));
                $total_pending = count(array_filter($submitted_documents, function($doc) {
                    return $doc['status'] === 'pending';
                }));
                $total_rejected = count(array_filter($submitted_documents, function($doc) {
                    return $doc['status'] === 'rejected';
                }));
                
                echo json_encode([
                    'success' => true, 
                    'documents' => $submitted_documents,
                    'required_documents' => $required_documents,
                    'all_requirements_met' => $all_requirements_met,
                    'missing_documents' => $missing_documents,
                    'pending_documents' => $pending_documents,
                    'rejected_documents' => $rejected_documents,
                    'statistics' => [
                        'total_required' => $total_required,
                        'total_submitted' => $total_submitted,
                        'total_approved' => $total_approved,
                        'total_pending' => $total_pending,
                        'total_rejected' => $total_rejected,
                        'approved_required' => count(array_filter($submitted_documents, function($doc) use ($required_documents) {
                            foreach ($required_documents as $req_doc) {
                                if ($doc['document_id'] == $req_doc['id'] && $doc['status'] === 'approved') {
                                    return true;
                                }
                            }
                            return false;
                        }))
                    ]
                ]);
                break;

            case 'get_all_pending_documents':
                // Add search parameter if provided
                $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
                
                $documents_query = "
                    SELECT sd.*, s.first_name, s.last_name, s.student_id as student_number, s.department
                    FROM student_documents sd
                    JOIN students s ON sd.student_id = s.id
                    WHERE sd.status = 'pending'
                ";
                
                // Add search condition if search term is provided
                if (!empty($search)) {
                    $documents_query .= " AND (sd.name LIKE '%$search%' OR sd.description LIKE '%$search%' OR sd.original_filename LIKE '%$search%' OR s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%')";
                }
                
                $documents_query .= " ORDER BY sd.submitted_at DESC";
                
                $documents_result = mysqli_query($conn, $documents_query);
                
                if ($documents_result) {
                    $documents = mysqli_fetch_all($documents_result, MYSQLI_ASSOC);
                    echo json_encode(['success' => true, 'documents' => $documents]);
                } else {
                    // Log the actual MySQL error for debugging
                    error_log("MySQL Error in get_all_pending_documents: " . mysqli_error($conn));
                    echo json_encode(['success' => false, 'message' => 'Database query failed: ' . mysqli_error($conn)]);
                }
                break;

            case 'approve_document':
                $document_id = (int)$_POST['document_id'];
                $feedback = isset($_POST['feedback']) ? mysqli_real_escape_string($conn, $_POST['feedback']) : '';
                
                // First get document and student information
                $doc_query = "
                    SELECT sd.student_id, sd.original_filename, dr.name as document_name 
                    FROM student_documents sd 
                    LEFT JOIN document_requirements dr ON sd.document_id = dr.id 
                    WHERE sd.id = ?
                ";
                $stmt = mysqli_prepare($conn, $doc_query);
                mysqli_stmt_bind_param($stmt, "i", $document_id);
                mysqli_stmt_execute($stmt);
                $doc_result = mysqli_stmt_get_result($stmt);
                $doc_info = mysqli_fetch_assoc($doc_result);
                
                if ($doc_info) {
                    // Update document status
                    $update_query = "UPDATE student_documents SET status = 'approved', feedback = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "sii", $feedback, $adviser_id, $document_id);
                    $success = mysqli_stmt_execute($stmt);
                    
                    if ($success) {
                        // Create notification for student
                        $document_name = $doc_info['document_name'] ?: $doc_info['original_filename'];
                        createDocumentStatusNotification(
                            $conn, 
                            $doc_info['student_id'], 
                            $document_id, 
                            'approved', 
                            $document_name, 
                            $feedback
                        );
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'Document approved successfully! Student has been notified.'
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Failed to approve document'
                        ]);
                    }
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Document not found'
                    ]);
                }
                break;

            case 'reject_document':
                $document_id = (int)$_POST['document_id'];
                $feedback = isset($_POST['feedback']) ? mysqli_real_escape_string($conn, $_POST['feedback']) : '';
                
                // First get document and student information
                $doc_query = "
                    SELECT sd.student_id, sd.original_filename, dr.name as document_name 
                    FROM student_documents sd 
                    LEFT JOIN document_requirements dr ON sd.document_id = dr.id 
                    WHERE sd.id = ?
                ";
                $stmt = mysqli_prepare($conn, $doc_query);
                mysqli_stmt_bind_param($stmt, "i", $document_id);
                mysqli_stmt_execute($stmt);
                $doc_result = mysqli_stmt_get_result($stmt);
                $doc_info = mysqli_fetch_assoc($doc_result);
                
                if ($doc_info) {
                    // Update document status
                    $update_query = "UPDATE student_documents SET status = 'rejected', feedback = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "sii", $feedback, $adviser_id, $document_id);
                    $success = mysqli_stmt_execute($stmt);
                    
                    if ($success) {
                        // Create notification for student
                        $document_name = $doc_info['document_name'] ?: $doc_info['original_filename'];
                        createDocumentStatusNotification(
                            $conn, 
                            $doc_info['student_id'], 
                            $document_id, 
                            'rejected', 
                            $document_name, 
                            $feedback
                        );
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'Document rejected successfully! Student has been notified.'
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Failed to reject document'
                        ]);
                    }
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Document not found'
                    ]);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch adviser profile picture
try {
    $adviser_query = "SELECT profile_picture FROM Academic_Adviser WHERE id = ?";
    $adviser_stmt = mysqli_prepare($conn, $adviser_query);
    mysqli_stmt_bind_param($adviser_stmt, "i", $adviser_id);
    mysqli_stmt_execute($adviser_stmt);
    $adviser_result = mysqli_stmt_get_result($adviser_stmt);
    
    if ($adviser_result && mysqli_num_rows($adviser_result) > 0) {
        $adviser_data = mysqli_fetch_assoc($adviser_result);
        $profile_picture = $adviser_data['profile_picture'] ?? '';
    } else {
        $profile_picture = '';
    }
} catch (Exception $e) {
    $profile_picture = '';
}
// Helper function to determine student status
function getStudentStatus($student) {
    // Check if student is deployed first
    if ($student['deployment_id'] !== null) {
        return ['status' => 'deployed', 'text' => 'Deployed'];
    } elseif ($student['ready_for_deployment'] == 1) {
        return ['status' => 'ready', 'text' => 'Ready for Deployment'];
    } elseif ($student['verified'] == 0) {
        return ['status' => 'unverified', 'text' => 'Unverified'];
    } elseif ($student['login_attempts'] >= 3 || $student['status'] == 'Blocked') {
        return ['status' => 'blocked', 'text' => 'Blocked'];
    } elseif ($student['status'] == 'Active') {
        return ['status' => 'active', 'text' => 'Active'];
    } else {
        return ['status' => 'inactive', 'text' => 'Inactive'];
    }
}
// Create adviser initials
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Accounts</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
      <script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                'bulsu-maroon': '#800000',     // Primary Maroon
                'bulsu-dark-maroon': '#6B1028',// Dark shade ng maroon
                'bulsu-gold': '#DAA520',       // Official Gold
                'bulsu-light-gold': '#F4E4BC', // Accent light gold
                'bulsu-white': '#FFFFFF'       // Supporting White
            }
        }
    }
}
</script>
    <style>
        /* Custom CSS for features not easily achievable with Tailwind */
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        .progress-fill {
            transition: width 2s ease-in-out;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Student specific styles */
        .student-row:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal {
            backdrop-filter: blur(4px);
        }

        .document-name {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1rem;
        }

        .document-card {
            transition: all 0.3s ease;
        }

        .document-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }

        .status-active { 
            @apply bg-green-100 text-green-800 border-green-200; 
        }
        .status-blocked { 
            @apply bg-red-100 text-red-800 border-red-200; 
        }
        .status-unverified { 
            @apply bg-yellow-100 text-yellow-800 border-yellow-200; 
        }
        .status-inactive { 
            @apply bg-gray-100 text-gray-800 border-gray-200; 
        }
        .status-deployed { 
            @apply bg-blue-100 text-blue-800 border-blue-200; 
        }
        .status-pending { 
            @apply bg-yellow-100 text-yellow-800 border-yellow-200; 
        }
        .status-approved { 
            @apply bg-green-100 text-green-800 border-green-200; 
        }
        .status-rejected { 
            @apply bg-red-100 text-red-800 border-red-200; 
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
    <!-- Close button for mobile -->
    <div class="flex justify-end p-4 lg:hidden">
        <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>

    <!-- Logo Section with BULSU Branding -->
    <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
        <div class="flex items-center">
            <!-- BULSU Logos -->
             <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-14 h-14 mr-2">
            <!-- Brand Name -->
            <div class="flex items-center font-bold text-lg text-white">
                <span>OnTheJob</span>
                <span class="ml-1">Tracker</span>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <div class="px-4 py-6">
        <h2 class="text-xs font-semibold text-bulsu-light-gold uppercase tracking-wide mb-4">Navigation</h2>
        <nav class="space-y-2">
            <a href="AdviserDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-th-large mr-3"></i>
                Dashboard
            </a>
            <a href="ViewOJTCoordinators.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-users-cog mr-3"></i>
                View OJT Company Supervisor
            </a>
            <a href="StudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-user-graduate mr-3 text-bulsu-gold"></i>
                Student Accounts
            </a>
            <a href="StudentDeployment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-paper-plane mr-3"></i>
                Student Deployment
            </a>
            <a href="StudentPerformance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-chart-line mr-3"></i>
                Student Performance
            </a>
            <a href="StudentRecords.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-folder-open mr-3"></i>
                Student Records
            </a>
            <a href="GenerateReports.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-file-alt mr-3"></i>
                Generate Reports
            </a>
            <a href="AdminAlerts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-bell mr-3"></i>
                Administrative Alerts
            </a>
            <a href="academicAdviserMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Messages
                <?php if ($unread_messages_count > 0): ?>
                    <span class="notification-badge" id="sidebar-notification-badge">
                        <?php echo $unread_messages_count; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="academicAdviserEdit.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-edit mr-3"></i>
                Edit Document
            </a>
        </nav>
    </div>
    
    <!-- User Profile -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
        <div class="flex items-center space-x-3">
<div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm overflow-hidden">
    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
    <?php else: ?>
        <?php echo $adviser_initials; ?>
    <?php endif; ?>
</div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($adviser_name); ?></p>
                <p class="text-xs text-bulsu-light-gold">Academic Adviser</p>
            </div>
        </div>
    </div>
</div>
    
    <!-- Main Content -->
    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <!-- Header Title -->
                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Accounts</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitor and manage student accounts in the OJT program</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm overflow-hidden">
    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
    <?php else: ?>
        <?php echo $adviser_initials; ?>
    <?php endif; ?>
</div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold overflow-hidden">
    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full object-cover">
    <?php else: ?>
        <?php echo $adviser_initials; ?>
    <?php endif; ?>
</div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($adviser_name); ?></p>
                                    <p class="text-sm text-gray-500">Academic Adviser</p>
                                </div>
                            </div>
                        </div>
                        <a href="AdviserAccountSettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-cog mr-3"></i>
                            Account Settings
                        </a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" onclick="return confirmLogout()">
                            <i class="fas fa-sign-out-alt mr-3"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Error Message Display -->
            <?php if (isset($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-1">Student Management</h2>
                    <p class="text-sm text-gray-600">Monitor student accounts and document submissions</p>
                </div>
                <div>
                   
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $total_students; ?></div>
<div class="text-sm text-gray-600">Total Students</div>
                        </div>
                        <div class="text-green-500">
                            <i class="fas fa-user-check text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-red-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $blocked_students; ?></div>
                            <div class="text-sm text-gray-600">Blocked Students</div>
                        </div>
                        <div class="text-red-500">
                            <i class="fas fa-user-times text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-yellow-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $unverified_students; ?></div>
                            <div class="text-sm text-gray-600">Unverified Students</div>    
                        </div>
                        <div class="text-yellow-500">
                            <i class="fas fa-user-clock text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
    <div class="flex items-center justify-between">
        <div>
            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $deployed_students; ?></div>
            <div class="text-sm text-gray-600">Deployed Students</div>
        </div>
        <div class="text-blue-500">
            <i class="fas fa-briefcase text-2xl"></i>
        </div>
    </div>
</div>
            </div>

            <!-- Filters Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search Students</label>
                            <div class="relative">
                                <input type="text" id="searchInput" 
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Search by name, email, or ID..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Department</label>
                            <select id="departmentFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Departments</option>
                                <?php 
                                mysqli_data_seek($departments_result, 0); // Reset result pointer
                                while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                            <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Section</label>
    <select id="sectionFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
        <option value="">All Sections</option>
        <?php 
        mysqli_data_seek($sections_result, 0);
        while ($sect = mysqli_fetch_assoc($sections_result)): ?>
            <option value="<?php echo htmlspecialchars($sect['section']); ?>" 
                    <?php echo $section_filter === $sect['section'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($sect['section']); ?>
            </option>
        <?php endwhile; ?>
    </select>
</div>


                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Status</label>
                         <select id="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
    <option value="">All Status</option>
    <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
    <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
    <option value="ready_for_deployment" <?php echo $status_filter === 'ready_for_deployment' ? 'selected' : ''; ?>>Ready for Deployment</option>
    <option value="deployed" <?php echo $status_filter === 'deployed' ? 'selected' : ''; ?>>Deployed</option>
</select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Student List</h3>
                        <p class="text-sm text-gray-500 mt-1 sm:mt-0">
                            Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> students
                        </p>
                    </div>
                </div>

                <?php if (mysqli_num_rows($students_result) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Year & Section</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                    <?php $studentStatus = getStudentStatus($student); ?>
                                    <tr class="student-row hover:bg-gray-50 cursor-pointer transition-all duration-200" 
                                        data-student-id="<?php echo $student['id']; ?>" 
                                        onclick="viewStudentDetails(<?php echo $student['id']; ?>)">
                                       <td class="px-6 py-4 whitespace-nowrap">
    <div class="flex items-center">
        <div class="flex-shrink-0 h-10 w-10">
            <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm">
                <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
            </div>
        </div>
        <div class="ml-4">
            <div class="text-sm font-medium text-gray-900">
                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
            </div>
            <div class="text-sm text-gray-500">
                <?php echo htmlspecialchars($student['email']); ?>
            </div>
            <?php if ($student['login_attempts'] >= 3): ?>
                <div class="text-xs text-red-600 mt-1">
                    Login attempts: <?php echo $student['login_attempts']; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($student['deployment_id']): ?>
                <div class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mt-1">
                    <i class="fas fa-briefcase mr-1"></i>
                    Deployed to <?php echo htmlspecialchars($student['company_name']); ?>
                </div>
            <?php elseif ($student['ready_for_deployment'] == 1): ?>
                <div class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1">
                    <i class="fas fa-rocket mr-1"></i>
                    Ready for Deployment
                </div>
            <?php endif; ?>
        </div>
    </div>
</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['student_id']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['department'] ?: 'Not Assigned'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['program'] ?: 'Not Assigned'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars(($student['year_level'] ?: '') . ($student['section'] ? ' - ' . $student['section'] : '') ?: 'Not Assigned'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border status-<?php echo $studentStatus['status']; ?>">
                                                <?php echo $studentStatus['text']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium" onclick="event.stopPropagation()">
                                            <div class="flex space-x-2">
                                                <button onclick="viewStudentDocuments(<?php echo $student['id']; ?>)" 
                                                        class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200 transition-colors text-xs"
                                                        title="View Documents">
                                                    <i class="fas fa-file-alt mr-1"></i>
                                                    Documents
                                                </button>
                                                
                                                <?php if ($student['login_attempts'] >= 3 || $student['status'] == 'Blocked'): ?>
                                                    <button onclick="performAction(<?php echo $student['id']; ?>, 'unblock')" 
                                                            class="inline-flex items-center px-3 py-1 bg-yellow-100 text-yellow-700 rounded-md hover:bg-yellow-200 transition-colors text-xs"
                                                            title="Unblock Student">
                                                        <i class="fas fa-unlock mr-1"></i>
                                                        Unblock
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="performAction(<?php echo $student['id']; ?>, 'block')" 
                                                            class="inline-flex items-center px-3 py-1 bg-red-100 text-red-700 rounded-md hover:bg-red-200 transition-colors text-xs"
                                                            title="Block Student">
                                                        <i class="fas fa-ban mr-1"></i>
                                                        Block
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($student['status'] != 'Active'): ?>
                                                    <button onclick="performAction(<?php echo $student['id']; ?>, 'activate')" 
                                                            class="inline-flex items-center px-3 py-1 bg-green-100 text-green-700 rounded-md hover:bg-green-200 transition-colors text-xs"
                                                            title="Activate Student">
                                                        <i class="fas fa-play mr-1"></i>
                                                        Activate
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No students found</h3>
                        <p class="text-gray-600">No students match your current search criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-6 flex items-center justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-left mr-1"></i>
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i == $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                Next
                                <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="flex items-center justify-between pb-3 border-b">
                <h3 class="text-lg font-medium text-gray-900">Student Details</h3>
                <button onclick="closeModal('studentModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="mt-4">
                <div class="flex items-center space-x-4 mb-6">
                    <div id="studentInitials" class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-xl">
                        --
                    </div>
                    <div class="flex-1">
                        <h4 id="studentName" class="text-xl font-semibold text-gray-900">Loading...</h4>
                        <p id="studentEmail" class="text-gray-600">Loading...</p>
                        <span id="studentStatusBadge" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-2">
                            Loading...
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Student ID</label>
                            <p id="studentID" class="text-sm text-gray-900">--</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Department</label>
                            <p id="studentDepartment" class="text-sm text-gray-900">--</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Program</label>
                            <p id="studentProgram" class="text-sm text-gray-900">--</p>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Year Level</label>
                            <p id="studentYear" class="text-sm text-gray-900">--</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Section</label>
                            <p id="studentSection" class="text-sm text-gray-900">--</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Verified</label>
                            <p id="verifiedStatus" class="text-sm text-gray-900">--</p>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <div class="flex items-center justify-between mb-4">
                        <h5 class="text-md font-medium text-gray-900">
                            <i class="fas fa-file-alt mr-2"></i>Document Status
                        </h5>
                        <div class="flex space-x-4 text-sm">
                            <div class="text-center">
                                <div id="totalDocs" class="font-semibold text-gray-900">0</div>
                                <div class="text-gray-500">Total</div>
                            </div>
                            <div class="text-center">
                                <div id="approvedDocs" class="font-semibold text-green-600">0</div>
                                <div class="text-gray-500">Approved</div>
                            </div>
                            <div class="text-center">
                                <div id="pendingDocs" class="font-semibold text-yellow-600">0</div>
                                <div class="text-gray-500">Pending</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button onclick="viewStudentDocumentsFromModal()" 
                                class="flex-1 inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-eye mr-2"></i>
                            View All Documents
                        </button>
                        <button id="deployButton" onclick="markReadyForDeployment()" disabled
                                class="flex-1 inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm bg-gray-300 text-sm font-medium text-gray-500 cursor-not-allowed">
                            <i class="fas fa-rocket mr-2"></i>
                            Mark Ready for Deployment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Documents Modal -->
    <div id="documentsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-5/6 lg:w-4/5 shadow-lg rounded-md bg-white">
            <div class="flex items-center justify-between pb-3 border-b">
                <h3 class="text-lg font-medium text-gray-900">Student Documents</h3>
                <button onclick="closeModal('documentsModal')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                          </button>
           </div>
           
           <div class="mt-4">
               <!-- Student Info Header -->
               <div id="documentsStudentInfo" class="bg-gray-50 p-4 rounded-lg mb-4">
                   <div class="flex items-center space-x-3">
                       <div id="documentsStudentInitials" class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                           --
                       </div>
                       <div>
                           <h4 id="documentsStudentName" class="font-semibold text-gray-900">Loading...</h4>
                           <p id="documentsStudentID" class="text-sm text-gray-600">Loading...</p>
                       </div>
                   </div>
               </div>

               <!-- Document Statistics -->
               <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                   <div class="bg-blue-50 p-3 rounded-lg text-center">
                       <div id="statsRequired" class="text-2xl font-bold text-blue-600">0</div>
                       <div class="text-xs text-blue-600">Required</div>
                   </div>
                   <div class="bg-gray-50 p-3 rounded-lg text-center">
                       <div id="statsSubmitted" class="text-2xl font-bold text-gray-600">0</div>
                       <div class="text-xs text-gray-600">Submitted</div>
                   </div>
                   <div class="bg-green-50 p-3 rounded-lg text-center">
                       <div id="statsApproved" class="text-2xl font-bold text-green-600">0</div>
                       <div class="text-xs text-green-600">Approved</div>
                   </div>
                   <div class="bg-yellow-50 p-3 rounded-lg text-center">
                       <div id="statsPending" class="text-2xl font-bold text-yellow-600">0</div>
                       <div class="text-xs text-yellow-600">Pending</div>
                   </div>
                   <div class="bg-red-50 p-3 rounded-lg text-center">
                       <div id="statsRejected" class="text-2xl font-bold text-red-600">0</div>
                       <div class="text-xs text-red-600">Rejected</div>
                   </div>
               </div>

               <!-- Search Documents -->
               <div class="mb-4">
                   <div class="relative">
                       <input type="text" id="documentSearch" 
                              class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                              placeholder="Search documents...">
                       <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                           <i class="fas fa-search text-gray-400"></i>
                       </div>
                   </div>
               </div>

               <!-- Requirements Status -->
               <div id="requirementsStatus" class="mb-6">
                   <!-- Missing documents alert -->
                   <div id="missingDocsAlert" class="hidden bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                       <div class="flex items-start">
                           <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3"></i>
                           <div>
                               <h5 class="font-medium text-red-800">Missing Required Documents</h5>
                               <ul id="missingDocsList" class="text-sm text-red-700 mt-2 space-y-1"></ul>
                           </div>
                       </div>
                   </div>

                   <!-- Pending documents alert -->
                   <div id="pendingDocsAlert" class="hidden bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                       <div class="flex items-start">
                           <i class="fas fa-clock text-yellow-600 mt-1 mr-3"></i>
                           <div>
                               <h5 class="font-medium text-yellow-800">Documents Pending Review</h5>
                               <ul id="pendingDocsList" class="text-sm text-yellow-700 mt-2 space-y-1"></ul>
                           </div>
                       </div>
                   </div>

                   <!-- Rejected documents alert -->
                   <div id="rejectedDocsAlert" class="hidden bg-orange-50 border border-orange-200 rounded-lg p-4 mb-4">
                       <div class="flex items-start">
                           <i class="fas fa-times-circle text-orange-600 mt-1 mr-3"></i>
                           <div>
                               <h5 class="font-medium text-orange-800">Rejected Documents (Need Resubmission)</h5>
                               <ul id="rejectedDocsList" class="text-sm text-orange-700 mt-2 space-y-1"></ul>
                           </div>
                       </div>
                   </div>

                   <!-- All requirements met -->
                   <div id="requirementsMetAlert" class="hidden bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                       <div class="flex items-center">
                           <i class="fas fa-check-circle text-green-600 mr-3"></i>
                           <div>
                               <h5 class="font-medium text-green-800">All Requirements Met</h5>
                               <p class="text-sm text-green-700">Student has submitted and received approval for all required documents.</p>
                           </div>
                       </div>
                   </div>
               </div>

               <!-- Documents List -->
               <div id="documentsContainer" class="documents-grid">
                   <!-- Documents will be loaded here -->
               </div>

               <!-- Loading State -->
               <div id="documentsLoading" class="text-center py-8">
                   <i class="fas fa-spinner animate-spin text-2xl text-gray-400 mb-2"></i>
                   <p class="text-gray-600">Loading documents...</p>
               </div>

               <!-- Empty State -->
               <div id="documentsEmpty" class="hidden text-center py-8">
                   <i class="fas fa-folder-open text-4xl text-gray-400 mb-4"></i>
                   <h4 class="text-lg font-medium text-gray-900 mb-2">No Documents Found</h4>
                   <p class="text-gray-600">No documents have been submitted yet.</p>
               </div>
           </div>
       </div>
   </div>

   <!-- All Pending Documents Modal -->
   <div id="allPendingDocsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
       <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-5/6 lg:w-4/5 shadow-lg rounded-md bg-white">
           <div class="flex items-center justify-between pb-3 border-b">
               <h3 class="text-lg font-medium text-gray-900">All Pending Documents</h3>
               <button onclick="closeModal('allPendingDocsModal')" class="text-gray-400 hover:text-gray-600">
                   <i class="fas fa-times text-xl"></i>
               </button>
           </div>
           
           <div class="mt-4">
               <!-- Search Pending Documents -->
               <div class="mb-4">
                   <div class="relative">
                       <input type="text" id="pendingDocumentSearch" 
                              class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                              placeholder="Search pending documents...">
                       <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                           <i class="fas fa-search text-gray-400"></i>
                       </div>
                   </div>
               </div>

               <!-- Pending Documents List -->
               <div id="pendingDocumentsContainer" class="documents-grid">
                   <!-- Pending documents will be loaded here -->
               </div>

               <!-- Loading State -->
               <div id="pendingDocsLoading" class="text-center py-8">
                   <i class="fas fa-spinner animate-spin text-2xl text-gray-400 mb-2"></i>
                   <p class="text-gray-600">Loading pending documents...</p>
               </div>

               <!-- Empty State -->
               <div id="pendingDocsEmpty" class="hidden text-center py-8">
                   <i class="fas fa-check-circle text-4xl text-green-400 mb-4"></i>
                   <h4 class="text-lg font-medium text-gray-900 mb-2">No Pending Documents</h4>
                   <p class="text-gray-600">All documents have been reviewed!</p>
               </div>
           </div>
       </div>
   </div>

   <!-- Document Action Modal -->
   <div id="documentActionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
       <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
           <div class="flex items-center justify-between pb-3 border-b">
               <h3 id="documentActionTitle" class="text-lg font-medium text-gray-900">Document Action</h3>
               <button onclick="closeModal('documentActionModal')" class="text-gray-400 hover:text-gray-600">
                   <i class="fas fa-times text-xl"></i>
               </button>
           </div>
           
           <div class="mt-4">
               <div id="documentActionInfo" class="mb-4">
                   <p class="text-sm text-gray-600">Document: <span id="actionDocumentName" class="font-medium"></span></p>
                   <p class="text-sm text-gray-600">Student: <span id="actionStudentName" class="font-medium"></span></p>
               </div>
               
               <div class="mb-4">
                   <label class="block text-sm font-medium text-gray-700 mb-2">Feedback (Optional)</label>
                   <textarea id="documentFeedback" 
                             class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                             rows="4" 
                             placeholder="Add feedback for the student..."></textarea>
               </div>
               
               <div class="flex space-x-3">
                   <button onclick="closeModal('documentActionModal')" 
                           class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                       Cancel
                   </button>
                   <button id="confirmDocumentAction" 
                           class="flex-1 px-4 py-2 rounded-md text-sm font-medium text-white">
                       Confirm
                   </button>
               </div>
           </div>
       </div>
   </div>
<!-- Mark Ready for Deployment Modal -->
<div id="deploymentConfirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-2/5 shadow-lg rounded-md bg-white">
        <div class="flex items-center justify-between pb-3 border-b">
            <h3 class="text-lg font-medium text-gray-900">
                <i class="fas fa-rocket mr-2 text-blue-600"></i>
                Mark Student Ready for Deployment
            </h3>
            <button onclick="closeModal('deploymentConfirmModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="mt-4">
            <!-- Student Info -->
            <div class="bg-blue-50 p-4 rounded-lg mb-4">
                <div class="flex items-center space-x-3">
                    <div id="deployStudentInitials" class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                        --
                    </div>
                    <div>
                        <h4 id="deployStudentName" class="font-semibold text-gray-900">Loading...</h4>
                        <p id="deployStudentID" class="text-sm text-gray-600">Loading...</p>
                    </div>
                </div>
            </div>

            <!-- Requirements Summary -->
            <div id="deploymentRequirementsSummary" class="mb-4">
                <!-- Will be populated by JavaScript -->
            </div>

            <!-- Warning Message -->
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <i class="fas fa-exclamation-triangle text-amber-600 mt-1 mr-3"></i>
                    <div>
                        <h5 class="font-medium text-amber-800">Important Notice</h5>
                        <p class="text-sm text-amber-700 mt-1">
                            Once marked as ready for deployment, this student will be moved from the active student list 
                            and transferred to the deployment queue. This action cannot be easily undone.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Confirmation Checkbox -->
            <div class="flex items-center mb-6">
                <input type="checkbox" id="deploymentConfirmCheck" 
                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="deploymentConfirmCheck" class="ml-2 text-sm text-gray-700">
                    I confirm that all requirements have been verified and this student is ready for deployment
                </label>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex space-x-3">
                <button onclick="closeModal('deploymentConfirmModal')" 
                        class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-times mr-2"></i>
                    Cancel
                </button>
                <button id="confirmDeploymentBtn" onclick="confirmDeployment()" disabled
                        class="flex-1 px-4 py-2 rounded-md text-sm font-medium text-white bg-gray-300 cursor-not-allowed">
                    <i class="fas fa-rocket mr-2"></i>
                    Confirm Deployment
                </button>
            </div>
        </div>
    </div>
</div>
   <!-- Success Toast -->
   <div id="successToast" class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-md shadow-lg z-50 hidden">
       <div class="flex items-center">
           <i class="fas fa-check-circle mr-2"></i>
           <span id="successMessage">Success!</span>
       </div>
   </div>

   <!-- Error Toast -->
   <div id="errorToast" class="fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-md shadow-lg z-50 hidden">
       <div class="flex items-center">
           <i class="fas fa-exclamation-circle mr-2"></i>
           <span id="errorMessage">Error!</span>
       </div>
   </div>

   <!-- JavaScript -->
   <script>
       // Global variables
       let currentStudentId = null;
       let currentDocumentId = null;
       let currentAction = null;

       // Mobile menu functionality
       document.getElementById('mobileMenuBtn').addEventListener('click', function() {
           document.getElementById('sidebar').classList.remove('-translate-x-full');
           document.getElementById('sidebarOverlay').classList.remove('hidden');
       });

       document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
       document.getElementById('sidebarOverlay').addEventListener('click', closeSidebar);

       function closeSidebar() {
           document.getElementById('sidebar').classList.add('-translate-x-full');
           document.getElementById('sidebarOverlay').classList.add('hidden');
       }

       // Profile dropdown functionality
       document.getElementById('profileBtn').addEventListener('click', function() {
           const dropdown = document.getElementById('profileDropdown');
           dropdown.classList.toggle('hidden');
       });

       // Close dropdown when clicking outside
       document.addEventListener('click', function(event) {
           const profileBtn = document.getElementById('profileBtn');
           const dropdown = document.getElementById('profileDropdown');
           
           if (!profileBtn.contains(event.target) && !dropdown.contains(event.target)) {
               dropdown.classList.add('hidden');
           }
       });

       // Filter functionality
       document.getElementById('searchInput').addEventListener('keyup', function(e) {
           if (e.key === 'Enter') {
               applyFilters();
           }
       });

       document.getElementById('departmentFilter').addEventListener('change', applyFilters);
       document.getElementById('statusFilter').addEventListener('change', applyFilters);

       function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const department = document.getElementById('departmentFilter').value;
    const section = document.getElementById('sectionFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (department) params.append('department', department);
    if (section) params.append('section', section);
    if (status) params.append('status', status);
    
    window.location.href = `${window.location.pathname}?${params.toString()}`;
}

// Add event listener for section filter
document.getElementById('sectionFilter').addEventListener('change', applyFilters);



       // Document search functionality
       document.getElementById('documentSearch').addEventListener('input', function() {
           if (currentStudentId) {
               loadStudentDocuments(currentStudentId, this.value);
           }
       });

       document.getElementById('pendingDocumentSearch').addEventListener('input', function() {
           loadAllPendingDocuments(this.value);
       });

       // Student actions
       function performAction(studentId, action) {
           if (!confirm(`Are you sure you want to ${action} this student?`)) {
               return;
           }

           const formData = new FormData();
           formData.append('action', action);
           formData.append('student_id', studentId);

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               if (data.success) {
                   showToast('success', data.message);
                   setTimeout(() => {
                       window.location.reload();
                   }, 1000);
               } else {
                   showToast('error', data.message);
               }
           })
           .catch(error => {
               console.error('Error:', error);
               showToast('error', 'An error occurred');
           });
       }

       // View student details
       function viewStudentDetails(studentId) {
           currentStudentId = studentId;
           
           const formData = new FormData();
           formData.append('action', 'get_details');
           formData.append('student_id', studentId);

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               if (data.success) {
                   populateStudentDetails(data.student);
                   loadStudentDocuments(studentId);
                   document.getElementById('studentModal').classList.remove('hidden');
               } else {
                   showToast('error', data.message);
               }
           })
           .catch(error => {
               console.error('Error:', error);
               showToast('error', 'Failed to load student details');
           });
       }

       function populateStudentDetails(student) {
           const initials = student.first_name.charAt(0) + (student.last_name ? student.last_name.charAt(0) : '');
           document.getElementById('studentInitials').textContent = initials.toUpperCase();
           document.getElementById('studentName').textContent = `${student.first_name} ${student.last_name}`;
           document.getElementById('studentEmail').textContent = student.email;
           document.getElementById('studentID').textContent = student.student_id || 'Not assigned';
           document.getElementById('studentDepartment').textContent = student.department || 'Not assigned';
           document.getElementById('studentProgram').textContent = student.program || 'Not assigned';
           document.getElementById('studentYear').textContent = student.year_level || 'Not assigned';
           document.getElementById('studentSection').textContent = student.section || 'Not assigned';
           document.getElementById('verifiedStatus').textContent = student.verified == 1 ? 'Yes' : 'No';

           // Set status badge
           const statusBadge = document.getElementById('studentStatusBadge');
           let statusClass = '';
           let statusText = '';
           
           if (student.verified == 0) {
               statusClass = 'status-unverified';
               statusText = 'Unverified';
           } else if (student.login_attempts >= 3 || student.status == 'Blocked') {
               statusClass = 'status-blocked';
               statusText = 'Blocked';
           } else if (student.status == 'Active') {
               statusClass = 'status-active';
               statusText = 'Active';
           } else {
               statusClass = 'status-inactive';
               statusText = 'Inactive';
           }
           
           statusBadge.className = `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border mt-2 ${statusClass}`;
           statusBadge.textContent = statusText;
       }

       // Load student documents
       function loadStudentDocuments(studentId, searchTerm = '') {
           const formData = new FormData();
           formData.append('action', 'get_documents');
           formData.append('student_id', studentId);
           if (searchTerm) {
               formData.append('search', searchTerm);
           }

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               if (data.success) {
                   updateDocumentStatistics(data.statistics);
                   displayRequirementsStatus(data);
                   displayDocuments(data.documents);
                   updateDeploymentButton(data.all_requirements_met);
               } else {
                   showToast('error', data.message);
               }
           })
           .catch(error => {
               console.error('Error:', error);
               showToast('error', 'Failed to load documents');
           });
       }

       function updateDocumentStatistics(stats) {
           document.getElementById('totalDocs').textContent = stats.total_submitted;
           document.getElementById('approvedDocs').textContent = stats.total_approved;
           document.getElementById('pendingDocs').textContent = stats.total_pending;

           // Update modal statistics
           document.getElementById('statsRequired').textContent = stats.total_required;
           document.getElementById('statsSubmitted').textContent = stats.total_submitted;
           document.getElementById('statsApproved').textContent = stats.total_approved;
           document.getElementById('statsPending').textContent = stats.total_pending;
           document.getElementById('statsRejected').textContent = stats.total_rejected;
       }

       function displayRequirementsStatus(data) {
           // Hide all alerts first
           document.getElementById('missingDocsAlert').classList.add('hidden');
           document.getElementById('pendingDocsAlert').classList.add('hidden');
           document.getElementById('rejectedDocsAlert').classList.add('hidden');
           document.getElementById('requirementsMetAlert').classList.add('hidden');

           // Show missing documents
           if (data.missing_documents && data.missing_documents.length > 0) {
               const missingList = document.getElementById('missingDocsList');
               missingList.innerHTML = '';
               data.missing_documents.forEach(doc => {
                   const li = document.createElement('li');
                   li.textContent = `• ${doc}`;
                   missingList.appendChild(li);
               });
               document.getElementById('missingDocsAlert').classList.remove('hidden');
           }

           // Show pending documents
           if (data.pending_documents && data.pending_documents.length > 0) {
               const pendingList = document.getElementById('pendingDocsList');
               pendingList.innerHTML = '';
               data.pending_documents.forEach(doc => {
                   const li = document.createElement('li');
                   li.textContent = `• ${doc}`;
                   pendingList.appendChild(li);
               });
               document.getElementById('pendingDocsAlert').classList.remove('hidden');
           }

           // Show rejected documents
           if (data.rejected_documents && data.rejected_documents.length > 0) {
               const rejectedList = document.getElementById('rejectedDocsList');
               rejectedList.innerHTML = '';
               data.rejected_documents.forEach(doc => {
                   const li = document.createElement('li');
                   li.textContent = `• ${doc}`;
                   rejectedList.appendChild(li);
               });
               document.getElementById('rejectedDocsAlert').classList.remove('hidden');
           }

           // Show success if all requirements met
           if (data.all_requirements_met) {
               document.getElementById('requirementsMetAlert').classList.remove('hidden');
           }
       }

       function displayDocuments(documents) {
           const container = document.getElementById('documentsContainer');
           const loading = document.getElementById('documentsLoading');
           const empty = document.getElementById('documentsEmpty');

           loading.classList.add('hidden');

           if (documents.length === 0) {
               container.innerHTML = '';
               empty.classList.remove('hidden');
               return;
           }

           empty.classList.add('hidden');
           container.innerHTML = '';

           documents.forEach(doc => {
               const docCard = createDocumentCard(doc);
               container.appendChild(docCard);
           });
       }

       function createDocumentCard(doc) {
           const card = document.createElement('div');
           card.className = 'document-card bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition-all';

           const statusClass = `status-${doc.status}`;
           const statusIcon = doc.status === 'approved' ? 'check-circle' : 
                             doc.status === 'rejected' ? 'times-circle' : 'clock';

           card.innerHTML = `
               <div class="flex items-start justify-between mb-3">
                   <div class="flex-1">
                       <h5 class="font-medium text-gray-900 truncate">${doc.document_name || doc.original_filename}</h5>
                       <p class="text-sm text-gray-600">${doc.student_number || ''} - ${doc.first_name} ${doc.last_name}</p>
                   </div>
                   <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium border ${statusClass}">
                       <i class="fas fa-${statusIcon} mr-1"></i>
                       ${doc.status.charAt(0).toUpperCase() + doc.status.slice(1)}
                   </span>
               </div>
               
               <div class="text-sm text-gray-600 mb-3">
                   <p>Submitted: ${new Date(doc.submitted_at).toLocaleDateString()}</p>
                   ${doc.reviewed_at ? `<p>Reviewed: ${new Date(doc.reviewed_at).toLocaleDateString()}</p>` : ''}
               </div>
               
               ${doc.feedback ? `
                   <div class="bg-gray-50 p-3 rounded text-sm text-gray-700 mb-3">
                       <strong>Feedback:</strong> ${doc.feedback}
                   </div>
               ` : ''}
               
               <div class="flex space-x-2">
                   <button onclick="viewDocument('${doc.file_path}')" 
                           class="flex-1 inline-flex items-center justify-center px-3 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50">
                       <i class="fas fa-eye mr-1"></i>
                       View
                   </button>
                   ${doc.status === 'pending' ? `
                       <button onclick="approveDocument(${doc.id}, '${doc.original_filename}', '${doc.first_name} ${doc.last_name}')" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-green-600 text-white rounded text-sm font-medium hover:bg-green-700">
                           <i class="fas fa-check mr-1"></i>
                           Approve
                       </button>
                       <button onclick="rejectDocument(${doc.id}, '${doc.original_filename}', '${doc.first_name} ${doc.last_name}')" 
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-red-600 text-white rounded text-sm font-medium hover:bg-red-700">
                           <i class="fas fa-times mr-1"></i>
                           Reject
                       </button>
                   ` : ''}
               </div>
           `;

           return card;
       }

       function updateDeploymentButton(allRequirementsMet) {
           const button = document.getElementById('deployButton');
           if (allRequirementsMet) {
               button.disabled = false;
               button.className = 'flex-1 inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm bg-blue-600 text-sm font-medium text-white hover:bg-blue-700';
           } else {
               button.disabled = true;
               button.className = 'flex-1 inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm bg-gray-300 text-sm font-medium text-gray-500 cursor-not-allowed';
           }
       }

       // View student documents from modal
       function viewStudentDocumentsFromModal() {
           if (currentStudentId) {
               viewStudentDocuments(currentStudentId);
           }
       }

       function viewStudentDocuments(studentId) {
           currentStudentId = studentId;
           
           // Get student info for header
           const formData = new FormData();
           formData.append('action', 'get_details');
           formData.append('student_id', studentId);

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               if (data.success) {
                   const student = data.student;
                   const initials = student.first_name.charAt(0) + (student.last_name ? student.last_name.charAt(0) : '');
                   document.getElementById('documentsStudentInitials').textContent = initials.toUpperCase();
                   document.getElementById('documentsStudentName').textContent = `${student.first_name} ${student.last_name}`;
                   document.getElementById('documentsStudentID').textContent = student.student_id || 'Not assigned';
               }
           });

           loadStudentDocuments(studentId);
           document.getElementById('documentsModal').classList.remove('hidden');
       }

      function markReadyForDeployment() {
    if (!currentStudentId) return;

    // Get current student details and populate the modal
    const studentName = document.getElementById('studentName').textContent;
    const studentID = document.getElementById('studentID').textContent;
    const studentInitials = document.getElementById('studentInitials').textContent;

    // Populate deployment modal with student info
    document.getElementById('deployStudentName').textContent = studentName;
    document.getElementById('deployStudentID').textContent = studentID;
    document.getElementById('deployStudentInitials').textContent = studentInitials;

    // Load and display requirements summary
    loadDeploymentRequirementsSummary(currentStudentId);

    // Reset confirmation checkbox
    document.getElementById('deploymentConfirmCheck').checked = false;
    updateConfirmDeploymentButton();

    // Show the modal
    document.getElementById('deploymentConfirmModal').classList.remove('hidden');
}
function loadDeploymentRequirementsSummary(studentId) {
    const formData = new FormData();
    formData.append('action', 'get_documents');
    formData.append('student_id', studentId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayDeploymentRequirementsSummary(data);
        }
    })
    .catch(error => {
        console.error('Error loading requirements summary:', error);
    });
}

// New function to display requirements summary in deployment modal
function displayDeploymentRequirementsSummary(data) {
    const container = document.getElementById('deploymentRequirementsSummary');
    
    if (data.all_requirements_met) {
        container.innerHTML = `
            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-3"></i>
                    <div>
                        <h5 class="font-medium text-green-800">All Requirements Completed</h5>
                        <p class="text-sm text-green-700 mt-1">
                            Student has submitted and received approval for all ${data.statistics.total_required} required documents.
                        </p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-lg font-bold text-green-600">${data.statistics.total_required}</div>
                        <div class="text-xs text-green-600">Required</div>
                    </div>
                    <div>
                        <div class="text-lg font-bold text-green-600">${data.statistics.total_approved}</div>
                        <div class="text-xs text-green-600">Approved</div>
                    </div>
                    <div>
                        <div class="text-lg font-bold text-green-600">${data.statistics.approved_required}</div>
                        <div class="text-xs text-green-600">Required Approved</div>
                    </div>
                </div>
            </div>
        `;
    } else {
        let issuesHtml = '';
        
        if (data.missing_documents && data.missing_documents.length > 0) {
            issuesHtml += `
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-3">
                    <h6 class="font-medium text-red-800 mb-2">Missing Required Documents:</h6>
                    <ul class="text-sm text-red-700 space-y-1">
                        ${data.missing_documents.map(doc => `<li>• ${doc}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        if (data.pending_documents && data.pending_documents.length > 0) {
            issuesHtml += `
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-3">
                    <h6 class="font-medium text-yellow-800 mb-2">Documents Pending Review:</h6>
                    <ul class="text-sm text-yellow-700 space-y-1">
                        ${data.pending_documents.map(doc => `<li>• ${doc}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        if (data.rejected_documents && data.rejected_documents.length > 0) {
            issuesHtml += `
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mb-3">
                    <h6 class="font-medium text-orange-800 mb-2">Rejected Documents (Need Resubmission):</h6>
                    <ul class="text-sm text-orange-700 space-y-1">
                        ${data.rejected_documents.map(doc => `<li>• ${doc}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        container.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                    <div class="flex-1">
                        <h5 class="font-medium text-red-800">Requirements Not Complete</h5>
                        <p class="text-sm text-red-700 mt-1">This student cannot be deployed yet due to incomplete requirements.</p>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                ${issuesHtml}
            </div>
        `;
    }
}

// New function to handle confirmation checkbox
function updateConfirmDeploymentButton() {
    const checkbox = document.getElementById('deploymentConfirmCheck');
    const button = document.getElementById('confirmDeploymentBtn');
    
    if (checkbox.checked) {
        button.disabled = false;
        button.className = 'flex-1 px-4 py-2 rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700';
    } else {
        button.disabled = true;
        button.className = 'flex-1 px-4 py-2 rounded-md text-sm font-medium text-white bg-gray-300 cursor-not-allowed';
    }
}

// New function to confirm deployment
function confirmDeployment() {
    if (!currentStudentId) return;

    const formData = new FormData();
    formData.append('action', 'ready_for_deployment');
    formData.append('student_id', currentStudentId);

    // Show loading state
    const button = document.getElementById('confirmDeploymentBtn');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner animate-spin mr-2"></i>Processing...';
    button.disabled = true;

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message in the modal first
            const container = document.getElementById('deploymentRequirementsSummary');
            container.innerHTML = `
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-3"></i>
                        <div>
                            <h5 class="font-medium text-green-800">Deployment Successful!</h5>
                            <p class="text-sm text-green-700 mt-1">${data.message}</p>
                        </div>
                    </div>
                </div>
            `;
            
            showToast('success', data.message);
            
            setTimeout(() => {
                closeModal('deploymentConfirmModal');
                if (data.reload) {
                    window.location.reload();
                } else {
                    closeModal('studentModal');
                }
            }, 2000);
        } else {
            button.innerHTML = originalText;
            button.disabled = false;
            updateConfirmDeploymentButton();
            showToast('error', data.message);
        }
    })
    .catch(error => {
        button.innerHTML = originalText;
        button.disabled = false;
        updateConfirmDeploymentButton();
        console.error('Error:', error);
        showToast('error', 'Failed to update deployment status');
    });
}


       // View all pending documents
       function viewAllPendingDocuments() {
           loadAllPendingDocuments();
           document.getElementById('allPendingDocsModal').classList.remove('hidden');
       }

       function loadAllPendingDocuments(searchTerm = '') {
           const loading = document.getElementById('pendingDocsLoading');
           const container = document.getElementById('pendingDocumentsContainer');
           const empty = document.getElementById('pendingDocsEmpty');

           loading.classList.remove('hidden');
           empty.classList.add('hidden');

           const formData = new FormData();
           formData.append('action', 'get_all_pending_documents');
           if (searchTerm) {
               formData.append('search', searchTerm);
           }

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               loading.classList.add('hidden');
               
               if (data.success) {
                   if (data.documents.length === 0) {
                       empty.classList.remove('hidden');
                       container.innerHTML = '';
                   } else {
                       container.innerHTML = '';
                       data.documents.forEach(doc => {
                           const docCard = createDocumentCard(doc);
                           container.appendChild(docCard);
                       });
                   }
               } else {
                   showToast('error', data.message);
               }
           })
           .catch(error => {
               loading.classList.add('hidden');
               console.error('Error:', error);
               showToast('error', 'Failed to load pending documents');
           });
       }

       function approveDocument(documentId, documentName, studentName) {
           currentDocumentId = documentId;
           currentAction = 'approve';
           
           document.getElementById('documentActionTitle').textContent = 'Approve Document';
           document.getElementById('actionDocumentName').textContent = documentName;
           document.getElementById('actionStudentName').textContent = studentName;
           document.getElementById('documentFeedback').value = '';
           
           const confirmBtn = document.getElementById('confirmDocumentAction');
           confirmBtn.className = 'flex-1 px-4 py-2 rounded-md text-sm font-medium text-white bg-green-600 hover:bg-green-700';
           confirmBtn.textContent = 'Approve Document';
           confirmBtn.onclick = confirmDocumentAction;
           
           document.getElementById('documentActionModal').classList.remove('hidden');
       }

       function rejectDocument(documentId, documentName, studentName) {
           currentDocumentId = documentId;
           currentAction = 'reject';
           
           document.getElementById('documentActionTitle').textContent = 'Reject Document';
           document.getElementById('actionDocumentName').textContent = documentName;
           document.getElementById('actionStudentName').textContent = studentName;
           document.getElementById('documentFeedback').value = '';
           
           const confirmBtn = document.getElementById('confirmDocumentAction');
           confirmBtn.className = 'flex-1 px-4 py-2 rounded-md text-sm font-medium text-white bg-red-600 hover:bg-red-700';
           confirmBtn.textContent = 'Reject Document';
           confirmBtn.onclick = confirmDocumentAction;
           
           document.getElementById('documentActionModal').classList.remove('hidden');
       }

       function confirmDocumentAction() {
           if (!currentDocumentId || !currentAction) return;

           const feedback = document.getElementById('documentFeedback').value;
           const formData = new FormData();
           formData.append('action', `${currentAction}_document`);
           formData.append('document_id', currentDocumentId);
           formData.append('feedback', feedback);

           fetch(window.location.href, {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               if (data.success) {
                   showToast('success', data.message);
                   closeModal('documentActionModal');
                   
                   // Refresh documents if we're in the documents modal
                   if (currentStudentId) {
                       loadStudentDocuments(currentStudentId);
                   }
                   
                   // Refresh pending documents if we're in that modal
                   const pendingModal = document.getElementById('allPendingDocsModal');
                   if (!pendingModal.classList.contains('hidden')) {
                       loadAllPendingDocuments();
                   }
               } else {
                   showToast('error', data.message);
               }
           })
           .catch(error => {
               console.error('Error:', error);
               showToast('error', 'Failed to update document status');
           });
       }

       function viewDocument(filePath) {
           if (filePath) {
               window.open(filePath, '_blank');
           } else {
               showToast('error', 'Document file not found');
           }
       }

       // Modal management
       function closeModal(modalId) {
           document.getElementById(modalId).classList.add('hidden');
           
           // Reset current values when closing modals
           if (modalId === 'studentModal' || modalId === 'documentsModal') {
               currentStudentId = null;
           }
           if (modalId === 'documentActionModal') {
               currentDocumentId = null;
               currentAction = null;
           }
       }

       // Toast notifications
       function showToast(type, message) {
           const toast = document.getElementById(`${type}Toast`);
           const messageEl = document.getElementById(`${type}Message`);
           
           messageEl.textContent = message;
           toast.classList.remove('hidden');
           
           setTimeout(() => {
               toast.classList.add('hidden');
           }, 5000);
       }

       // Logout confirmation
       function confirmLogout() {
           return confirm('Are you sure you want to logout?');
       }

       // Close modals when clicking outside
       window.addEventListener('click', function(event) {
    const modals = ['studentModal', 'documentsModal', 'allPendingDocsModal', 'documentActionModal', 'deploymentConfirmModal'];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            closeModal(modalId);
        }
    });
});

       // Keyboard shortcuts
       document.addEventListener('keydown', function(event) {
    // ESC to close modals
    if (event.key === 'Escape') {
        const modals = ['studentModal', 'documentsModal', 'allPendingDocsModal', 'documentActionModal', 'deploymentConfirmModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (!modal.classList.contains('hidden')) {
                closeModal(modalId);
            }
        });
    }
});
       // Auto-refresh functionality (optional - uncomment if needed)
       /*
       setInterval(function() {
           // Auto-refresh pending documents count every 5 minutes
           if (document.visibilityState === 'visible') {
               location.reload();
           }
       }, 300000); // 5 minutes
       */

       // Initialize page
       document.addEventListener('DOMContentLoaded', function() {
           // Any initialization code can go here
           const deploymentCheckbox = document.getElementById('deploymentConfirmCheck');
    if (deploymentCheckbox) {
        deploymentCheckbox.addEventListener('change', updateConfirmDeploymentButton);
    }
    
    console.log('Student Management System initialized');
       });
   </script>
</body>
</html>