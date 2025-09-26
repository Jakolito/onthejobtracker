<?php
include('connect.php');
session_start();
require './PHPMailer/PHPMailer/src/Exception.php';
require './PHPMailer/PHPMailer/src/PHPMailer.php';
require './PHPMailer/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in and is an adviser
if (!isset($_SESSION['adviser_id']) || $_SESSION['user_type'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

// Get adviser information
$adviser_id = $_SESSION['adviser_id'];
$adviser_name = $_SESSION['name'];
$name_parts = explode(' ', trim($adviser_name));

if (count($name_parts) >= 2) {
    $adviser_initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
} else {
    $adviser_initials = strtoupper(substr($adviser_name, 0, 2));
}

$unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

// Filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';
$section_filter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Initialize variables for success/error messages
$deployment_success = '';
$deployment_error = '';
$email_status = '';

// Process deployment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deploy_student'])) {
    $student_id = $_POST['student_id'];
    $supervisor_id = $_POST['supervisor_id'];
    $company_name = $_POST['company_name'];
    $company_address = $_POST['company_address'];
    $company_contact_number = $_POST['company_contact_number'];
    $supervisor_name = $_POST['supervisor_name'];
    $supervisor_position = $_POST['supervisor_position'];
    $supervisor_email = $_POST['supervisor_email'];
    $supervisor_phone = $_POST['supervisor_phone'];
    
    // Handle position - check if custom position is provided
    $position = '';
    if (isset($_POST['position']) && !empty($_POST['position'])) {
        if ($_POST['position'] === 'custom' && isset($_POST['custom_position']) && !empty($_POST['custom_position'])) {
            $position = $_POST['custom_position'];
        } else {
            $position = $_POST['position'];
        }
    } elseif (isset($_POST['custom_position']) && !empty($_POST['custom_position'])) {
        $position = $_POST['custom_position'];
    }
    
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $required_hours = $_POST['required_hours'];
    
$work_days = null;

// First check if work_days came from the form
if (isset($_POST['work_days']) && is_array($_POST['work_days']) && !empty($_POST['work_days'])) {
    $work_days = implode(',', $_POST['work_days']);
} else {
    // If not in form (because checkboxes were disabled), get from supervisor
    $get_supervisor_work_days = "SELECT work_days FROM company_supervisors WHERE supervisor_id = ?";
    $work_days_stmt = mysqli_prepare($conn, $get_supervisor_work_days);
    mysqli_stmt_bind_param($work_days_stmt, "i", $supervisor_id);
    mysqli_stmt_execute($work_days_stmt);
    $work_days_result = mysqli_stmt_get_result($work_days_stmt);
    
    if ($supervisor_work_data = mysqli_fetch_assoc($work_days_result)) {
        $work_days = $supervisor_work_data['work_days'];
    }
}
    try {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Validate student is available for deployment
        $validate_student = "SELECT id, ready_for_deployment, first_name, last_name, email, student_id, program, year_level FROM students WHERE id = ? AND verified = 1";
        $validate_stmt = mysqli_prepare($conn, $validate_student);
        mysqli_stmt_bind_param($validate_stmt, "i", $student_id);
        mysqli_stmt_execute($validate_stmt);
        $validate_result = mysqli_stmt_get_result($validate_stmt);
        $student_data = mysqli_fetch_assoc($validate_result);
        
        if (!$student_data) {
            throw new Exception("Student not found or not verified.");
        }
        
        // Get complete supervisor information from company_supervisors table
        $validate_supervisor = "SELECT 
            supervisor_id, students_needed, full_name, email, phone_number, position,
            company_name, company_address, company_contact_number, work_mode,
            work_schedule_start, work_schedule_end, work_days as supervisor_work_days,
            internship_duration, internship_start_date, internship_end_date, industry_field
            FROM company_supervisors 
            WHERE supervisor_id = ? AND account_status = 'Active' AND students_needed > 0";
        $validate_sup_stmt = mysqli_prepare($conn, $validate_supervisor);
        mysqli_stmt_bind_param($validate_sup_stmt, "i", $supervisor_id);
        mysqli_stmt_execute($validate_sup_stmt);
        $validate_sup_result = mysqli_stmt_get_result($validate_sup_stmt);
        $supervisor_data = mysqli_fetch_assoc($validate_sup_result);
        
        if (!$supervisor_data) {
            throw new Exception("Selected supervisor is no longer available or has no student slots remaining.");
        }
        
        // Check if student is already deployed
        $check_deployment = "SELECT deployment_id FROM student_deployments WHERE student_id = ? AND status = 'Active'";
        $check_stmt = mysqli_prepare($conn, $check_deployment);
        mysqli_stmt_bind_param($check_stmt, "i", $student_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            throw new Exception("Student is already deployed and active.");
        }
        
        // Insert deployment record
$deploy_query = "INSERT INTO student_deployments (
        student_id, supervisor_id, company_name, company_address, company_contact,
        supervisor_name, supervisor_position, supervisor_email, supervisor_phone,
        position, start_date, end_date, required_hours, work_days, status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())";

    $deploy_stmt = mysqli_prepare($conn, $deploy_query);

// FIXED: Proper binding with work_days as string or null
 mysqli_stmt_bind_param($deploy_stmt, "iissssssssssss", 
        $student_id,           // integer
        $supervisor_id,        // integer  
        $company_name,         // string
        $company_address,      // string
        $company_contact_number, // string
        $supervisor_name,      // string
        $supervisor_position,  // string
        $supervisor_email,     // string
        $supervisor_phone,     // string
        $position,             // string
        $start_date,           // string (date)
        $end_date,             // string (date)  
        $required_hours,       // string (will be converted to int by MySQL)
        $work_days             // string or NULL
    );

    if (!mysqli_stmt_execute($deploy_stmt)) {
        throw new Exception("Failed to create deployment record: " . mysqli_error($conn));
    }

        // Decrease students_needed count for the supervisor
        $decrease_students_query = "UPDATE company_supervisors SET 
            students_needed = GREATEST(students_needed - 1, 0),
            updated_at = NOW()
            WHERE supervisor_id = ?";
        $decrease_stmt = mysqli_prepare($conn, $decrease_students_query);
        mysqli_stmt_bind_param($decrease_stmt, "i", $supervisor_id);

        if (!mysqli_stmt_execute($decrease_stmt)) {
            throw new Exception("Failed to update supervisor students needed count: " . mysqli_error($conn));
        }

        // Update student status to 'Deployed'
        $update_student_query = "UPDATE students SET 
            status = 'Deployed',
            updated_at = NOW()
            WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_student_query);
        mysqli_stmt_bind_param($update_stmt, "i", $student_id);

        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception("Failed to update student status: " . mysqli_error($conn));
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        $deployment_success = "Student successfully deployed!";
        // Create deployment notification for the student
require_once('notification_functions.php');
$deployment_notification_success = createDeploymentNotification(
    $conn, 
    $student_id, 
    $company_name, 
    $supervisor_name, 
    $start_date, 
    $end_date, 
    $position
);

if (!$deployment_notification_success) {
    error_log("Failed to create deployment notification for student ID: $student_id");
}

        // Send email notifications with enhanced supervisor data
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'ojttracker2@gmail.com';
            $mail->Password = 'rxtj qlze uomg xzqj';
            $mail->SMTPSecure = 'ssl';
            $mail->Port = 465;
            
            $student_email = $student_data['email'];
            
            // Format work schedule
            $work_schedule = '';
            if ($supervisor_data['work_schedule_start'] && $supervisor_data['work_schedule_end']) {
                $work_schedule = date('g:i A', strtotime($supervisor_data['work_schedule_start'])) . ' - ' . 
                               date('g:i A', strtotime($supervisor_data['work_schedule_end']));
            }
            
            // Use supervisor's work days if available, otherwise use the assigned work days
            $display_work_days = !empty($supervisor_data['supervisor_work_days']) ? 
                               $supervisor_data['supervisor_work_days'] : $work_days;
            
            // Email to Student - Enhanced with supervisor details
            if ($student_email) {
                $mail->setFrom('ojttracker2@gmail.com', 'OnTheJob Tracker - BulSU');
                $mail->addAddress($student_email, $student_data['first_name'] . ' ' . $student_data['last_name']);
                
                $mail->isHTML(true);
                $mail->Subject = 'OJT Deployment Notification - Bulacan State University';
                
                $studentEmailBody = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
                    
                    <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                        
                        <!-- BulSU Header -->
                        <div style="text-align: center; padding: 30px; background-color: #8B0000; color: white;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: bold;">
                                OnTheJob Tracker
                            </h1>
                            <p style="margin: 5px 0 0 0; font-size: 16px; color: #FFD700;">
                                Student OJT Performance Monitoring System
                            </p>
                        </div>
                        
                        <!-- Content -->
                        <div style="padding: 30px;">
                            <h2 style="color: #8B0000; margin: 0 0 20px 0; font-size: 20px;">
                                Congratulations, ' . htmlspecialchars($student_data['first_name']) . '!
                            </h2>
                            
                            <p style="color: #666; font-size: 16px; line-height: 1.6; margin: 0 0 25px 0;">
                                You have been successfully deployed for your On-the-Job Training! 
                                We wish you the best of luck in this exciting learning opportunity.
                            </p>
                            
                            <!-- Deployment Details -->
                            <div style="background-color: #FFF8DC; border-left: 4px solid #B8860B; padding: 20px; margin: 25px 0; border-radius: 4px;">
                                <h3 style="color: #8B0000; margin: 0 0 15px 0; font-size: 18px;">
                                    Your OJT Assignment Details:
                                </h3>
                                
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Student ID:</strong> ' . htmlspecialchars($student_data['student_id']) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Program:</strong> ' . htmlspecialchars($student_data['program']) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Company:</strong> ' . htmlspecialchars($company_name) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Company Address:</strong> ' . htmlspecialchars($company_address) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Position:</strong> ' . htmlspecialchars($position) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Industry Field:</strong> ' . htmlspecialchars($supervisor_data['industry_field']) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Mode:</strong> ' . htmlspecialchars($supervisor_data['work_mode']) . '</p>
                            </div>
                            
                            <!-- Supervisor Information -->
                            <div style="background-color: #E8F5E8; border-left: 4px solid #4CAF50; padding: 20px; margin: 25px 0; border-radius: 4px;">
                                <h3 style="color: #8B0000; margin: 0 0 15px 0; font-size: 18px;">
                                    Your Supervisor:
                                </h3>
                                
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Name:</strong> ' . htmlspecialchars($supervisor_name) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Position:</strong> ' . htmlspecialchars($supervisor_position) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Email:</strong> ' . htmlspecialchars($supervisor_email) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Phone:</strong> ' . htmlspecialchars($supervisor_phone) . '</p>
                            </div>
                            
                            <!-- Schedule Information -->
                            <div style="background-color: #F0F8FF; border-left: 4px solid #2196F3; padding: 20px; margin: 25px 0; border-radius: 4px;">
                                <h3 style="color: #8B0000; margin: 0 0 15px 0; font-size: 18px;">
                                    Schedule & Duration:
                                </h3>
                                
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Start Date:</strong> ' . date('F j, Y', strtotime($start_date)) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>End Date:</strong> ' . date('F j, Y', strtotime($end_date)) . '</p>
                                <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Required Hours:</strong> ' . htmlspecialchars($required_hours) . ' hours</p>';
                                
                if (!empty($display_work_days)) {
                    $studentEmailBody .= '<p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Days:</strong> ' . htmlspecialchars($display_work_days) . '</p>';
                }
                
                if (!empty($work_schedule)) {
                    $studentEmailBody .= '<p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Hours:</strong> ' . htmlspecialchars($work_schedule) . '</p>';
                }
                
                $studentEmailBody .= '
                            </div>
                            
                            <!-- Success Message -->
                            <div style="background-color: #E8F5E8; border: 1px solid #4CAF50; padding: 15px; margin: 20px 0; border-radius: 4px; text-align: center;">
                                <p style="margin: 0; color: #2E7D32; font-size: 16px; font-weight: bold;">
                                    🎉 Best of luck with your OJT journey! Make the most of this valuable learning experience.
                                </p>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div style="background-color: #2D3748; padding: 20px; text-align: center; color: white;">
                            <h4 style="margin: 0 0 5px 0; color: #FFD700; font-size: 16px;">
                                Bulacan State University
                            </h4>
                            <p style="margin: 0; color: #CBD5E0; font-size: 12px;">
                                OnTheJob Tracker - AI-Powered OJT Performance Monitoring
                            </p>
                        </div>
                    </div>
                </div>';
                
                $mail->Body = $studentEmailBody;
                $mail->send();
                
                // Clear recipients for supervisor email
                $mail->clearAddresses();
            }
            
            // Email to Supervisor - Enhanced with complete information
            $mail->addAddress($supervisor_email, $supervisor_name);
            $mail->Subject = 'New BulSU Intern Assignment - OnTheJob Tracker';
            
            $supervisorEmailBody = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
                
                <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                    
                    <!-- BulSU Header -->
                    <div style="text-align: center; padding: 30px; background-color: #8B0000; color: white;">
                        <h1 style="margin: 0; font-size: 24px; font-weight: bold;">
                            OnTheJob Tracker
                        </h1>
                        <p style="margin: 5px 0 0 0; font-size: 16px; color: #FFD700;">
                            Student OJT Performance Monitoring System
                        </p>
                    </div>
                    
                    <!-- Content -->
                    <div style="padding: 30px;">
                        <h2 style="color: #8B0000; margin: 0 0 20px 0; font-size: 20px;">
                            New Intern Assignment
                        </h2>
                        
                        <p style="color: #666; font-size: 16px; line-height: 1.6; margin: 0 0 25px 0;">
                            A BulSU student has been assigned to your company for their On-the-Job Training. 
                            Please review the student details below and welcome them to your team.
                        </p>
                        
                        <!-- Student Details -->
                        <div style="background-color: #F0FFF4; border-left: 4px solid #4CAF50; padding: 20px; margin: 25px 0; border-radius: 4px;">
                            <h3 style="color: #8B0000; margin: 0 0 15px 0; font-size: 18px;">
                                Student Information:
                            </h3>
                            
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Student ID:</strong> ' . htmlspecialchars($student_data['student_id']) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Name:</strong> ' . htmlspecialchars($student_data['first_name'] . ' ' . $student_data['last_name']) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Program:</strong> ' . htmlspecialchars($student_data['program']) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Year Level:</strong> ' . htmlspecialchars($student_data['year_level']) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Email:</strong> ' . htmlspecialchars($student_email) . '</p>
                        </div>
                        
                        <!-- Internship Details -->
                        <div style="background-color: #FFF8DC; border-left: 4px solid #B8860B; padding: 20px; margin: 25px 0; border-radius: 4px;">
                            <h3 style="color: #8B0000; margin: 0 0 15px 0; font-size: 18px;">
                                Internship Details:
                            </h3>
                            
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Position:</strong> ' . htmlspecialchars($position) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Start Date:</strong> ' . date('F j, Y', strtotime($start_date)) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>End Date:</strong> ' . date('F j, Y', strtotime($end_date)) . '</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Required Hours:</strong> ' . htmlspecialchars($required_hours) . ' hours</p>
                            <p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Mode:</strong> ' . htmlspecialchars($supervisor_data['work_mode']) . '</p>';
                            
            if (!empty($display_work_days)) {
                $supervisorEmailBody .= '<p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Days:</strong> ' . htmlspecialchars($display_work_days) . '</p>';
            }
            
            if (!empty($work_schedule)) {
                $supervisorEmailBody .= '<p style="margin: 8px 0; color: #333; font-size: 14px;"><strong>Work Hours:</strong> ' . htmlspecialchars($work_schedule) . '</p>';
            }
            
            $supervisorEmailBody .= '
                        </div>
                        
                        <!-- Welcome Message -->
                        <div style="background-color: #E3F2FD; border: 1px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px; text-align: center;">
                            <p style="margin: 0; color: #1976D2; font-size: 14px; font-weight: bold;">
                                Thank you for providing this valuable learning opportunity to our BulSU student!
                            </p>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div style="background-color: #2D3748; padding: 20px; text-align: center; color: white;">
                        <h4 style="margin: 0 0 5px 0; color: #FFD700; font-size: 16px;">
                            Bulacan State University
                        </h4>
                        <p style="margin: 0; color: #CBD5E0; font-size: 12px;">
                            OnTheJob Tracker - AI-Powered OJT Performance Monitoring
                        </p>
                    </div>
                </div>
            </div>';
            
            $mail->Body = $supervisorEmailBody;
            $mail->send();
            
            $email_status = "Deployment successful! Email notifications sent to both student and supervisor.";
            
        } catch (Exception $e) {
            $email_status = "Deployment successful, but email notification failed: " . $e->getMessage();
            error_log("Email notification error: " . $e->getMessage());
        }
        
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        $deployment_error = $e->getMessage();
        error_log("Deployment error: " . $e->getMessage());
    }
}

