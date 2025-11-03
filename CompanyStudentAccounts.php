<?php
include('connect.php');
session_start();

require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if user is logged in and is a company supervisor
if (!isset($_SESSION['supervisor_id']) || $_SESSION['user_type'] !== 'supervisor') {
    header("Location: login.php");
    exit;
}

// Get supervisor information
$supervisor_id = $_SESSION['supervisor_id'];

// Fetch supervisor data including company name
$supervisor_query = "SELECT * FROM company_supervisors WHERE supervisor_id = ?";
$supervisor_stmt = mysqli_prepare($conn, $supervisor_query);
mysqli_stmt_bind_param($supervisor_stmt, "i", $supervisor_id);
mysqli_stmt_execute($supervisor_stmt);
$supervisor_result = mysqli_stmt_get_result($supervisor_stmt);
$supervisor = mysqli_fetch_assoc($supervisor_result);

$supervisor_name = $supervisor['full_name'];
$company_name = $supervisor['company_name'];
$profile_picture = $supervisor['profile_picture'] ?? '';

// Create initials
$name_parts = explode(' ', trim($supervisor['full_name']));
if (count($name_parts) >= 2) {
    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
} else {
    $initials = strtoupper(substr($supervisor['full_name'], 0, 2));
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    try {
        if ($action === 'get_details') {
            $student_id = (int)$_POST['student_id'];
            
            // Get student information
            $details_query = "SELECT s.*, sd.deployment_id, sd.company_name as deployed_company, sd.position
                              FROM students s 
                              LEFT JOIN student_deployments sd ON s.id = sd.student_id 
                              WHERE s.id = ?";
            $stmt = mysqli_prepare($conn, $details_query);
            mysqli_stmt_bind_param($stmt, "i", $student_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $student = mysqli_fetch_assoc($result);
            
            if ($student) {
                // Get academic adviser based on student's section
                $adviser_query = "SELECT name, email FROM academic_adviser 
                                 WHERE FIND_IN_SET(?, assigned_groups) > 0 
                                 AND status = 'active' 
                                 AND approval_status = 'approved'
                                 LIMIT 1";
                $adviser_stmt = mysqli_prepare($conn, $adviser_query);
                mysqli_stmt_bind_param($adviser_stmt, "s", $student['section']);
                mysqli_stmt_execute($adviser_stmt);
                $adviser_result = mysqli_stmt_get_result($adviser_stmt);
                $adviser = mysqli_fetch_assoc($adviser_result);
                
                // Add adviser info to student array
                if ($adviser) {
                    $student['adviser_name'] = $adviser['name'];
                    $student['adviser_email'] = $adviser['email'];
                }
                
                echo json_encode(['success' => true, 'student' => $student]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
            }
        } elseif ($action === 'approve_student') {
            $student_id = (int)$_POST['student_id'];
            
            // Get student information first
            $student_query = "SELECT s.* FROM students s WHERE s.id = ?";
            $stmt = mysqli_prepare($conn, $student_query);
            mysqli_stmt_bind_param($stmt, "i", $student_id);
            mysqli_stmt_execute($stmt);
            $student_result = mysqli_stmt_get_result($stmt);
            $student = mysqli_fetch_assoc($student_result);
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            
            // Get academic adviser based on student's section
            $adviser_query = "SELECT name, email FROM academic_adviser 
                             WHERE FIND_IN_SET(?, assigned_groups) > 0 
                             AND status = 'active' 
                             AND approval_status = 'approved'
                             LIMIT 1";
            $adviser_stmt = mysqli_prepare($conn, $adviser_query);
            mysqli_stmt_bind_param($adviser_stmt, "s", $student['section']);
            mysqli_stmt_execute($adviser_stmt);
            $adviser_result = mysqli_stmt_get_result($adviser_stmt);
            $adviser = mysqli_fetch_assoc($adviser_result);
            
            // Add adviser info to student array
            if ($adviser) {
                $student['adviser_name'] = $adviser['name'];
                $student['adviser_email'] = $adviser['email'];
            }
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            
            $emailsSent = [];
            $emailErrors = [];
            
            // ============= SEND TO STUDENT =============
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'ojttracker2@gmail.com';
                $mail->Password = 'rxtj qlze uomg xzqj';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;
                $mail->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
                $mail->addAddress($student['email']);
                $mail->isHTML(true);
                $mail->Subject = 'OJT Application Approved - ' . $company_name;
                $mail->Body = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                        <p style="color: #666; margin: 5px 0;">OJT Application Status</p>
                    </div>
                    
                    <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                        <h2 style="color: #155724; margin: 0 0 10px 0;">Congratulations! Your Application has been Approved</h2>
                    </div>
                    
                    <p style="color: #555; line-height: 1.6;">
                        Dear <strong>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</strong>,
                    </p>
                    
                    <p style="color: #555; line-height: 1.6;">
                        We are pleased to inform you that <strong>' . htmlspecialchars($company_name) . '</strong> has approved your OJT application!
                    </p>
                    
                    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                        <h3 style="color: #800000; margin: 0 0 15px 0;">Company Details:</h3>
                        <ul style="color: #555; line-height: 1.8;">
                            <li><strong>Company Name:</strong> ' . htmlspecialchars($company_name) . '</li>
                            <li><strong>Supervisor:</strong> ' . htmlspecialchars($supervisor_name) . '</li>
                            <li><strong>Your Student ID:</strong> ' . htmlspecialchars($student['student_id']) . '</li>
                            <li><strong>Department:</strong> ' . htmlspecialchars($student['department']) . '</li>
                        </ul>
                    </div>
                    
                    <p style="color: #555; line-height: 1.6;">
                        Please wait for further instructions from your academic adviser regarding the next steps for your OJT deployment.
                    </p>
                    
                    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <p style="color: #666; margin: 0;">
                            <strong>OnTheJob Tracker Team</strong><br>
                            <small>AI-Powered OJT Performance Monitoring</small>
                        </p>
                    </div>
                </div>';
                
                $mail->send();
                $emailsSent[] = 'Student (' . $student['email'] . ')';
            } catch (Exception $e) {
                $emailErrors[] = 'Student email failed: ' . $e->getMessage();
            }
            
            // ============= SEND TO ADVISER =============
            if (!empty($student['adviser_email']) && filter_var($student['adviser_email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    // Create completely NEW instance for adviser
                    $mail2 = new PHPMailer(true);
                    $mail2->isSMTP();
                    $mail2->Host = 'smtp.gmail.com';
                    $mail2->SMTPAuth = true;
                    $mail2->Username = 'ojttracker2@gmail.com';
                    $mail2->Password = 'rxtj qlze uomg xzqj';
                    $mail2->SMTPSecure = 'ssl';
                    $mail2->Port = 465;
                    $mail2->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
                    $mail2->addAddress($student['adviser_email']);
                    $mail2->isHTML(true);
                    $mail2->Subject = 'Student OJT Application Approved - ' . $student['first_name'] . ' ' . $student['last_name'];
                    $mail2->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                            <p style="color: #666; margin: 5px 0;">Student OJT Application Update</p>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            Dear <strong>' . htmlspecialchars($student['adviser_name']) . '</strong>,
                        </p>
                        
                        <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                            <h3 style="color: #155724; margin: 0 0 10px 0;">Student Application Approved</h3>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            We are pleased to inform you that one of your students has been approved for OJT placement.
                        </p>
                        
                        <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h3 style="color: #800000; margin: 0 0 15px 0;">Student Information:</h3>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Student Name:</strong> ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</li>
                                <li><strong>Student ID:</strong> ' . htmlspecialchars($student['student_id']) . '</li>
                                <li><strong>Department:</strong> ' . htmlspecialchars($student['department']) . '</li>
                                <li><strong>Program:</strong> ' . htmlspecialchars($student['program']) . '</li>
                                <li><strong>Company:</strong> ' . htmlspecialchars($company_name) . '</li>
                                <li><strong>Supervisor:</strong> ' . htmlspecialchars($supervisor_name) . '</li>
                            </ul>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            Please proceed with the deployment process and provide further instructions to the student.
                        </p>
                        
                        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <p style="color: #666; margin: 0;">
                                <strong>OnTheJob Tracker Team</strong><br>
                                <small>AI-Powered OJT Performance Monitoring</small>
                            </p>
                        </div>
                    </div>';
                    
                    $mail2->send();
                    $emailsSent[] = 'Adviser (' . $student['adviser_email'] . ')';
                } catch (Exception $e) {
                    $emailErrors[] = 'Adviser email failed: ' . $e->getMessage();
                }
            } else {
                $emailErrors[] = 'Adviser email not available or invalid';
            }
            
            // Build response message
            if (count($emailsSent) > 0) {
                $message = 'Student approved successfully. Emails sent to: ' . implode(', ', $emailsSent);
                if (!empty($emailErrors)) {
                    $message .= '. Issues: ' . implode(', ', $emailErrors);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Approval processed but emails failed: ' . implode(', ', $emailErrors)]);
            }
            
        } elseif ($action === 'reject_student') {
            $student_id = (int)$_POST['student_id'];
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            
            if (empty($reject_reason)) {
                echo json_encode(['success' => false, 'message' => 'Please provide a reason for rejection']);
                exit;
            }
            
            // Get student information first
            $student_query = "SELECT s.* FROM students s WHERE s.id = ?";
            $stmt = mysqli_prepare($conn, $student_query);
            mysqli_stmt_bind_param($stmt, "i", $student_id);
            mysqli_stmt_execute($stmt);
            $student_result = mysqli_stmt_get_result($stmt);
            $student = mysqli_fetch_assoc($student_result);
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            
            // Get academic adviser based on student's section
            $adviser_query = "SELECT name, email FROM academic_adviser 
                             WHERE FIND_IN_SET(?, assigned_groups) > 0 
                             AND status = 'active' 
                             AND approval_status = 'approved'
                             LIMIT 1";
            $adviser_stmt = mysqli_prepare($conn, $adviser_query);
            mysqli_stmt_bind_param($adviser_stmt, "s", $student['section']);
            mysqli_stmt_execute($adviser_stmt);
            $adviser_result = mysqli_stmt_get_result($adviser_stmt);
            $adviser = mysqli_fetch_assoc($adviser_result);
            
            // Add adviser info to student array
            if ($adviser) {
                $student['adviser_name'] = $adviser['name'];
                $student['adviser_email'] = $adviser['email'];
            }
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found']);
                exit;
            }
            
            $emailsSent = [];
            $emailErrors = [];
            
            // ============= SEND TO STUDENT =============
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'ojttracker2@gmail.com';
                $mail->Password = 'rxtj qlze uomg xzqj';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;
                $mail->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
                $mail->addAddress($student['email']);
                $mail->isHTML(true);
                $mail->Subject = 'OJT Application Status - ' . $company_name;
                $mail->Body = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                        <p style="color: #666; margin: 5px 0;">OJT Application Status</p>
                    </div>
                    
                    <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                        <h2 style="color: #721c24; margin: 0 0 10px 0;">Application Update</h2>
                    </div>
                    
                    <p style="color: #555; line-height: 1.6;">
                        Dear <strong>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</strong>,
                    </p>
                    
                    <p style="color: #555; line-height: 1.6;">
                        Thank you for your interest in pursuing your OJT at <strong>' . htmlspecialchars($company_name) . '</strong>.
                    </p>
                    
                    <p style="color: #555; line-height: 1.6;">
                        After careful consideration, we regret to inform you that we are unable to accommodate your application at this time.
                    </p>
                    
                    <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; margin: 20px 0;">
                        <p style="margin: 0; color: #856404;"><strong>Reason:</strong></p>
                        <p style="margin: 10px 0 0 0; color: #856404;">' . nl2br(htmlspecialchars($reject_reason)) . '</p>
                    </div>
                    
                    <p style="color: #555; line-height: 1.6;">
                        We encourage you to explore other opportunities. Please contact your academic adviser for guidance on alternative OJT placements.
                    </p>
                    
                    <p style="color: #555; line-height: 1.6;">
                        We wish you the best in your OJT journey and future endeavors.
                    </p>
                    
                    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <p style="color: #666; margin: 0;">
                            <strong>OnTheJob Tracker Team</strong><br>
                            <small>AI-Powered OJT Performance Monitoring</small>
                        </p>
                    </div>
                </div>';
                
                $mail->send();
                $emailsSent[] = 'Student (' . $student['email'] . ')';
            } catch (Exception $e) {
                $emailErrors[] = 'Student email failed: ' . $e->getMessage();
            }
            
            // ============= SEND TO ADVISER =============
            if (!empty($student['adviser_email']) && filter_var($student['adviser_email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    // Create completely NEW instance for adviser
                    $mail2 = new PHPMailer(true);
                    $mail2->isSMTP();
                    $mail2->Host = 'smtp.gmail.com';
                    $mail2->SMTPAuth = true;
                    $mail2->Username = 'ojttracker2@gmail.com';
                    $mail2->Password = 'rxtj qlze uomg xzqj';
                    $mail2->SMTPSecure = 'ssl';
                    $mail2->Port = 465;
                    $mail2->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker');
                    $mail2->addAddress($student['adviser_email']);
                    $mail2->isHTML(true);
                    $mail2->Subject = 'Student OJT Application Update - ' . $student['first_name'] . ' ' . $student['last_name'];
                    $mail2->Body = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1 style="color: #800000; margin: 0;">OnTheJob Tracker</h1>
                            <p style="color: #666; margin: 5px 0;">Student OJT Application Update</p>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            Dear <strong>' . htmlspecialchars($student['adviser_name']) . '</strong>,
                        </p>
                        
                        <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                            <h3 style="color: #721c24; margin: 0 0 10px 0;">Application Not Approved</h3>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            This is to inform you that the following student\'s OJT application was not approved by the company.
                        </p>
                        
                        <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h3 style="color: #800000; margin: 0 0 15px 0;">Student Information:</h3>
                            <ul style="color: #555; line-height: 1.8;">
                                <li><strong>Student Name:</strong> ' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</li>
                                <li><strong>Student ID:</strong> ' . htmlspecialchars($student['student_id']) . '</li>
                                <li><strong>Department:</strong> ' . htmlspecialchars($student['department']) . '</li>
                                <li><strong>Program:</strong> ' . htmlspecialchars($student['program']) . '</li>
                                <li><strong>Company:</strong> ' . htmlspecialchars($company_name) . '</li>
                            </ul>
                        </div>
                        
                        <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; margin: 20px 0;">
                            <p style="margin: 0; color: #856404;"><strong>Company\'s Feedback:</strong></p>
                            <p style="margin: 10px 0 0 0; color: #856404;">' . nl2br(htmlspecialchars($reject_reason)) . '</p>
                        </div>
                        
                        <p style="color: #555; line-height: 1.6;">
                            Please assist the student in finding an alternative OJT placement.
                        </p>
                        
                        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <p style="color: #666; margin: 0;">
                                <strong>OnTheJob Tracker Team</strong><br>
                                <small>AI-Powered OJT Performance Monitoring</small>
                            </p>
                        </div>
                    </div>';
                    
                    $mail2->send();
                    $emailsSent[] = 'Adviser (' . $student['adviser_email'] . ')';
                } catch (Exception $e) {
                    $emailErrors[] = 'Adviser email failed: ' . $e->getMessage();
                }
            } else {
                $emailErrors[] = 'Adviser email not available or invalid';
            }
            
            // Build response message
            if (count($emailsSent) > 0) {
                $message = 'Rejection notification sent successfully to: ' . implode(', ', $emailsSent);
                if (!empty($emailErrors)) {
                    $message .= '. Issues: ' . implode(', ', $emailErrors);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Rejection processed but emails failed: ' . implode(', ', $emailErrors)]);
            }
            
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';

// Pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build WHERE conditions
$where_conditions = ["s.company_name = ?"];
$param_types = "s";
$param_values = [$company_name];

if (!empty($search)) {
    $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.student_id LIKE ?)";
    $param_types .= "ssss";
    $search_term = "%$search%";
    $param_values[] = $search_term;
    $param_values[] = $search_term;
    $param_values[] = $search_term;
    $param_values[] = $search_term;
}

if (!empty($department_filter)) {
    $where_conditions[] = "s.department = ?";
    $param_types .= "s";
    $param_values[] = $department_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'ready') {
        $where_conditions[] = "s.ready_for_deployment = 1 AND sd.deployment_id IS NULL";
    } elseif ($status_filter === 'deployed') {
        $where_conditions[] = "sd.deployment_id IS NOT NULL";
    } elseif ($status_filter === 'active') {
        $where_conditions[] = "sd.deployment_id IS NOT NULL AND sd.ojt_status = 'Active'";
    } elseif ($status_filter === 'completed') {
        $where_conditions[] = "sd.deployment_id IS NOT NULL AND sd.ojt_status = 'Completed'";
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get statistics
$ready_query = "SELECT COUNT(*) as total FROM students s 
                WHERE s.company_name = ? AND s.ready_for_deployment = 1 
                AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE student_id IS NOT NULL)";
$ready_stmt = mysqli_prepare($conn, $ready_query);
mysqli_stmt_bind_param($ready_stmt, "s", $company_name);
mysqli_stmt_execute($ready_stmt);
$ready_count = mysqli_fetch_assoc(mysqli_stmt_get_result($ready_stmt))['total'];

$deployed_query = "SELECT COUNT(DISTINCT s.id) as total FROM students s 
                   INNER JOIN student_deployments sd ON s.id = sd.student_id 
                   WHERE sd.supervisor_id = ? AND sd.ojt_status = 'Active'";
$deployed_stmt = mysqli_prepare($conn, $deployed_query);
mysqli_stmt_bind_param($deployed_stmt, "i", $supervisor_id);
mysqli_stmt_execute($deployed_stmt);
$deployed_count = mysqli_fetch_assoc(mysqli_stmt_get_result($deployed_stmt))['total'];

$completed_query = "SELECT COUNT(DISTINCT s.id) as total FROM students s 
                    INNER JOIN student_deployments sd ON s.id = sd.student_id 
                    WHERE sd.supervisor_id = ? AND sd.ojt_status = 'Completed'";
$completed_stmt = mysqli_prepare($conn, $completed_query);
mysqli_stmt_bind_param($completed_stmt, "i", $supervisor_id);
mysqli_stmt_execute($completed_stmt);
$completed_count = mysqli_fetch_assoc(mysqli_stmt_get_result($completed_stmt))['total'];

$total_students = $ready_count + $deployed_count + $completed_count;

$dept_query = "SELECT DISTINCT s.department FROM students s 
               LEFT JOIN student_deployments sd ON s.id = sd.student_id
               WHERE s.company_name = ? AND s.department IS NOT NULL 
               ORDER BY s.department";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, "s", $company_name);
mysqli_stmt_execute($dept_stmt);
$departments_result = mysqli_stmt_get_result($dept_stmt);

$count_query = "SELECT COUNT(DISTINCT s.id) as total FROM students s 
                LEFT JOIN student_deployments sd ON s.id = sd.student_id 
                WHERE $where_clause";
$count_stmt = mysqli_prepare($conn, $count_query);
mysqli_stmt_bind_param($count_stmt, $param_types, ...$param_values);
mysqli_stmt_execute($count_stmt);
$total_records = mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'];
$total_pages = ceil($total_records / $records_per_page);

$students_query = "SELECT DISTINCT
    s.id, s.student_id, s.first_name, s.middle_name, s.last_name,
    s.email, s.contact_number, s.department, s.program, 
    s.year_level, s.section, s.ready_for_deployment,
    sd.deployment_id, sd.position, sd.start_date, sd.end_date,
    sd.required_hours, sd.completed_hours, sd.ojt_status,
    sd.supervisor_id
    FROM students s
    LEFT JOIN student_deployments sd ON s.id = sd.student_id AND sd.supervisor_id = ?
    WHERE $where_clause
    ORDER BY 
        CASE 
            WHEN s.ready_for_deployment = 1 AND sd.deployment_id IS NULL THEN 1
            WHEN sd.ojt_status = 'Active' THEN 2
            WHEN sd.ojt_status = 'Completed' THEN 3
            ELSE 4
        END,
        s.created_at DESC
    LIMIT ? OFFSET ?";

$students_param_types = "i" . $param_types . "ii";
$students_param_values = array_merge([$supervisor_id], $param_values, [$records_per_page, $offset]);

$students_stmt = mysqli_prepare($conn, $students_query);
mysqli_stmt_bind_param($students_stmt, $students_param_types, ...$students_param_values);
mysqli_stmt_execute($students_stmt);
$students_result = mysqli_stmt_get_result($students_stmt);

function getStudentStatusBadge($student) {
    if ($student['ready_for_deployment'] == 1 && $student['deployment_id'] === null) {
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                <i class="fas fa-rocket mr-1"></i>Ready for Deployment</span>';
    } elseif ($student['deployment_id'] !== null) {
        if ($student['ojt_status'] === 'Active') {
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                    <i class="fas fa-briefcase mr-1"></i>Currently Deployed</span>';
        } elseif ($student['ojt_status'] === 'Completed') {
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200">
                    <i class="fas fa-check-circle mr-1"></i>OJT Completed</span>';
        }
    }
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-200">
            <i class="fas fa-user mr-1"></i>Not Deployed</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Accounts</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'bulsu-maroon': '#800000',
                        'bulsu-dark-maroon': '#6B1028',
                        'bulsu-gold': '#DAA520',
                        'bulsu-light-gold': '#F4E4BC',
                        'bulsu-white': '#FFFFFF'
                    }
                }
            }
        }
    </script>
    <style>
        .sidebar { transition: transform 0.3s ease-in-out; }
        .student-row:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); }
        .modal { backdrop-filter: blur(4px); }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 sidebar">
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <img src="reqsample/bulsu12.png" alt="BULSU Logo" class="w-14 h-14 mr-2">
                <div class="flex items-center font-bold text-lg text-white">
                    <span>OnTheJob</span>
                    <span class="ml-1">Tracker</span>
                </div>
            </div>
        </div>
        
        <div class="px-4 py-6">
            <h2 class="text-xs font-semibold text-bulsu-light-gold uppercase tracking-wide mb-4">Navigation</h2>
            <nav class="space-y-2">
                <a href="CompanyDashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-th-large mr-3"></i>Dashboard
                </a>
                <a href="CompanyTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-tasks mr-3"></i>
                Tasks
            </a>
                 <a href="CompanyStudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                    <i class="fas fa-users mr-3 text-bulsu-gold"></i>Student Accounts
                </a>
               <a href="CompanyTimeRecord.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-clock mr-3"></i>
                Student Time Record
            </a>
             <a href="CompanyScheduleManager.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-calendar-alt mr-3"></i>
                    Schedule Manager
                </a>
            <a href="ApproveTasks.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-comment-dots mr-3"></i>
                Task Approval Management
            </a>
            <a href="CompanyProgressReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-chart-line mr-3"></i>
                Student Progress Report
            </a>
            <a href="StudentEvaluate.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
                Student Evaluation
            </a>
            <a href="CompanyMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Messages
            </a>
            </nav>
        </div>
        
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
            <div class="flex items-center space-x-3">
                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm overflow-hidden">
                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="w-full h-full object-cover">
                    <?php else: ?>
                        <?php echo $initials; ?>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($supervisor_name); ?></p>
                    <p class="text-xs text-bulsu-light-gold">Company Supervisor</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="lg:ml-64 min-h-screen">
        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 py-4">
                <button id="mobileMenuBtn" class="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <div class="flex-1 lg:ml-0 ml-4">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Accounts</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block"><?php echo htmlspecialchars($company_name); ?></p>
                </div>
                
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm overflow-hidden">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supervisor_name); ?></p>
                            <p class="text-sm text-gray-500">Company Supervisor</p>
                            <p class="text-xs text-gray-400"><?php echo htmlspecialchars($company_name); ?></p>
                        </div>
                        <a href="CompanyAccountSettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-cog mr-3"></i>Account Settings
                        </a>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-sign-out-alt mr-3"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6">
                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-blue-600 mb-1"><?php echo $total_students; ?></div>
                            <div class="text-sm text-gray-600">Total Students</div>
                        </div>
                        <div class="text-blue-500"><i class="fas fa-users text-2xl"></i></div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-green-600 mb-1"><?php echo $ready_count; ?></div>
                            <div class="text-sm text-gray-600">Ready for Deployment</div>
                        </div>
                        <div class="text-green-500"><i class="fas fa-rocket text-2xl"></i></div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-purple-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-purple-600 mb-1"><?php echo $deployed_count; ?></div>
                            <div class="text-sm text-gray-600">Currently Deployed</div>
                        </div>
                        <div class="text-purple-500"><i class="fas fa-briefcase text-2xl"></i></div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-indigo-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-3xl font-bold text-indigo-600 mb-1"><?php echo $completed_count; ?></div>
                            <div class="text-sm text-gray-600">Completed OJT</div>
                        </div>
                        <div class="text-indigo-500"><i class="fas fa-check-circle text-2xl"></i></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search Students</label>
                            <input type="text" id="searchInput" value="<?php echo htmlspecialchars($search); ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="Search by name, email, or ID...">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Department</label>
                            <select id="departmentFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Departments</option>
                                <?php while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                            <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Status</label>
                            <select id="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Status</option>
                                <option value="ready" <?php echo $status_filter === 'ready' ? 'selected' : ''; ?>>Ready for Deployment</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Currently Deployed</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>OJT Completed</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Student List</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?> students
                    </p>
                </div>

                <?php if (mysqli_num_rows($students_result) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Program</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                    <tr class="student-row hover:bg-gray-50 transition-all duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($student['student_id']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['department']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['program']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php echo getStudentStatusBadge($student); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="viewStudentDetails(<?php echo $student['id']; ?>)" 
                                                    class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200 transition-colors text-xs">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Students Found</h3>
                        <p class="text-gray-600">No students match your search criteria or are assigned to your company.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-6 flex justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($start_page > 1): ?>
                            <a href="?page=1&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                1
                            </a>
                            <?php if ($start_page > 2): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'; ?> text-sm font-medium">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                            <?php endif; ?>
                            <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <?php echo $total_pages; ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&department=<?php echo urlencode($department_filter); ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white">
            <div class="flex items-center justify-between pb-3 border-b">
                <h3 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-user-circle mr-2 text-blue-600"></i>Student Profile
                </h3>
                <button onclick="closeModal('studentModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="mt-6">
                <!-- Student Header Card -->
                <div class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg p-6 mb-6 border border-blue-200">
                    <div class="flex items-center space-x-4">
                        <div id="studentInitials" class="w-20 h-20 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-2xl shadow-lg">
                            --
                        </div>
                        <div class="flex-1">
                            <h4 id="studentName" class="text-2xl font-bold text-gray-900 mb-1">Loading...</h4>
                            <p id="studentEmail" class="text-gray-600 mb-2 flex items-center">
                                <i class="fas fa-envelope mr-2 text-blue-500"></i>
                                Loading...
                            </p>
                            <span id="studentStatusBadge" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium">
                                Loading...
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Student Information Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Left Column -->
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-id-card mr-2 text-blue-500"></i>Student ID
                            </label>
                            <p id="studentID" class="text-base text-gray-900">--</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-building mr-2 text-blue-500"></i>Department
                            </label>
                            <p id="studentDepartment" class="text-base text-gray-900">--</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-graduation-cap mr-2 text-blue-500"></i>Program
                            </label>
                            <p id="studentProgram" class="text-base text-gray-900">--</p>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="space-y-4">
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-calendar-alt mr-2 text-blue-500"></i>Year Level
                            </label>
                            <p id="studentYear" class="text-base text-gray-900">--</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-users mr-2 text-blue-500"></i>Section
                            </label>
                            <p id="studentSection" class="text-base text-gray-900">--</p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <label class="flex items-center text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-check-circle mr-2 text-blue-500"></i>Verification Status
                            </label>
                            <p id="verifiedStatus" class="text-base text-gray-900">--</p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
<!-- Action Buttons (Only show for Ready for Deployment students) -->
<div id="actionButtons" class="flex flex-col sm:flex-row gap-3 justify-end mt-6 pt-6 border-t border-gray-200">
    <!-- Buttons will be populated dynamically -->
</div>
            </div>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
            <div class="flex items-center justify-between pb-3 border-b">
                <h3 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-exclamation-triangle mr-2 text-red-600"></i>Reject Student Application
                </h3>
                <button onclick="closeModal('rejectModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="mt-6">
                <p class="text-gray-700 mb-4">Please provide a reason for rejecting this student's OJT application. This will be sent to the student and their academic adviser.</p>
                
                <label class="block text-sm font-medium text-gray-700 mb-2">Rejection Reason <span class="text-red-500">*</span></label>
                <textarea id="rejectReason" rows="5" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500" 
                    placeholder="e.g., The available positions have been filled, or the student's qualifications do not match our current requirements..."></textarea>
                
                <div class="flex flex-col sm:flex-row gap-3 justify-end mt-6">
                    <button onclick="closeModal('rejectModal')" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-semibold transition-colors">
                        Cancel
                    </button>
                    <button onclick="confirmReject()" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold transition-colors">
                        <i class="fas fa-paper-plane mr-2"></i>Send Rejection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Approval Modal -->
<div id="confirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden modal">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
        <div class="flex items-center justify-between pb-3 border-b">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-check mr-2 text-green-600"></i>Confirm Student Approval
            </h3>
            <button onclick="closeModal('confirmModal')" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="mt-6">
            <p class="text-gray-700 mb-4">Are you sure you want to approve this student for OJT at your company? An email notification will be sent to the student and their academic adviser.</p>
            
            <div class="flex flex-col sm:flex-row gap-3 justify-end mt-6">
                <button onclick="closeModal('confirmModal')" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-semibold transition-colors">
                    Cancel
                </button>
                <button onclick="confirmApproval()" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition-colors">
                    <i class="fas fa-check-circle mr-2"></i>OK
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

    <script>
        let currentStudentId = null;

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        });

        closeSidebar.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });

        // Profile dropdown
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Filter functionality
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const departmentFilter = document.getElementById('departmentFilter');
        const statusFilter = document.getElementById('statusFilter');

        function applyFilters() {
            const search = searchInput.value;
            const department = departmentFilter.value;
            const status = statusFilter.value;
            
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (department) params.append('department', department);
            if (status) params.append('status', status);
            params.append('page', '1');
            
            window.location.href = '?' + params.toString();
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500);
        });

        departmentFilter.addEventListener('change', applyFilters);
        statusFilter.addEventListener('change', applyFilters);

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
    document.getElementById('studentEmail').innerHTML = `<i class="fas fa-envelope mr-2 text-blue-500"></i>${student.email}`;
    document.getElementById('studentID').textContent = student.student_id || 'Not assigned';
    document.getElementById('studentDepartment').textContent = student.department || 'Not assigned';
    document.getElementById('studentProgram').textContent = student.program || 'Not assigned';
    document.getElementById('studentYear').textContent = student.year_level || 'Not assigned';
    document.getElementById('studentSection').textContent = student.section || 'Not assigned';
    
    // Enhanced verification status with icon
    const verifiedStatusEl = document.getElementById('verifiedStatus');
    if (student.verified == 1) {
        verifiedStatusEl.innerHTML = '<span class="inline-flex items-center text-green-600"><i class="fas fa-check-circle mr-2"></i>Verified</span>';
    } else {
        verifiedStatusEl.innerHTML = '<span class="inline-flex items-center text-yellow-600"><i class="fas fa-clock mr-2"></i>Unverified</span>';
    }

    // Set status badge with improved styling
    const statusBadge = document.getElementById('studentStatusBadge');
    let statusClass = '';
    let statusText = '';
    let statusIcon = '';
    
    if (student.verified == 0) {
        statusClass = 'bg-yellow-100 text-yellow-800 border border-yellow-300';
        statusText = 'Unverified';
        statusIcon = 'fas fa-clock';
    } else if (student.login_attempts >= 3 || student.status == 'Blocked') {
        statusClass = 'bg-red-100 text-red-800 border border-red-300';
        statusText = 'Blocked';
        statusIcon = 'fas fa-ban';
    } else if (student.status == 'Active') {
        statusClass = 'bg-green-100 text-green-800 border border-green-300';
        statusText = 'Active';
        statusIcon = 'fas fa-check-circle';
    } else {
        statusClass = 'bg-gray-100 text-gray-800 border border-gray-300';
        statusText = 'Inactive';
        statusIcon = 'fas fa-minus-circle';
    }
    
    statusBadge.className = `inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${statusClass}`;
    statusBadge.innerHTML = `<i class="${statusIcon} mr-2"></i>${statusText}`;
    
    // Show approve/reject buttons ONLY if student is Ready for Deployment (not yet deployed)
    const actionButtons = document.getElementById('actionButtons');
    const isReadyForDeployment = student.ready_for_deployment == 1 && !student.deployment_id;
    
    if (isReadyForDeployment) {
        actionButtons.innerHTML = `
            <button onclick="approveStudent()" class="inline-flex items-center justify-center px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition-colors">
                <i class="fas fa-check-circle mr-2"></i>Approve Student
            </button>
            <button onclick="showRejectModal()" class="inline-flex items-center justify-center px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold transition-colors">
                <i class="fas fa-times-circle mr-2"></i>Reject Student
            </button>
        `;
    } else {
        actionButtons.innerHTML = `
            <div class="w-full text-center py-4 bg-gray-50 rounded-lg border border-gray-200">
                <p class="text-gray-600">
                    <i class="fas fa-info-circle mr-2"></i>
                    ${student.deployment_id ? 'This student is already deployed.' : 'This student is not yet ready for deployment.'}
                </p>
            </div>
        `;
    }
}
// Show confirmation modal
function showConfirmModal() {
    document.getElementById('confirmModal').classList.remove('hidden');
}