// Pagination settings
$records_per_page = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get statistics with filtering applied
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM students s 
     WHERE s.ready_for_deployment = 1 
     AND s.verified = 1 
     AND s.status != 'Deployed' 
     AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE status = 'Active')
    ) as ready_count,
    (SELECT COUNT(*) FROM student_deployments WHERE status = 'Active') as deployed_count";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get unique departments for filter dropdown
$departments_query = "SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' ORDER BY department";
$departments_result = mysqli_query($conn, $departments_query);

// Get unique sections for filter dropdown  
$sections_query = "SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section != '' ORDER BY section";
$sections_result = mysqli_query($conn, $sections_query);

// Build where conditions
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
    if ($status_filter === 'ready_for_deployment') {
        $where_conditions[] = "s.ready_for_deployment = 1 AND s.verified = 1 AND s.status != 'Deployed' AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE status = 'Active')";
    } elseif ($status_filter === 'deployed') {
        $where_conditions[] = "s.id IN (SELECT student_id FROM student_deployments WHERE status = 'Active')";
    } elseif ($status_filter === 'verified') {
        $where_conditions[] = "s.verified = 1 AND s.ready_for_deployment = 0 AND s.id NOT IN (SELECT student_id FROM student_deployments WHERE status = 'Active')";
    } elseif ($status_filter === 'unverified') {
        $where_conditions[] = "s.verified = 0";
    } elseif ($status_filter === 'blocked') {
        $where_conditions[] = "(s.status = 'Blocked' OR s.login_attempts >= 3)";
    } else {
        $where_conditions[] = "s.status = '$status_filter'";
    }
}

// Base condition: only show students that meet deployment criteria or are already deployed
$base_condition = "(
    (s.ready_for_deployment = 1 AND s.verified = 1 AND s.status != 'Deployed')
    OR 
    (s.status = 'Deployed' AND s.id IN (SELECT student_id FROM student_deployments WHERE status = 'Active'))
)";

// Combine base condition with filter conditions
if (count($where_conditions) > 0) {
    $where_clause = $base_condition . " AND (" . implode(' AND ', $where_conditions) . ")";
} else {
    $where_clause = $base_condition;
}

// Count query with filtering
$count_query = "SELECT COUNT(*) as total FROM students s 
LEFT JOIN student_deployments sd ON s.id = sd.student_id AND sd.status = 'Active'
WHERE $where_clause";

$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Main students query with filtering and enhanced supervisor data
$students_query = "SELECT 
    s.id, 
    s.first_name, 
    s.last_name, 
    s.student_id, 
    s.department, 
    s.program, 
    s.section, 
    s.status,
    s.ready_for_deployment,
    s.verified,
    s.year_level,
    s.email,
    s.login_attempts,
    s.created_at,
    s.last_login,
    sd.deployment_id,
    sd.company_name as deployed_company,
    sd.supervisor_name as deployed_supervisor,
    sd.position as deployed_position,
    sd.start_date,
    sd.end_date,
    sd.status as deployment_status,
    sd.company_address,
    sd.company_contact,
    sd.supervisor_position as deployed_supervisor_position,
    sd.supervisor_email,
    sd.supervisor_phone,
    sd.required_hours,
    sd.work_days,
    COALESCE(sd.completed_hours, 0) as completed_hours,
    sd.created_at as deployment_date,
    cs.work_schedule_start,
    cs.work_schedule_end,
    cs.work_mode,
    cs.industry_field,
    cs.work_days as supervisor_work_days, -- This was missing proper aliasing
    CASE 
        WHEN sd.work_days IS NOT NULL AND sd.work_days != '' THEN sd.work_days
        WHEN cs.work_days IS NOT NULL AND cs.work_days != '' THEN cs.work_days
        ELSE 'Monday,Tuesday,Wednesday,Thursday,Friday'
    END as display_work_days -- Use deployment work_days first, then supervisor work_days