// Approve student
function confirmApproval() {
    if (!currentStudentId) {
        showToast('error', 'No student selected');
        return;
    }

    closeModal('confirmModal');

    const formData = new FormData();
    formData.append('action', 'approve_student');
    formData.append('student_id', currentStudentId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            closeModal('studentModal');
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            showToast('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('error', 'Failed to approve student');
    });
}

        // Approve student
        function approveStudent() {
            if (!currentStudentId) {
                showToast('error', 'No student selected');
                return;
            }

            showConfirmModal();


            const formData = new FormData();
            formData.append('action', 'approve_student');
            formData.append('student_id', currentStudentId);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                    closeModal('studentModal');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('error', 'Failed to approve student');
            });
        }

        // Show reject modal
        function showRejectModal() {
            if (!currentStudentId) {
                showToast('error', 'No student selected');
                return;
            }
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectModal').classList.remove('hidden');
        }

        // Confirm reject
        function confirmReject() {
            const reason = document.getElementById('rejectReason').value.trim();
            
            if (!reason) {
                showToast('error', 'Please provide a reason for rejection');
                return;
            }

            if (!confirm('Are you sure you want to reject this student? A notification email with your reason will be sent to the student and their academic adviser.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reject_student');
            formData.append('student_id', currentStudentId);
            formData.append('reject_reason', reason);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                    closeModal('rejectModal');
                    closeModal('studentModal');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('error', 'Failed to reject student');
            });
        }

        // Modal management
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            if (modalId === 'studentModal') {
                currentStudentId = null;
            }
        }

        // Toast notifications
        function showToast(type, message) {
            const toast = document.getElementById(`${type}Toast`);
            const messageEl = document.getElementById(`${type}Message`);
            
            if (toast && messageEl) {
                messageEl.textContent = message;
                toast.classList.remove('hidden');
                
                setTimeout(() => {
                    toast.classList.add('hidden');
                }, 5000);
            }
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const studentModal = document.getElementById('studentModal');
            const rejectModal = document.getElementById('rejectModal');
            
            if (event.target === studentModal) {
                closeModal('studentModal');
            }
            if (event.target === rejectModal) {
                closeModal('rejectModal');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal('studentModal');
                closeModal('rejectModal');
            }
        });
    </script>
</body>
</html>