FROM students s 
LEFT JOIN student_deployments sd ON s.id = sd.student_id AND sd.status = 'Active'
LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
WHERE $where_clause
ORDER BY 
    CASE WHEN s.status = 'Deployed' THEN 0 ELSE 1 END,
    sd.created_at DESC,
    s.last_name, 
    s.first_name
LIMIT $records_per_page OFFSET $offset";

$students_result = mysqli_query($conn, $students_query);

// Get supervisors data with complete information
$supervisors_query = "SELECT 
    supervisor_id, 
    full_name, 
    email, 
    phone_number, 
    position, 
    company_name, 
    company_address, 
    company_contact_number,
    students_needed,
    role_position,
    required_skills,
    work_days,
    work_schedule_start,
    work_schedule_end,
    work_mode,
    internship_duration,
    internship_start_date,
    internship_end_date,
    industry_field,
    account_status,
    created_at
FROM company_supervisors 
WHERE account_status = 'Active' AND students_needed > 0
ORDER BY company_name, full_name";
$supervisors_result = mysqli_query($conn, $supervisors_query);

// Helper function to get student status
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

// Helper function to format work schedule
function formatWorkSchedule($start_time, $end_time) {
    if ($start_time && $end_time) {
        return date('g:i A', strtotime($start_time)) . ' - ' . date('g:i A', strtotime($end_time));
    }
    return 'Not specified';
}

// Helper function to format work days
function formatWorkDays($work_days) {
    if (empty($work_days)) {
        return 'Not specified';
    }
    
    // If it's comma-separated, convert to readable format
    if (strpos($work_days, ',') !== false) {
        $days = explode(',', $work_days);
        return implode(', ', array_map('trim', $days));
    }
    
    return $work_days;
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Deployment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
        .sidebar { transition: transform 0.3s ease-in-out; }
        .sidebar-overlay { transition: opacity 0.3s ease-in-out; }
        .progress-fill { transition: width 2s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.3s ease; }
        .work-day-checkbox {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .work-day-checkbox:hover {
            border-color: #3b82f6;
            transform: translateY(-1px);
        }
        .work-day-checkbox.selected {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        .work-day-checkbox.disabled {
            background-color: #f3f4f6;
            border-color: #d1d5db;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .work-day-checkbox.disabled.selected {
            background-color: #10b981;
            border-color: #10b981;
            color: white;
            opacity: 0.8;
        }
        .work-day-checkbox input[type="checkbox"] {
            display: none;
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
            <a href="StudentAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-user-graduate mr-3"></i>
                Student Accounts
            </a>
            <a href="StudentDeployment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-paper-plane mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Deployment Management</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Deploy students to companies with auto-populated supervisor preferences</p>
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
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg animate-fade-in">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                        <p class="text-green-700"><?php echo $success_message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg animate-fade-in">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                        <p class="text-red-700"><?php echo $error_message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
           <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $stats['ready_count']; ?></div>
                <div class="text-sm text-gray-600">Ready for Deployment</div>
            </div>
            <div class="text-blue-500">
                <i class="fas fa-clock text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-green-500">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $stats['deployed_count']; ?></div>
                <div class="text-sm text-gray-600">Currently Deployed</div>
            </div>
            <div class="text-green-500">
                <i class="fas fa-check-circle text-2xl"></i>
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
    <option value="ready_for_deployment" <?php echo $status_filter === 'ready_for_deployment' ? 'selected' : ''; ?>>Ready for Deployment</option>
    <option value="deployed" <?php echo $status_filter === 'deployed' ? 'selected' : ''; ?>>Deployed</option>
</select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-lg shadow-sm border border-bulsu-maroon overflow-hidden">
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4">
        <h3 class="text-lg font-medium text-white flex items-center">
            <i class="fas fa-user-graduate mr-2 text-bulsu-gold"></i>
            Student Management
        </h3>
        <p class="text-bulsu-light-gold text-sm">
            Deploy students with auto-populated supervisor preferences
        </p>
    </div>
</div>

                
                <div class="overflow-x-auto">
                    <?php if (mysqli_num_rows($students_result) > 0): ?>
                        <table class="w-full" id="studentsTable">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company/Position</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                    <tr class="hover:bg-gray-50 transition-colors <?php echo $student['deployment_id'] ? 'bg-green-50 border-l-4 border-l-green-500' : ''; ?>" 
                                        data-status="<?php echo $student['deployment_id'] ? 'deployed' : 'ready'; ?>"
                                        data-department="<?php echo htmlspecialchars($student['department']); ?>"
                                        data-search="<?php echo strtolower($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['student_id']); ?>">
                                        
                                        <!-- Student Information -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        ID: <?php echo htmlspecialchars($student['student_id']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Program Details -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['program']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['department']); ?></div>
                                            <div class="text-sm text-gray-500">Section: <?php echo htmlspecialchars($student['section']); ?></div>
                                        </td>

                                        <!-- Deployment Status -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($student['deployment_id']): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    <i class="fas fa-check-circle mr-1"></i>
                                                    Deployed
                                                </span>
                                                <?php if ($student['deployment_date']): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        Since: <?php echo date('M j, Y', strtotime($student['deployment_date'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    Ready
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Company/Position -->
                                        <td class="px-6 py-4">
                                            <?php if ($student['deployment_id']): ?>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($student['deployed_company']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($student['deployed_position']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    Supervisor: <?php echo htmlspecialchars($student['deployed_supervisor']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not assigned</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Duration -->
                                        <td class="px-6 py-4">
                                            <?php if ($student['deployment_id'] && $student['start_date'] && $student['end_date']): ?>
                                                <div class="text-sm">
                                                    <div><strong>Start:</strong> <?php echo date('M j, Y', strtotime($student['start_date'])); ?></div>
                                                    <div><strong>End:</strong> <?php echo date('M j, Y', strtotime($student['end_date'])); ?></div>
                                                    <?php
                                                    $days_remaining = ceil((strtotime($student['end_date']) - time()) / (60 * 60 * 24));
                                                    if ($days_remaining > 0): ?>
                                                        <div class="text-green-600 font-medium">
                                                            <?php echo $days_remaining; ?> days left
                                                        </div>
                                                    <?php elseif ($days_remaining == 0): ?>
                                                        <div class="text-yellow-600 font-medium">Ends today</div>
                                                    <?php else: ?>
                                                        <div class="text-red-600 font-medium">Overdue</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Hours Progress -->
                                        <td class="px-6 py-4">
                                            <?php if ($student['deployment_id'] && $student['required_hours']): ?>
                                                <?php 
                                                $progress_percentage = ($student['completed_hours'] / $student['required_hours']) * 100;
                                                $progress_percentage = min($progress_percentage, 100);
                                                ?>
                                                <div class="text-sm">
                                                    <div class="font-medium">
                                                        <?php echo $student['completed_hours']; ?> / <?php echo $student['required_hours']; ?> hrs
                                                    </div>
                                                    <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                                        <div class="bg-green-500 h-2 rounded-full transition-all duration-300" style="width: <?php echo $progress_percentage; ?>%"></div>
                                                    </div>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?php echo number_format($progress_percentage, 1); ?>% complete
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">No hours set</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Work Schedule -->
                                        <td class="px-6 py-4">
    <?php if ($student['deployment_id']): ?>
        <div class="text-sm">
            <!-- Work Days -->
            <?php 
            $displayWorkDays = $student['display_work_days']; // Use the new field
            if ($displayWorkDays): 
            ?>
                <div class="flex flex-wrap gap-1 mb-2">
                    <?php
                    $workDays = explode(',', $displayWorkDays);
                    $allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($allDays as $day): 
                        $isActive = in_array(trim($day), array_map('trim', $workDays));
                    ?>
                        <span class="px-2 py-1 text-xs rounded <?php echo $isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo substr($day, 0, 3); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Work Schedule Time -->
            <?php if ($student['work_schedule_start'] && $student['work_schedule_end']): ?>
                <div class="text-gray-700">
                    <i class="fas fa-clock mr-1"></i>
                    <?php echo date('g:i A', strtotime($student['work_schedule_start'])); ?> - 
                    <?php echo date('g:i A', strtotime($student['work_schedule_end'])); ?>
                </div>
            <?php endif; ?>
            
            <!-- Work Mode -->
            <?php if ($student['work_mode']): ?>
                <div class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <?php echo htmlspecialchars($student['work_mode']); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <span class="text-gray-400 italic">Not assigned</span>
    <?php endif; ?>
</td>   

                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap">
    <?php if ($student['deployment_id']): ?>
        <!-- For deployed students, show view details button -->
        <div class="flex space-x-2">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                <i class="fas fa-check-circle mr-1"></i>
                Deployed
            </span>
        </div>
    <?php else: ?>
        <!-- For students ready for deployment, show deploy button -->
        <button type="button" 
                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors" 
                onclick="openDeploymentModal(<?php echo $student['id']; ?>, 
                                           '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>', 
                                           '<?php echo htmlspecialchars($student['student_id']); ?>')">
            <i class="fas fa-paper-plane mr-2"></i>
            Deploy
        </button>
    <?php endif; ?>
</td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-users-slash text-gray-400 text-4xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No students found</h3>
                            <p class="text-gray-600">There are currently no students ready for deployment or deployed.</p>
                        </div>
                    <?php endif; ?>
                </div>
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

    <!-- Deployment Modal -->
    <div id="deploymentModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeDeploymentModal()"></div>

            <div class="inline-block w-full max-w-4xl p-0 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
                <!-- Modal Header -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4 flex items-center justify-between">
                    <h3 class="text-lg font-medium text-white">Deploy Student to Company</h3>
                    <button onclick="closeDeploymentModal()" class="text-white hover:text-gray-200 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Modal Content -->
                <form method="POST" id="deploymentForm" class="p-6">
                    <input type="hidden" name="student_id" id="modal_student_id">
                    
                    <!-- Student Information Section -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-user-graduate text-blue-600 mr-2"></i>
                            Student Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Student Name</label>
                                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50" id="modal_student_name" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Student ID</label>
                                <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50" id="modal_student_id_display" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Company Selection Section -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-building text-blue-600 mr-2"></i>
                            Company & Supervisor Selection
                        </h4>
                        <div>
                            <label for="supervisor_id" class="block text-sm font-medium text-gray-700 mb-2">Select Company Supervisor *</label>
                            <select name="supervisor_id" id="supervisor_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required onchange="populateCompanyDetails()">
                                <option value="">Select a supervisor...</option>
                                <?php 
                                mysqli_data_seek($supervisors_result, 0);
                                while ($supervisor = mysqli_fetch_assoc($supervisors_result)): 
                                ?>
                                    <option value="<?php echo $supervisor['supervisor_id']; ?>"
                                            data-company="<?php echo htmlspecialchars($supervisor['company_name']); ?>"
                                            data-address="<?php echo htmlspecialchars($supervisor['company_address']); ?>"
                                            data-contact="<?php echo htmlspecialchars($supervisor['company_contact_number']); ?>"
                                            data-supervisor-name="<?php echo htmlspecialchars($supervisor['full_name']); ?>"
                                            data-supervisor-position="<?php echo htmlspecialchars($supervisor['position']); ?>"
                                            data-supervisor-email="<?php echo htmlspecialchars($supervisor['email']); ?>"
                                            data-supervisor-phone="<?php echo htmlspecialchars($supervisor['phone_number']); ?>"
                                            data-role-position="<?php echo htmlspecialchars($supervisor['role_position']); ?>"
                                            data-students-needed="<?php echo htmlspecialchars($supervisor['students_needed']); ?>"
                                            data-work-days="<?php echo htmlspecialchars($supervisor['work_days']); ?>"
                                            data-work-start="<?php echo htmlspecialchars($supervisor['work_schedule_start']); ?>"
                                            data-work-end="<?php echo htmlspecialchars($supervisor['work_schedule_end']); ?>"
                                            data-work-mode="<?php echo htmlspecialchars($supervisor['work_mode']); ?>"
                                            data-internship-duration="<?php echo htmlspecialchars($supervisor['internship_duration']); ?>"
                                            data-internship-start="<?php echo htmlspecialchars($supervisor['internship_start_date']); ?>"
                                            data-internship-end="<?php echo htmlspecialchars($supervisor['internship_end_date']); ?>"
                                            data-industry="<?php echo htmlspecialchars($supervisor['industry_field']); ?>"
                                            data-required-skills="<?php echo htmlspecialchars($supervisor['required_skills']); ?>">
                                        <?php echo htmlspecialchars($supervisor['full_name'] . ' - ' . $supervisor['company_name'] . ' (' . $supervisor['industry_field'] . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <p class="text-sm text-gray-500 mt-1">All fields below will be automatically filled based on supervisor preferences</p>
                        </div>
                    </div>

                    <!-- Company Information -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-building text-blue-600 mr-2"></i>
                            Company Information <span class="text-sm text-green-600 ml-2">(Auto-populated)</span>
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-2">Company Name *</label>
                                <input type="text" name="company_name" id="company_name" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                            <div>
                                <label for="company_contact_number" class="block text-sm font-medium text-gray-700 mb-2">Company Contact Number *</label>
                                <input type="text" name="company_contact_number" id="company_contact_number" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                        </div>
                        <div>
                            <label for="company_address" class="block text-sm font-medium text-gray-700 mb-2">Company Address *</label>
                            <textarea name="company_address" id="company_address" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" rows="2" required readonly></textarea>
                        </div>
                    </div>

                    <!-- Supervisor Details -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-user-tie text-blue-600 mr-2"></i>
                            Supervisor Details <span class="text-sm text-green-600 ml-2">(Auto-populated)</span>
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="supervisor_name" class="block text-sm font-medium text-gray-700 mb-2">Supervisor Name *</label>
                                <input type="text" name="supervisor_name" id="supervisor_name" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                            <div>
                                <label for="supervisor_position" class="block text-sm font-medium text-gray-700 mb-2">Supervisor Position *</label>
                                <input type="text" name="supervisor_position" id="supervisor_position" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="supervisor_email" class="block text-sm font-medium text-gray-700 mb-2">Supervisor Email *</label>
                                <input type="email" name="supervisor_email" id="supervisor_email" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                            <div>
                                <label for="supervisor_phone" class="block text-sm font-medium text-gray-700 mb-2">Supervisor Phone *</label>
                                <input type="text" name="supervisor_phone" id="supervisor_phone" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Position and Schedule Section -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-briefcase text-blue-600 mr-2"></i>
                            Position & Schedule <span class="text-sm text-green-600 ml-2">(Based on supervisor preferences)</span>
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="position" class="block text-sm font-medium text-gray-700 mb-2">Student Position *</label>
                                <select name="position" id="position" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required onchange="toggleCustomPosition()">
                                    <option value="">Will be auto-filled...</option>
                                    <option value="Software Developer Intern">Software Developer Intern</option>
                                    <option value="Web Developer Intern">Web Developer Intern</option>
                                    <option value="IT Support Intern">IT Support Intern</option>
                                    <option value="Network Administrator Intern">Network Administrator Intern</option>
                                    <option value="Database Administrator Intern">Database Administrator Intern</option>
                                    <option value="Quality Assurance Intern">Quality Assurance Intern</option>
                                    <option value="System Analyst Intern">System Analyst Intern</option>
                                    <option value="UI/UX Designer Intern">UI/UX Designer Intern</option>
                                    <option value="Data Analyst Intern">Data Analyst Intern</option>
                                    <option value="Cybersecurity Intern">Cybersecurity Intern</option>
                                    <option value="custom">Other (Specify)</option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1" id="position_info">Based on supervisor's role_position preference</p>
                            </div>
                            <div id="custom_position_group" class="hidden">
                                <label for="custom_position" class="block text-sm font-medium text-gray-700 mb-2">Custom Position</label>
                                <input type="text" name="custom_position" id="custom_position" class="w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="Enter custom position">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date *</label>
                                <input type="date" name="start_date" id="start_date" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required>
                                <p class="text-xs text-gray-500 mt-1" id="start_date_info">Will be set based on supervisor's internship schedule</p>
                            </div>
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">End Date *</label>
                                <input type="date" name="end_date" id="end_date" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" required>
                                <p class="text-xs text-gray-500 mt-1" id="end_date_info">Will be set based on supervisor's internship schedule</p>
                            </div>
                            <div>
                                <label for="required_hours" class="block text-sm font-medium text-gray-700 mb-2">Required Hours *</label>
                                <input type="number" name="required_hours" id="required_hours" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" min="1" max="1000" required>
                                <p class="text-xs text-gray-500 mt-1" id="hours_info">Based on supervisor requirements and industry standards</p>
                            </div>
                        </div>

                        <!-- Work Days Section -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Work Days * <span class="text-sm text-green-600">(Auto-selected based on company schedule)</span></label>
                            <div class="grid grid-cols-3 md:grid-cols-7 gap-2">
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Monday')">
                                    <input type="checkbox" name="work_days[]" value="Monday" id="monday">
                                    <label for="monday">Monday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Tuesday')">
                                    <input type="checkbox" name="work_days[]" value="Tuesday" id="tuesday">
                                    <label for="tuesday">Tuesday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Wednesday')">
                                    <input type="checkbox" name="work_days[]" value="Wednesday" id="wednesday">
                                    <label for="wednesday">Wednesday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Thursday')">
                                    <input type="checkbox" name="work_days[]" value="Thursday" id="thursday">
                                    <label for="thursday">Thursday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Friday')">
                                    <input type="checkbox" name="work_days[]" value="Friday" id="friday">
                                    <label for="friday">Friday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Saturday')">
                                    <input type="checkbox" name="work_days[]" value="Saturday" id="saturday">
                                    <label for="saturday">Saturday</label>
                                </div>
                                <div class="work-day-checkbox" onclick="toggleWorkDay(this, 'Sunday')">
                                    <input type="checkbox" name="work_days[]" value="Sunday" id="sunday">
                                    <label for="sunday">Sunday</label>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Work schedule: <span id="work_schedule_display">Will be auto-filled</span></p>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                            Additional Information
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="industry_display" class="block text-sm font-medium text-gray-700 mb-2">Industry Field</label>
                                <input type="text" id="industry_display" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" readonly placeholder="Will be auto-filled">
                            </div>
                            <div>
                                <label for="duration_display" class="block text-sm font-medium text-gray-700 mb-2">Internship Duration</label>
                                <input type="text" id="duration_display" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" readonly placeholder="Will be auto-filled">
                            </div>
                        </div>
                        <div>
                            <label for="required_skills_display" class="block text-sm font-medium text-gray-700 mb-2">Required Skills</label>
                            <textarea id="required_skills_display" class="w-full px-3 py-2 border border-green-300 rounded-md bg-green-50" rows="2" readonly placeholder="Will be auto-filled"></textarea>
                            <p class="text-xs text-gray-500 mt-1">Skills preferred by the supervisor for this position</p>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button type="button" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors" onclick="closeDeploymentModal()">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" name="deploy_student" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors">
                            <i class="fas fa-paper-plane mr-2"></i>Deploy Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
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


        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Modal functions
        function openDeploymentModal(studentId, studentName, studentIdDisplay) {
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_student_name').value = studentName;
            document.getElementById('modal_student_id_display').value = studentIdDisplay;
            
            // Reset form
            document.getElementById('deploymentForm').reset();
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_student_name').value = studentName;
            document.getElementById('modal_student_id_display').value = studentIdDisplay;
            
            // Reset work days checkboxes
            resetWorkDays();
            
            // Clear auto-filled fields
            clearAutoFilledFields();
            
            // Set minimum date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('start_date').min = today;
            document.getElementById('end_date').min = today;
            
            document.getElementById('deploymentModal').classList.remove('hidden');
        }

        function closeDeploymentModal() {
            document.getElementById('deploymentModal').classList.add('hidden');
        }

        function clearAutoFilledFields() {
            const autoFilledFields = ['company_name', 'company_address', 'company_contact_number', 
                                    'supervisor_name', 'supervisor_position', 'supervisor_email', 
                                    'supervisor_phone', 'industry_display', 'duration_display', 
                                    'required_skills_display'];
            
            autoFilledFields.forEach(fieldId => {
                document.getElementById(fieldId).value = '';
            });
            
            document.getElementById('position').selectedIndex = 0;
            document.getElementById('required_hours').value = '';
            document.getElementById('start_date').value = '';
            document.getElementById('end_date').value = '';
            
            document.getElementById('start_date_info').textContent = 'Will be set based on supervisor\'s internship schedule';
            document.getElementById('end_date_info').textContent = 'Will be set based on supervisor\'s internship schedule';
            document.getElementById('hours_info').textContent = 'Based on supervisor requirements and industry standards';
            document.getElementById('work_schedule_display').textContent = 'Will be auto-filled';
            document.getElementById('position_info').textContent = 'Based on supervisor\'s role_position preference';
        }

        function populateCompanyDetails() {
    const supervisorSelect = document.getElementById('supervisor_id');
    const selectedOption = supervisorSelect.options[supervisorSelect.selectedIndex];
    
    if (selectedOption.value) {
        // Check if supervisor still has available slots
        const studentsNeeded = parseInt(selectedOption.dataset.studentsNeeded || 0);
        
        if (studentsNeeded <= 0) {
            alert('This supervisor no longer has available student slots. Please select another supervisor.');
            supervisorSelect.value = '';
            clearAutoFilledFields();
            resetWorkDays();
            return;
        }
        
        // Show available slots info
        const availableText = studentsNeeded === 1 ? '1 slot available' : `${studentsNeeded} slots available`;
        const indicator = document.createElement('div');
        indicator.innerHTML = `<i class="fas fa-info-circle mr-2"></i> ${availableText} for this supervisor`;
        indicator.className = 'text-sm text-blue-600 font-medium mt-1 supervisor-info';
        
        // Remove any existing supervisor info
        const existingInfo = document.querySelector('.supervisor-info');
        if (existingInfo) {
            existingInfo.remove();
        }
        
        // Add info after supervisor select
        supervisorSelect.parentNode.appendChild(indicator);
        
        // Populate company details
        document.getElementById('company_name').value = selectedOption.dataset.company || '';
        document.getElementById('company_address').value = selectedOption.dataset.address || '';
        document.getElementById('company_contact_number').value = selectedOption.dataset.contact || '';
        
        // Populate supervisor details
        document.getElementById('supervisor_name').value = selectedOption.dataset.supervisorName || '';
        document.getElementById('supervisor_position').value = selectedOption.dataset.supervisorPosition || '';
        document.getElementById('supervisor_email').value = selectedOption.dataset.supervisorEmail || '';
        document.getElementById('supervisor_phone').value = selectedOption.dataset.supervisorPhone || '';
        
        // Auto-populate position based on supervisor's role_position
        const rolePosition = selectedOption.dataset.rolePosition;
        if (rolePosition && rolePosition.trim() !== '') {
            const positionSelect = document.getElementById('position');
            let positionMatched = false;
            
            // Try to match with predefined positions
            for (let i = 0; i < positionSelect.options.length; i++) {
                if (positionSelect.options[i].value === 'custom') continue;
                
                const optionText = positionSelect.options[i].text.toLowerCase();
                const roleText = rolePosition.toLowerCase();
                
                // Check for exact match or contains match
                if (roleText.includes(optionText.replace(' intern', '')) || 
                    optionText.includes(roleText) || 
                    roleText === optionText.replace(' intern', '')) {
                    positionSelect.value = positionSelect.options[i].value;
                    positionMatched = true;
                    document.getElementById('position_info').textContent = `Auto-filled from supervisor's role: ${rolePosition}`;
                    break;
                }
            }
            
            // If no match found, use custom position
            if (!positionMatched) {
                positionSelect.value = 'custom';
                document.getElementById('custom_position').value = rolePosition + ' Intern';
                toggleCustomPosition();
                document.getElementById('position_info').textContent = `Custom position from supervisor's role: ${rolePosition}`;
            }
        } else {
            // Default to common position if no role_position specified
            document.getElementById('position').value = 'Software Developer Intern';
            document.getElementById('position_info').textContent = 'Default position selected (no role_position specified)';
        }
        
        // Auto-populate other fields
        document.getElementById('industry_display').value = selectedOption.dataset.industry || '';
        document.getElementById('duration_display').value = selectedOption.dataset.internshipDuration || '';
        document.getElementById('required_skills_display').value = selectedOption.dataset.requiredSkills || '';
        
        // Auto-populate required hours
        const duration = selectedOption.dataset.internshipDuration;
        let defaultHours = 500;
        
        if (duration) {
            if (duration.includes('3') && duration.includes('month')) defaultHours = 400;
            else if (duration.includes('4') && duration.includes('month')) defaultHours = 500;
            else if (duration.includes('5') && duration.includes('month')) defaultHours = 600;
            else if (duration.includes('6') && duration.includes('month')) defaultHours = 700;
        }
        
        document.getElementById('required_hours').value = defaultHours;
        document.getElementById('hours_info').textContent = `Calculated based on ${duration || 'standard internship duration'}`;

        // FIXED: Auto-populate work days properly
        const workDays = selectedOption.dataset.workDays;
        const workStart = selectedOption.dataset.workStart;
        const workEnd = selectedOption.dataset.workEnd;
        
        if (workDays && workDays.trim() !== '' && workDays !== 'null') {
            // Split by comma and clean up the array
            const workDaysArray = workDays.split(',')
                .map(day => day.trim())
                .filter(day => day.length > 0);
            
            if (workDaysArray.length > 0) {
                setWorkDays(workDaysArray);
            } else {
                // Default work days if parsing fails
                setWorkDays(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);
            }
        } else {
            // Default work days if not specified
            setWorkDays(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);
        }
        
        if (workStart && workEnd && workStart !== 'null' && workEnd !== 'null') {
            const startTime = formatTime(workStart);
            const endTime = formatTime(workEnd);
            document.getElementById('work_schedule_display').textContent = `${startTime} - ${endTime}`;
        } else {
            document.getElementById('work_schedule_display').textContent = 'Standard business hours (8:00 AM - 5:00 PM)';
        }
        
        // Auto-populate dates
        const startDate = selectedOption.dataset.internshipStart;
        const endDate = selectedOption.dataset.internshipEnd;
        
        if (startDate && startDate !== '' && startDate !== 'null') {
            document.getElementById('start_date').value = startDate;
            document.getElementById('start_date_info').textContent = 'Set based on supervisor\'s preferred start date';
        } else {
            const suggestedStart = getNextMondayOrWeekFromNow();
            document.getElementById('start_date').value = suggestedStart;
            document.getElementById('start_date_info').textContent = 'Suggested start date (can be modified)';
        }
        
        if (endDate && endDate !== '' && endDate !== 'null') {
            document.getElementById('end_date').value = endDate;
            document.getElementById('end_date_info').textContent = 'Set based on supervisor\'s preferred end date';
        } else {
            const startDateValue = document.getElementById('start_date').value;
            if (startDateValue && duration) {
                const calculatedEndDate = calculateEndDate(startDateValue, duration);
                document.getElementById('end_date').value = calculatedEndDate;
                document.getElementById('end_date_info').textContent = `Calculated based on ${duration}`;
            }
        }
        
        showAutoPopulationSuccess();
    } else {
        // Remove supervisor info when clearing selection
        const existingInfo = document.querySelector('.supervisor-info');
        if (existingInfo) {
            existingInfo.remove();
        }
        clearAutoFilledFields();
        resetWorkDays();
    }
}

function formatTime(timeString) {
    if (!timeString || timeString === 'null') return '';
    
    try {
        const time = new Date('2000-01-01 ' + timeString);
        return time.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return timeString; // Return original if parsing fails
    }
}
        function getNextMondayOrWeekFromNow() {
            const today = new Date();
            const dayOfWeek = today.getDay();
            let daysUntilNextMonday;
            
            if (dayOfWeek === 0) daysUntilNextMonday = 1;
            else if (dayOfWeek === 1) daysUntilNextMonday = 7;
            else if (dayOfWeek <= 3) daysUntilNextMonday = 8 - dayOfWeek;
            else daysUntilNextMonday = 8 - dayOfWeek + 7;
            
            const nextMonday = new Date(today.getTime() + daysUntilNextMonday * 24 * 60 * 60 * 1000);
            return nextMonday.toISOString().split('T')[0];
        }

        function calculateEndDate(startDate, duration) {
            const start = new Date(startDate);
            let months = 3;
            
            const monthMatch = duration.match(/(\d+)\s*month/i);
            if (monthMatch) {
                months = parseInt(monthMatch[1]);
            }
            
            const end = new Date(start);
            end.setMonth(end.getMonth() + months);
            
            return end.toISOString().split('T')[0];
        }

        function showAutoPopulationSuccess() {
            const indicator = document.createElement('div');
            indicator.innerHTML = '<i class="fas fa-check-circle mr-2"></i> Form auto-populated with supervisor preferences';
            indicator.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in';
            
            document.body.appendChild(indicator);
            
            setTimeout(() => {
                indicator.remove();
            }, 3000);
        }

        function toggleCustomPosition() {
            const positionSelect = document.getElementById('position');
            const customGroup = document.getElementById('custom_position_group');
            const customInput = document.getElementById('custom_position');
            
            if (positionSelect.value === 'custom') {
                customGroup.classList.remove('hidden');
                customInput.required = true;
            } else {
                customGroup.classList.add('hidden');
                customInput.required = false;
                customInput.value = '';
            }
        }

        function toggleWorkDay(element, day) {
            const checkbox = element.querySelector('input[type="checkbox"]');
            if (!checkbox.disabled) {
                checkbox.checked = !checkbox.checked;
                
                if (checkbox.checked) {
                    element.classList.add('selected');
                } else {
                    element.classList.remove('selected');
                }
            }
        }

        function setWorkDays(workDaysArray) {
            resetWorkDays();
            
            workDaysArray.forEach(day => {
                const dayLower = day.trim().toLowerCase();
                const checkbox = document.getElementById(dayLower);
                if (checkbox) {
                    checkbox.checked = true;
                    checkbox.disabled = true;
                    const container = checkbox.closest('.work-day-checkbox');
                    container.classList.add('selected', 'disabled');
                    container.onclick = null;
                }
            });
        }

        function resetWorkDays() {
            const checkboxes = document.querySelectorAll('.work-day-checkbox');
            checkboxes.forEach(element => {
                const checkbox = element.querySelector('input[type="checkbox"]');
                checkbox.checked = false;
                checkbox.disabled = false;
                element.classList.remove('selected', 'disabled');
                const day = checkbox.value;
                element.onclick = function() { toggleWorkDay(this, day); };
            });
        }

        

        function updateNoResultsMessage(visibleCount) {
            const tableContainer = document.querySelector('.overflow-x-auto');
            let noResultsMsg = tableContainer.querySelector('.no-results-message');

            if (visibleCount === 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'text-center py-12 no-results-message';
                    noResultsMsg.innerHTML = `
                        <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No students match your filters</h3>
                        <p class="text-gray-600">Try adjusting your search criteria or clearing the filters</p>
                    `;
                    tableContainer.appendChild(noResultsMsg);
                }
                document.getElementById('studentsTable').style.display = 'none';
            } else {
                if (noResultsMsg) {
                    noResultsMsg.remove();
                }
                document.getElementById('studentsTable').style.display = 'table';
            }
        }

        // Form validation
        function validateDates() {
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');

            if (startDate.value && endDate.value) {
                if (new Date(endDate.value) <= new Date(startDate.value)) {
                    alert('End date must be after start date');
                    endDate.value = '';
                    return false;
                }
            }
            return true;
        }

        function validateDeploymentForm() {
            const workDaysChecked = document.querySelectorAll('input[name="work_days[]"]:checked');
            if (workDaysChecked.length === 0) {
                alert('Please select at least one work day');
                return false;
            }

            const requiredHours = document.getElementById('required_hours').value;
            if (requiredHours < 1 || requiredHours > 1000) {
                alert('Required hours must be between 1 and 1000');
                return false;
            }

            const supervisorId = document.getElementById('supervisor_id').value;
            if (!supervisorId) {
                alert('Please select a company supervisor');
                return false;
            }

            return validateDates();
        }

        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('statusFilter').addEventListener('change', applyFilters);
            document.getElementById('departmentFilter').addEventListener('change', applyFilters);
            document.getElementById('searchBox').addEventListener('input', applyFilters);

            document.getElementById('start_date').addEventListener('change', function() {
                const startDate = this.value;
                if (startDate) {
                    document.getElementById('end_date').min = startDate;
                }
                validateDates();
            });

            document.getElementById('end_date').addEventListener('change', validateDates);

            document.getElementById('deploymentForm').addEventListener('submit', function(event) {
                if (!validateDeploymentForm()) {
                    event.preventDefault();
                }
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        // Handle escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
                
                const profileDropdown = document.getElementById('profileDropdown');
                if (!profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
                
                const modal = document.getElementById('deploymentModal');
                if (!modal.classList.contains('hidden')) {
                    closeDeploymentModal();
                }
            }
        });
    </script>
</body>
</html>