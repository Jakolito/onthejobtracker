<?php
include('connect.php');
session_start();

header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Consistent cache control headers (match dashboard)
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// Debug session information (remove after fixing)
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));

// Consistent session validation (match dashboard)
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    error_log("Session validation failed - redirecting to login");
    session_destroy();
    header("Location: login.php?error=session_expired");
    exit();
}

// Use consistent variable handling (no type casting initially)
$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Check for submission messages
$submit_success = isset($_SESSION['assessment_success']) ? $_SESSION['assessment_success'] : null;
$submit_error = isset($_SESSION['assessment_error']) ? $_SESSION['assessment_error'] : null;
unset($_SESSION['assessment_success'], $_SESSION['assessment_error']);

// Initialize variables
$student = null;
$deployment_info = null;
$full_name = '';
$first_name = '';
$middle_name = '';
$last_name = '';
$email = '';
$student_id = '';
$department = '';
$program = '';
$year_level = '';
$profile_picture = '';
$is_verified = 0;
$initials = '';
$is_deployed = false;
$can_submit_assessment = false;
$last_assessment_date = null;
$next_assessment_due = null;
$is_ojt_completed = false;
$assessment_frequency_status = 'inactive';
$days_since_last = null;
$assessment_stats = null;

// Fetch student data and check deployment status
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    // Get student information with consistent query structure
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, profile_picture, verified FROM students WHERE id = ?");
    
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    
    // Use consistent parameter binding (string initially, then validate)
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        
        // Now safely cast to int after validation
        $user_id = (int)$user_id;
        
        // Build full name (consistent with dashboard)
        $first_name = htmlspecialchars($student['first_name']);
        $middle_name = htmlspecialchars($student['middle_name'] ?? '');
        $last_name = htmlspecialchars($student['last_name']);
        
        $full_name = $first_name;
        if (!empty($middle_name)) {
            $full_name .= ' ' . $middle_name;
        }
        $full_name .= ' ' . $last_name;
        
        $email = htmlspecialchars($student['email']);
        $student_id = htmlspecialchars($student['student_id']);
        $department = htmlspecialchars($student['department']);
        $program = htmlspecialchars($student['program']);
        $year_level = htmlspecialchars($student['year_level']);
        $profile_picture = htmlspecialchars($student['profile_picture'] ?? '');
        $is_verified = (int)$student['verified'];
        
        // Create initials for avatar
        $initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
    } else {
        // Log for debugging
        error_log("Student not found for user_id: " . $user_id);
        session_destroy();
        header("Location: login.php?error=invalid_session");
        exit();
    }
    $stmt->close();

    // Enhanced deployment status checking with better weekly logic
    $deployment_stmt = $conn->prepare("SELECT *, 
        CASE 
            WHEN ojt_status = 'Completed' THEN 'completed'
            WHEN status = 'Active' THEN 'active'
            ELSE 'inactive'
        END as deployment_status
        FROM student_deployments WHERE student_id = ? AND status IN ('Active', 'Completed')");
    
    if ($deployment_stmt) {
        $deployment_stmt->bind_param("i", $user_id);
        $deployment_stmt->execute();
        $deployment_result = $deployment_stmt->get_result();
        
        if ($deployment_result->num_rows > 0) {
            $deployment_info = $deployment_result->fetch_assoc();
            $is_deployed = true;
            
            $ojt_status = $deployment_info['ojt_status'] ?? 'Active';
            $is_ojt_completed = ($ojt_status === 'Completed');
            
            // Enhanced weekly assessment logic
            if (!$is_ojt_completed) {
                // Get current week start (Monday)
                $current_date = new DateTime();
                $current_week_start = clone $current_date;
                $current_week_start->modify('Monday this week')->setTime(0, 0, 0);
                
                // Check if assessment was submitted this week
                $weekly_check_stmt = $conn->prepare("
                    SELECT 
                        assessment_id,
                        created_at,
                        WEEK(created_at, 1) as submission_week,
                        YEAR(created_at) as submission_year,
                        WEEK(NOW(), 1) as current_week,
                        YEAR(NOW()) as current_year,
                        DATEDIFF(NOW(), created_at) as days_since_last
                    FROM student_self_assessments 
                    WHERE student_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                
                if ($weekly_check_stmt) {
                    $weekly_check_stmt->bind_param("i", $user_id);
                    $weekly_check_stmt->execute();
                    $weekly_result = $weekly_check_stmt->get_result();
                    
                    if ($weekly_result->num_rows > 0) {
                        $last_assessment = $weekly_result->fetch_assoc();
                        $last_assessment_date = new DateTime($last_assessment['created_at']);
                        
                        // Check if submitted in current week
                        $is_same_week = ($last_assessment['submission_week'] == $last_assessment['current_week'] && 
                                        $last_assessment['submission_year'] == $last_assessment['current_year']);
                        
                        $can_submit_assessment = !$is_same_week;
                        
                        // Calculate next Monday
                        if ($is_same_week) {
                            $next_assessment_due = clone $current_week_start;
                            $next_assessment_due->modify('+1 week');
                        } else {
                            $next_assessment_due = clone $current_week_start;
                        }
                        
                        // Additional info for display
                        $days_since_last = (int)$last_assessment['days_since_last'];
                        $assessment_frequency_status = $is_same_week ? 'submitted_this_week' : 'can_submit';
                        
                    } else {
                        // No previous assessment
                        $can_submit_assessment = true;
                        $next_assessment_due = clone $current_week_start;
                        $last_assessment_date = null;
                        $days_since_last = null;
                        $assessment_frequency_status = 'first_assessment';
                    }
                    $weekly_check_stmt->close();
                }
                
                // Get assessment streak and statistics
                $streak_stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) as total_assessments,
                        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 4 WEEK) THEN 1 END) as recent_assessments,
                        AVG(stress_level) as avg_stress,
                        MAX(created_at) as last_submission
                    FROM student_self_assessments 
                    WHERE student_id = ?
                ");
                
                if ($streak_stmt) {
                    $streak_stmt->bind_param("i", $user_id);
                    $streak_stmt->execute();
                    $streak_result = $streak_stmt->get_result();
                    $assessment_stats = $streak_result->fetch_assoc();
                    $streak_stmt->close();
                }
                
            } else {
                $can_submit_assessment = false;
                $assessment_frequency_status = 'ojt_completed';
            }
        }
        $deployment_stmt->close();
    }

} catch (Exception $e) {
    error_log("Error fetching student data: " . $e->getMessage());
    // Don't destroy session for database errors, just redirect with error
    header("Location: studentdashboard.php?error=system_error");
    exit();
}

function insertAssessmentAlert($conn, $assessment_id, $student_id, $alert, $student_info) {
    try {
        // Insert into assessment_alerts table (create this table if it doesn't exist)
        $stmt = $conn->prepare("
            INSERT INTO assessment_alerts 
            (assessment_id, student_id, alert_type, message, severity, immediate_action, suggested_actions, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            error_log("Failed to prepare alert statement: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("iississs", 
            $assessment_id,
            $student_id, 
            $alert['type'],
            $alert['message'],
            $alert['severity'],
            $alert['immediate_action'],
            $alert['suggested_actions']
        );
        
        $result = $stmt->execute();
        $stmt->close();
        
        if (!$result) {
            error_log("Failed to insert assessment alert: " . $conn->error);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error inserting assessment alert: " . $e->getMessage());
        return false;
    }
}

function createAdviserNotification($conn, $student_id, $alerts, $student_info) {
    try {
        // Get the student's adviser (assuming you have a way to determine this)
        $adviser_stmt = $conn->prepare("
            SELECT adviser_id FROM student_advisers WHERE student_id = ? 
            UNION 
            SELECT id as adviser_id FROM advisers WHERE department = (
                SELECT department FROM students WHERE id = ?
            ) LIMIT 1
        ");
        
        if (!$adviser_stmt) {
            error_log("Failed to prepare adviser query: " . $conn->error);
            return false;
        }
        
        $adviser_stmt->bind_param("ii", $student_id, $student_id);
        $adviser_stmt->execute();
        $adviser_result = $adviser_stmt->get_result();
        
        if ($adviser_result->num_rows > 0) {
            $adviser = $adviser_result->fetch_assoc();
            $adviser_id = $adviser['adviser_id'];
            
            // Create notification message
            $high_priority_alerts = array_filter($alerts, function($alert) {
                return $alert['severity'] === 'critical' || $alert['severity'] === 'high';
            });
            
            if (!empty($high_priority_alerts)) {
                $message = "URGENT: Student {$student_info['full_name']} ({$student_info['student_id']}) has submitted a self-assessment with concerning stress levels. Immediate attention required.";
                $notification_type = 'urgent_student_alert';
            } else {
                $message = "Student {$student_info['full_name']} ({$student_info['student_id']}) has submitted a new self-assessment that requires your review.";
                $notification_type = 'student_assessment';
            }
            
            // Insert notification
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, user_type, type, title, message, is_read, created_at) 
                VALUES (?, 'adviser', ?, ?, ?, 0, NOW())
            ");
            
            if ($notif_stmt) {
                $title = "Student Self-Assessment Alert";
                $notif_stmt->bind_param("isss", $adviser_id, $notification_type, $title, $message);
                $notif_stmt->execute();
                $notif_stmt->close();
            }
        }
        
        $adviser_stmt->close();
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating adviser notification: " . $e->getMessage());
        return false;
    }
}

// Enhanced alert creation function
function createAssessmentAlerts($conn, $assessment_id, $student_id, $ratings, $challenges, $support_needed, $comments = '') {
    $alerts = [];
    
    // Get student information
    $student_stmt = $conn->prepare("
        SELECT CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) as full_name, 
            student_id, email, program 
        FROM students WHERE id = ?
    ");
    $student_stmt->bind_param("i", $student_id);
    $student_stmt->execute();
    $student_info = $student_stmt->get_result()->fetch_assoc();
    
    // 1. CRITICAL STRESS LEVEL ALERT
    if ($ratings['stress_level'] >= 4) {
        $severity = $ratings['stress_level'] == 5 ? 'critical' : 'high';
        $urgency = $ratings['stress_level'] == 5 ? "🚨 URGENT: " : "⚠️ HIGH PRIORITY: ";
        
        $challengeText = (!empty($challenges) && $challenges !== 'None') ? 
            "\n\n📋 Reported Challenges: " . $challenges : "";
        $supportText = (!empty($support_needed) && $support_needed !== 'None') ? 
            "\n🆘 Support Requested: " . $support_needed : "";
        
        $message = $urgency . "Student {$student_info['full_name']} ({$student_info['student_id']}) reported " . 
                ($ratings['stress_level'] == 5 ? "EXTREMELY HIGH" : "HIGH") . 
                " stress levels (Level {$ratings['stress_level']}/5) in their self-assessment." .
                $challengeText . $supportText . 
                "\n\n⚡ IMMEDIATE ACTION REQUIRED: Contact student and provide support resources.";
        
        $alerts[] = [
            'type' => 'high_stress',
            'message' => $message,
            'severity' => $severity,
            'immediate_action' => $ratings['stress_level'] == 5 ? 1 : 0,
            'suggested_actions' => $ratings['stress_level'] == 5 ? 
                'Contact immediately, Emergency counseling, Workload review, Mental health referral' :
                'Schedule meeting within 48hrs, Stress management discussion, Provide resources'
        ];
    }
    
    // Insert alerts and notify adviser
    if (!empty($alerts)) {
        foreach ($alerts as $alert) {
            insertAssessmentAlert($conn, $assessment_id, $student_id, $alert, $student_info);
        }
        
        // Create adviser notification
        createAdviserNotification($conn, $student_id, $alerts, $student_info);
    }
    
    return $alerts;
}

// Enhanced notification system for weekly reminders
function createWeeklyReminderNotifications($conn, $student_id) {
    try {
        // Check if reminder was already sent this week
        $check_reminder = $conn->prepare("
            SELECT notification_id FROM notifications 
            WHERE user_id = ? AND user_type = 'student' 
            AND type = 'weekly_assessment_reminder' 
            AND WEEK(created_at, 1) = WEEK(NOW(), 1) 
            AND YEAR(created_at) = YEAR(NOW())
        ");
        
        if ($check_reminder) {
            $check_reminder->bind_param("i", $student_id);
            $check_reminder->execute();
            $reminder_result = $check_reminder->get_result();
            
            if ($reminder_result->num_rows == 0) {
                // Send weekly reminder
                $insert_reminder = $conn->prepare("
                    INSERT INTO notifications 
                    (user_id, user_type, type, title, message, priority, created_at) 
                    VALUES (?, 'student', 'weekly_assessment_reminder', 
                    'Weekly Self-Assessment Due', 
                    'Your weekly self-assessment is now available. Please submit it to track your OJT progress and well-being.', 
                    'medium', NOW())
                ");
                
                if ($insert_reminder) {
                    $insert_reminder->bind_param("i", $student_id);
                    $insert_reminder->execute();
                    $insert_reminder->close();
                }
            }
            $check_reminder->close();
        }
    } catch (Exception $e) {
        error_log("Error creating weekly reminder: " . $e->getMessage());
    }
}

// Enhanced assessment tracking with weekly analytics
function getWeeklyAssessmentAnalytics($conn, $student_id) {
    try {
        $analytics_stmt = $conn->prepare("
            SELECT 
                WEEK(created_at, 1) as week_number,
                YEAR(created_at) as year,
                COUNT(*) as assessments_count,
                AVG(stress_level) as avg_stress,
                AVG(workplace_satisfaction) as avg_satisfaction,
                AVG(confidence_level) as avg_confidence,
                MAX(created_at) as latest_assessment,
                GROUP_CONCAT(DISTINCT challenges_faced) as all_challenges
            FROM student_self_assessments 
            WHERE student_id = ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
            GROUP BY WEEK(created_at, 1), YEAR(created_at)
            ORDER BY year DESC, week_number DESC
            LIMIT 12
        ");
        
        if ($analytics_stmt) {
            $analytics_stmt->bind_param("i", $student_id);
            $analytics_stmt->execute();
            $analytics_result = $analytics_stmt->get_result();
            $weekly_analytics = $analytics_result->fetch_all(MYSQLI_ASSOC);
            $analytics_stmt->close();
            return $weekly_analytics;
        }
    } catch (Exception $e) {
        error_log("Error getting weekly analytics: " . $e->getMessage());
    }
    return [];
}

// Enhanced submission handling with better weekly validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    try {
        // Comprehensive validation
        if (!$is_deployed) {
            throw new Exception("You must be deployed to a company before submitting a self-assessment.");
        }
        
        if ($is_ojt_completed) {
            throw new Exception("Assessment submission is not allowed as your OJT has been completed.");
        }
        
        // Enhanced weekly check
        if (!$can_submit_assessment) {
            if ($assessment_frequency_status === 'submitted_this_week') {
                $next_date = $next_assessment_due ? $next_assessment_due->format('l, F j, Y') : 'next Monday';
                throw new Exception("You have already submitted an assessment this week. Your next assessment will be available on " . $next_date . ".");
            } else {
                throw new Exception("Assessment submission is currently not available. Please try again later.");
            }
        }
        
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }
        
        // Validate and sanitize input
        $required_fields = [
            'academic_performance', 'stress_level', 'workplace_satisfaction', 
            'learning_progress', 'time_management', 'skill_development', 
            'mentor_support', 'work_life_balance', 'confidence_level'
        ];
        
        $ratings = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || !is_numeric($_POST[$field])) {
                throw new Exception("Missing or invalid value for {$field}");
            }
            
            $value = (int)$_POST[$field];
            if ($value < 1 || $value > 5) {
                throw new Exception("Invalid rating value for {$field}. Must be between 1 and 5.");
            }
            $ratings[$field] = $value;
        }
        
        // Process checkboxes
        $challenges_faced = '';
        if (isset($_POST['challenges_faced']) && is_array($_POST['challenges_faced'])) {
            $valid_challenges = [
                'Academic workload', 'Time management', 'Workplace stress', 
                'Communication issues', 'Technical difficulties', 'Personal issues', 
                'Financial concerns', 'None'
            ];
            $filtered_challenges = array_intersect($_POST['challenges_faced'], $valid_challenges);
            $challenges_faced = implode(',', $filtered_challenges);
        }
        
        $support_needed = '';
        if (isset($_POST['support_needed']) && is_array($_POST['support_needed'])) {
            $valid_support = [
                'Academic tutoring', 'Mental health counseling', 'Career guidance',
                'Technical training', 'Time management training', 'Financial assistance',
                'Peer support groups', 'None'
            ];
            $filtered_support = array_intersect($_POST['support_needed'], $valid_support);
            $support_needed = implode(',', $filtered_support);
        }
        
        $additional_comments = isset($_POST['additional_comments']) ? 
            substr(trim($_POST['additional_comments']), 0, 1000) : '';
        
        // Begin transaction
        $conn->autocommit(false);
        
        // Insert into database
        $insert_stmt = $conn->prepare("
            INSERT INTO student_self_assessments 
            (student_id, academic_performance, stress_level, workplace_satisfaction, learning_progress, 
            time_management, skill_development, mentor_support, work_life_balance, confidence_level, 
            challenges_faced, support_needed, additional_comments, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$insert_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $insert_stmt->bind_param("iiiiiiiiissss", 
            $user_id, 
            $ratings['academic_performance'], $ratings['stress_level'], 
            $ratings['workplace_satisfaction'], $ratings['learning_progress'],
            $ratings['time_management'], $ratings['skill_development'], 
            $ratings['mentor_support'], $ratings['work_life_balance'], 
            $ratings['confidence_level'], $challenges_faced, $support_needed, 
            $additional_comments
        );
        
        if ($insert_stmt->execute()) {
            $assessment_id = $conn->insert_id;
            
            // Log weekly submission
            $weekly_log_stmt = $conn->prepare("
                INSERT INTO assessment_weekly_log 
                (student_id, assessment_id, week_number, year, submission_day) 
                VALUES (?, ?, WEEK(NOW(), 1), YEAR(NOW()), DAYNAME(NOW()))
            ");
            
            if ($weekly_log_stmt) {
                $weekly_log_stmt->bind_param("ii", $user_id, $assessment_id);
                $weekly_log_stmt->execute();
                $weekly_log_stmt->close();
            }
            
            // Create alerts and notifications
            $created_alerts = createAssessmentAlerts($conn, $assessment_id, $user_id, $ratings, $challenges_faced, $support_needed, $additional_comments);
            
            // Send completion notification
            $success_msg = "Self-assessment submitted successfully! ";
            if ($next_assessment_due) {
                $success_msg .= "Your next assessment will be available on " . $next_assessment_due->format('l, F j, Y') . ".";
            }
            
            $_SESSION['assessment_success'] = $success_msg;
            
            // Commit transaction
            $conn->commit();
            $insert_stmt->close();
        } else {
            throw new Exception("Error saving assessment: " . $insert_stmt->error);
        }
        
        header("Location: studentSelf-Assessment.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        if ($conn) {
            $conn->rollback();
        }
        
        error_log("Assessment submission error: " . $e->getMessage());
        $_SESSION['assessment_error'] = $e->getMessage();
        header("Location: studentSelf-Assessment.php");
        exit();
    } finally {
        if ($conn) {
            $conn->autocommit(true);
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Call the reminder function for current user
if ($is_deployed && !$is_ojt_completed && $can_submit_assessment) {
    createWeeklyReminderNotifications($conn, $user_id);
}

// Get weekly analytics
$weekly_analytics = getWeeklyAssessmentAnalytics($conn, $user_id);

// Fetch recent assessments for display
$recent_assessments = [];
try {
    if ($is_deployed) {
        $recent_stmt = $conn->prepare("
            SELECT *, DATE_FORMAT(created_at, '%W, %M %d, %Y at %h:%i %p') as formatted_date
            FROM student_self_assessments 
            WHERE student_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        if ($recent_stmt) {
            $recent_stmt->bind_param("i", $user_id);
            $recent_stmt->execute();
            $recent_result = $recent_stmt->get_result();
            $recent_assessments = $recent_result->fetch_all(MYSQLI_ASSOC);
            $recent_stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error fetching recent assessments: " . $e->getMessage());
}

// Calculate assessment statistics
$total_assessments = count($recent_assessments);
$avg_stress_level = 0;
$avg_satisfaction = 0;
$avg_confidence = 0;

if ($total_assessments > 0) {
    $total_stress = array_sum(array_column($recent_assessments, 'stress_level'));
    $total_satisfaction = array_sum(array_column($recent_assessments, 'workplace_satisfaction'));
    $total_confidence = array_sum(array_column($recent_assessments, 'confidence_level'));
    
    $avg_stress_level = round($total_stress / $total_assessments, 1);
    $avg_satisfaction = round($total_satisfaction / $total_assessments, 1);
    $avg_confidence = round($total_confidence / $total_assessments, 1);
}

// Create the assessment_weekly_log table if it doesn't exist
$create_log_table = "
CREATE TABLE IF NOT EXISTS assessment_weekly_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    assessment_id INT NOT NULL,
    week_number INT NOT NULL,
    year INT NOT NULL,
    submission_day VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES student_self_assessments(assessment_id) ON DELETE CASCADE,
    INDEX idx_weekly_tracking (student_id, week_number, year)
)";

// Execute table creation (uncomment this line after first run)
// mysqli_query($conn, $create_log_table);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Self Assessment</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link rel="shortcut icon" type="image/png" href="reqsample/bulsu12.png">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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
        .nav-item {
            position: relative;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #ff4757, #ff3742);
            color: white;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(255, 71, 87, 0.4);
            animation: pulse-badge 2s infinite;
            z-index: 10;
        }

        @keyframes pulse-badge {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .notification-badge.zero {
            display: none;
        }

        .sidebar {
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        /* Radio button styling */
        .radio-option {
            transition: all 0.3s ease;
        }

        .radio-option input[type="radio"] {
            display: none;
        }

        .radio-option input[type="radio"]:checked + span {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        /* Checkbox styling */
        .checkbox-option {
            transition: all 0.3s ease;
        }

        .checkbox-option input[type="checkbox"] {
            display: none;
        }

        .checkbox-option input[type="checkbox"]:checked + span {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            <a href="studentdashboard.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-th-large mr-3"></i>
                Dashboard
            </a>
            <a href="studentAttendance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-calendar-check mr-3"></i>
                Attendance
            </a>
            <a href="studentTask.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-tasks mr-3"></i>
                Tasks
            </a>
            <a href="studentReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-book mr-3"></i>
                Report
            </a>
            <a href="studentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
                Evaluation
            </a>
            <a href="studentSelf-Assessment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-star mr-3 text-bulsu-gold"></i>
                Self-Assessment
            </a>
            <a href="studentMessage.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-envelope mr-3"></i>
                Message
            </a>
            <a href="notifications.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-bell mr-3"></i>
                Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="notification-badge" id="sidebar-notification-badge">
                        <?php echo $unread_count; ?>
                    </span>
                <?php endif; ?>
            </a>
        </nav>
    </div>
    
    <!-- User Profile -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-bulsu-gold border-opacity-30 bg-gradient-to-t from-black to-transparent">
        <div class="flex items-center space-x-3">
            <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-bulsu-gold to-yellow-400 rounded-full flex items-center justify-center text-bulsu-maroon font-semibold text-sm">
                <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                    <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                <?php else: ?>
                    <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($full_name); ?></p>
                <p class="text-xs text-bulsu-light-gold">BULSU Trainee</p>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Self Assessment</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitor your progress and well-being</p>
                </div>
                
                <!-- Profile Dropdown -->
                <div class="relative">
                    <button id="profileBtn" class="flex items-center p-1 rounded-full hover:bg-gray-100">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-xs sm:text-sm">
                            <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                    <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 sm:w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                    <?php if (!empty($profile_picture) && file_exists($profile_picture)): ?>
                                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <?php echo $initials; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($full_name); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($student_id); ?> • <?php echo htmlspecialchars($program); ?></p>
                                </div>
                            </div>
                        </div>
                        <a href="studentAccount-settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-cog mr-3"></i>
                            Account Settings
                        </a>
                        <div class="border-t border-gray-200"></div>
                        <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-sign-out-alt mr-3"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Container -->
        <div class="p-4 sm:p-6 lg:p-8">
            <!-- Alert Messages -->
            <?php if ($submit_success): ?>
                <div id="successAlert" class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-check-circle text-green-600 mt-1 mr-3"></i>
                            <p class="text-green-700"><?php echo htmlspecialchars($submit_success); ?></p>
                        </div>
                        <button onclick="closeAlert('successAlert')" class="text-green-400 hover:text-green-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($submit_error): ?>
                <div id="errorAlert" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                            <p class="text-red-700"><?php echo htmlspecialchars($submit_error); ?></p>
                        </div>
                        <button onclick="closeAlert('errorAlert')" class="text-red-400 hover:text-red-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Weekly Status Dashboard -->
          <div class="mb-6 sm:mb-8 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg p-6">
    <div class="flex items-center mb-4">
        <i class="fas fa-calendar-week text-2xl mr-3"></i>
        <h3 class="text-xl font-bold">Weekly Assessment Status</h3>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 border border-white/20">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-white/80">This Week</p>
                    <p class="text-lg font-semibold text-white">
                        <?php 
                        if ($assessment_frequency_status === 'submitted_this_week') {
                            echo "✓ Completed";
                        } elseif ($assessment_frequency_status === 'can_submit') {
                            echo "⏳ Pending";
                        } elseif ($assessment_frequency_status === 'first_assessment') {
                            echo "🎯 Ready";
                        } else {
                            echo "❌ Unavailable";
                        }
                        ?>
                    </p>
                </div>
                <div class="text-2xl">
                    <?php if ($assessment_frequency_status === 'submitted_this_week'): ?>
                        <i class="fas fa-check-circle text-green-400"></i>
                    <?php elseif ($can_submit_assessment): ?>
                        <i class="fas fa-clock text-yellow-400"></i>
                    <?php else: ?>
                        <i class="fas fa-lock text-white/60"></i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 border border-white/20">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-white/80">Next Due</p>
                    <p class="text-sm font-medium text-white">
                        <?php 
                        if ($next_assessment_due) {
                            $days_until = $next_assessment_due->diff(new DateTime())->days;
                            if ($can_submit_assessment) {
                                echo "Available Now";
                            } else {
                                echo $next_assessment_due->format('M j, Y') . " (" . $days_until . " days)";
                            }
                        } else {
                            echo "Available Now";
                        }
                        ?>
                    </p>
                </div>
                <div class="text-xl">
                    <i class="fas fa-calendar-alt text-white/80"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white/10 backdrop-blur-sm rounded-lg p-4 border border-white/20">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-white/80">Completion Rate</p>
                    <p class="text-lg font-semibold text-white">
                        <?php 
                        if (isset($assessment_stats)) {
                            $rate = ($assessment_stats['recent_assessments'] / 4) * 100; // Last 4 weeks
                            echo min(100, round($rate)) . "%";
                        } else {
                            echo "0%";
                        }
                        ?>
                    </p>
                </div>
                <div class="text-xl">
                    <i class="fas fa-chart-line text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($days_since_last !== null): ?>
    <div class="mt-4 p-3 bg-white/10 backdrop-blur-sm rounded-lg border border-white/20">
        <p class="text-sm text-white">
            <i class="fas fa-info-circle mr-2"></i>
            Last assessment submitted <?php echo $days_since_last; ?> day<?php echo $days_since_last !== 1 ? 's' : ''; ?> ago
            <?php if ($can_submit_assessment): ?>
                - You can submit a new assessment now.
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>
</div>

            <!-- Deployment Status -->
            <?php if ($is_deployed): ?>
                <?php if ($is_ojt_completed): ?>
                    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-blue-600 mr-3"></i>
                            <div>
                                <h3 class="text-blue-800 font-semibold">OJT Completed</h3>
                                <p class="text-blue-700">
                                    Company: <?php echo htmlspecialchars($deployment_info['company_name'] ?? 'N/A'); ?>
                                    <br>Your On-the-Job Training has been completed. Assessment submissions are no longer available.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-building text-green-600 mr-3"></i>
                            <div>
                                <h3 class="text-green-800 font-semibold">Currently Deployed</h3>
                                <p class="text-green-700">
                                    Company: <?php echo htmlspecialchars($deployment_info['company_name'] ?? 'N/A'); ?>
                                    <br>OJT Status: <?php echo htmlspecialchars($ojt_status); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-600 mr-3"></i>
                        <div>
                            <h3 class="text-red-800 font-semibold">Not Deployed</h3>
                            <p class="text-red-700">You must be deployed to a company to submit self-assessments.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Weekly Progress Chart -->
            <?php if (!empty($weekly_analytics)): ?>
            <div class="mb-6 bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4 rounded-t-lg">
                    <h3 class="text-lg font-semibold text-white flex items-center">
                        <i class="fas fa-chart-area mr-3"></i>
                        Weekly Progress Tracking
                    </h3>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Stress Level Trend -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-medium text-gray-900 mb-3">Stress Level Trend</h4>
                            <div class="space-y-2">
                                <?php foreach (array_slice($weekly_analytics, 0, 6) as $week): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Week <?php echo $week['week_number']; ?>/<?php echo $week['year']; ?></span>
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-red-500 h-2 rounded-full" style="width: <?php echo ($week['avg_stress'] / 5) * 100; ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?php echo number_format($week['avg_stress'], 1); ?>/5</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Satisfaction Trend -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="font-medium text-gray-900 mb-3">Satisfaction Trend</h4>
                            <div class="space-y-2">
                                <?php foreach (array_slice($weekly_analytics, 0, 6) as $week): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600">Week <?php echo $week['week_number']; ?>/<?php echo $week['year']; ?></span>
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo ($week['avg_satisfaction'] / 5) * 100; ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?php echo number_format($week['avg_satisfaction'], 1); ?>/5</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistics -->
            <?php if ($total_assessments > 0): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clipboard-list text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-2xl font-bold text-gray-900"><?php echo $total_assessments; ?></p>
                                <p class="text-sm text-gray-500">Total Assessments</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-heart-pulse text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-2xl font-bold text-gray-900"><?php echo $avg_stress_level; ?>/5</p>
                                <p class="text-sm text-gray-500">Average Stress Level</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-smile text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-2xl font-bold text-gray-900"><?php echo $avg_satisfaction; ?>/5</p>
                                <p class="text-sm text-gray-500">Average Satisfaction</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-trophy text-yellow-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-2xl font-bold text-gray-900"><?php echo $avg_confidence; ?>/5</p>
                                <p class="text-sm text-gray-500">Average Confidence</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Assessment Form -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-8 <?php echo (!$is_deployed || !$can_submit_assessment || $is_ojt_completed) ? 'opacity-60 pointer-events-none' : ''; ?>">
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4 rounded-t-lg">
                    <h2 class="text-xl font-semibold text-white flex items-center">
                        <i class="fas fa-clipboard-check mr-3"></i>
                        New Self Assessment
                        <?php if ($is_ojt_completed): ?>
                            <span class="ml-2 px-2 py-1 bg-blue-500 text-xs rounded-full">OJT Completed</span>
                        <?php endif; ?>
                    </h2>
                </div>

                <div class="p-6">
                    <form method="POST" action="studentSelf-Assessment.php" id="assessmentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <!-- Academic Performance Section -->
<!-- Academic Performance Section -->
<div class="mb-8">
    <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-graduation-cap text-blue-600 mr-3"></i>
        Academic & Learning
    </h3>
    
    <table class="w-full border-collapse border border-gray-300">
        <thead>
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Assessment Items</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Excellent<br>5</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Good<br>4</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Average<br>3</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Below Average<br>2</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Poor<br>1</th>
            </tr>
        </thead>
        <tbody>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How would you rate your current academic performance?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-blue-500 transition-colors">
                            <input type="radio" name="academic_performance" value="<?php echo $i; ?>" class="sr-only academic-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How satisfied are you with your learning progress?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-blue-500 transition-colors">
                            <input type="radio" name="learning_progress" value="<?php echo $i; ?>" class="sr-only academic-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How satisfied are you with your skill development progress?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-blue-500 transition-colors">
                            <input type="radio" name="skill_development" value="<?php echo $i; ?>" class="sr-only academic-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How confident do you feel about your abilities?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-blue-500 transition-colors">
                            <input type="radio" name="confidence_level" value="<?php echo $i; ?>" class="sr-only academic-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
        </tbody>
    </table>
</div>

<!-- Well-being Section -->
<div class="mb-8">
    <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-heart text-red-600 mr-3"></i>
        Well-being & Mental Health
    </h3>
    
    <table class="w-full border-collapse border border-gray-300">
        <thead>
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Assessment Items</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Very High<br>5</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">High<br>4</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Moderate<br>3</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Low<br>2</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Very Low<br>1</th>
            </tr>
        </thead>
        <tbody>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">What is your current stress level?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-red-500 transition-colors">
                            <input type="radio" name="stress_level" value="<?php echo $i; ?>" class="sr-only wellbeing-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How effectively are you managing your time?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-blue-500 transition-colors">
                            <input type="radio" name="time_management" value="<?php echo $i; ?>" class="sr-only wellbeing-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How well are you maintaining work-life balance?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-green-500 transition-colors">
                            <input type="radio" name="work_life_balance" value="<?php echo $i; ?>" class="sr-only wellbeing-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
        </tbody>
    </table>
</div>

<!-- Workplace Experience Section -->
<div class="mb-8">
    <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-building text-green-600 mr-3"></i>
        Workplace Experience
    </h3>
    
    <table class="w-full border-collapse border border-gray-300">
        <thead>
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Assessment Items</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Very Satisfied<br>5</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Satisfied<br>4</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Neutral<br>3</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Unsatisfied<br>2</th>
                <th class="border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">Very Unsatisfied<br>1</th>
            </tr>
        </thead>
        <tbody>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How satisfied are you with your workplace experience?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-green-500 transition-colors">
                            <input type="radio" name="workplace_satisfaction" value="<?php echo $i; ?>" class="sr-only workplace-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
            <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-4 py-3 text-sm text-gray-800">How would you rate the support from your mentor/supervisor?</td>
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <td class="border border-gray-300 px-2 py-3 text-center">
                        <label class="inline-flex items-center justify-center w-8 h-8 border-2 border-gray-400 rounded-full cursor-pointer hover:border-purple-500 transition-colors">
                            <input type="radio" name="mentor_support" value="<?php echo $i; ?>" class="sr-only workplace-radio" required>
                            <span class="text-sm font-medium"><?php echo $i; ?></span>
                        </label>
                    </td>
                <?php endfor; ?>
            </tr>
        </tbody>
    </table>
</div>

<style>
/* Academic section styling */
.academic-radio:checked + span {
    background-color: #3b82f6;
    color: white;
    border-radius: 50%;
    transform: scale(1.1);
}

label:has(.academic-radio:checked) {
    border-color: #3b82f6;
    border-width: 3px;
    background-color: #3b82f6;
}

/* Wellbeing section styling */
.wellbeing-radio:checked + span {
    background-color: #ef4444;
    color: white;
    border-radius: 50%;
    transform: scale(1.1);
}

label:has(.wellbeing-radio:checked) {
    border-color: #ef4444;
    border-width: 3px;
    background-color: #ef4444;
}

/* Workplace section styling */
.workplace-radio:checked + span {
    background-color: #10b981;
    color: white;
    border-radius: 50%;
    transform: scale(1.1);
}

label:has(.workplace-radio:checked) {
    border-color: #10b981;
    border-width: 3px;
    background-color: #10b981;
}

/* General hover and transition effects */
label {
    transition: all 0.2s ease;
}

label:hover {
    transform: scale(1.05);
}

.academic-radio:checked + span,
.wellbeing-radio:checked + span,
.workplace-radio:checked + span {
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
</style>
                        <!-- Challenges Section -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-exclamation-circle text-yellow-600 mr-3"></i>
                                Challenges & Support
                            </h3>
                            
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        Challenges Faced (Select all that apply)
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                        <?php 
                                        $challenges = [
                                            'Academic workload', 'Time management', 'Workplace stress', 
                                            'Communication issues', 'Technical difficulties', 'Personal issues', 
                                            'Financial concerns', 'None'
                                        ];
                                        foreach ($challenges as $challenge): ?>
                                            <label class="checkbox-option flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:border-yellow-500">
                                                <input type="checkbox" name="challenges_faced[]" value="<?php echo htmlspecialchars($challenge); ?>" class="sr-only">
                                                <span class="text-sm"><?php echo htmlspecialchars($challenge); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        Support Needed (Select all that apply)
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                        <?php 
                                        $support_options = [
                                            'Academic tutoring', 'Mental health counseling', 'Career guidance',
                                            'Technical training', 'Time management training', 'Financial assistance',
                                            'Peer support groups', 'None'
                                        ];
                                        foreach ($support_options as $support): ?>
                                            <label class="checkbox-option flex items-center p-3 border border-gray-300 rounded-lg cursor-pointer hover:border-blue-500">
                                                <input type="checkbox" name="support_needed[]" value="<?php echo htmlspecialchars($support); ?>" class="sr-only">
                                                <span class="text-sm"><?php echo htmlspecialchars($support); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-3">
                                        Additional Comments <span class="text-gray-400 text-xs">(Optional)</span>
                                    </label>
                                    <textarea name="additional_comments" 
                                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                              rows="4" 
                                              placeholder="Share any additional thoughts, concerns, or feedback... (Optional)" 
                                              maxlength="1000"></textarea>
                                    <p class="text-xs text-gray-500 mt-1">Maximum 1000 characters</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-center">
                            <button type="submit" 
                                    name="submit_assessment" 
                                    class="flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-lg hover:from-blue-700 hover:to-purple-700 transition-all duration-200 <?php if (!$is_deployed || !$can_submit_assessment || $is_ojt_completed) echo 'opacity-50 cursor-not-allowed'; ?>"
                                    <?php if (!$is_deployed || !$can_submit_assessment || $is_ojt_completed) echo 'disabled'; ?>>
                                <i class="fas fa-paper-plane mr-2"></i>
                                <?php if ($is_ojt_completed): ?>
                                    OJT Completed - Submission Disabled
                                <?php else: ?>
                                    Submit Assessment
                                <?php endif; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Assessments -->
            <?php if ($is_deployed): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon px-6 py-4 rounded-t-lg">
                        <h3 class="text-lg font-semibold text-white flex items-center">
                            <i class="fas fa-history mr-3"></i>
                            Recent Assessments
                        </h3>
                    </div>
                    
                    <?php if (empty($recent_assessments)): ?>
                        <div class="p-8 text-center">
                            <i class="fas fa-clipboard text-gray-400 text-4xl mb-4"></i>
                            <h4 class="text-lg font-medium text-gray-900 mb-2">No Assessments Yet</h4>
                            <p class="text-gray-500">Your submitted assessments will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-200">
                            <?php foreach ($recent_assessments as $assessment): ?>
                                <div class="p-6 hover:bg-gray-50 transition-colors duration-150">
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center text-sm text-gray-500">
                                            <i class="fas fa-calendar mr-2"></i>
                                            <?php echo $assessment['formatted_date']; ?>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                        <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                                            <span class="text-sm text-gray-600">Academic</span>
                                            <span class="font-semibold text-blue-600"><?php echo $assessment['academic_performance']; ?>/5</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                                            <span class="text-sm text-gray-600">Stress</span>
                                            <span class="font-semibold text-red-600"><?php echo $assessment['stress_level']; ?>/5</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                                            <span class="text-sm text-gray-600">Satisfaction</span>
                                            <span class="font-semibold text-green-600"><?php echo $assessment['workplace_satisfaction']; ?>/5</span>
                                        </div>
                                        <div class="flex justify-between items-center p-3 bg-yellow-50 rounded-lg">
                                            <span class="text-sm text-gray-600">Confidence</span>
                                            <span class="font-semibold text-yellow-600"><?php echo $assessment['confidence_level']; ?>/5</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.remove('hidden');
        });

        document.getElementById('closeSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.add('hidden');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.add('hidden');
        });

        // Profile dropdown functionality
        document.getElementById('profileBtn').addEventListener('click', function() {
            document.getElementById('profileDropdown').classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (!profileBtn.contains(event.target) && !profileDropdown.contains(event.target)) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Alert close functionality
        function closeAlert(alertId) {
            document.getElementById(alertId).style.display = 'none';
        }

        // Radio button styling
        document.querySelectorAll('.radio-option').forEach(option => {
            option.addEventListener('click', function() {
                const input = this.querySelector('input[type="radio"]');
                const name = input.name;
                
                // Remove selected styling from all options with same name
                document.querySelectorAll(`input[name="${name}"]`).forEach(radio => {
                    radio.closest('.radio-option').classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
                    radio.closest('.radio-option').classList.add('border-gray-300');
                });
                
                // Add selected styling to clicked option
                this.classList.remove('border-gray-300');
                this.classList.add('bg-blue-600', 'text-white', 'border-blue-600');
                input.checked = true;
            });
        });

        // Enhanced checkbox styling and functionality
        function updateCheckboxStyle(option, checkbox) {
            if (checkbox.checked) {
                option.classList.remove('border-gray-300');
                option.classList.add('bg-blue-600', 'text-white', 'border-blue-600');
            } else {
                option.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
                option.classList.add('border-gray-300');
            }
        }

        function handleNoneOption(checkbox) {
            const checkboxName = checkbox.name;
            
            // If "None" is selected, uncheck all other options in the same group
            if (checkbox.value === 'None' && checkbox.checked) {
                const otherCheckboxes = document.querySelectorAll(`input[name="${checkboxName}"]:not([value="None"])`);
                otherCheckboxes.forEach(cb => {
                    if (cb.checked) {
                        cb.checked = false;
                        const option = cb.closest('.checkbox-option');
                        updateCheckboxStyle(option, cb);
                    }
                });
            } 
            // If any other option is selected, uncheck "None"
            else if (checkbox.checked && checkbox.value !== 'None') {
                const noneCheckbox = document.querySelector(`input[name="${checkboxName}"][value="None"]`);
                if (noneCheckbox && noneCheckbox.checked) {
                    noneCheckbox.checked = false;
                    const noneOption = noneCheckbox.closest('.checkbox-option');
                    updateCheckboxStyle(noneOption, noneCheckbox);
                }
            }
        }

        // Initialize checkbox functionality
        document.querySelectorAll('.checkbox-option').forEach(option => {
            const checkbox = option.querySelector('input[type="checkbox"]');
            
            // Handle clicks on the label/option
            option.addEventListener('click', function(e) {
                // Prevent event if clicking directly on the checkbox
                if (e.target.type === 'checkbox') {
                    return;
                }
                
                // Prevent default to avoid double-triggering
                e.preventDefault();
                
                // Toggle the checkbox
                checkbox.checked = !checkbox.checked;
                
                // Update styling and handle "None" logic
                updateCheckboxStyle(option, checkbox);
                handleNoneOption(checkbox);
            });
            
            // Handle direct checkbox changes (keyboard navigation, etc.)
            checkbox.addEventListener('change', function(e) {
                updateCheckboxStyle(option, this);
                handleNoneOption(this);
            });
        });

        // Form validation
        document.getElementById('assessmentForm').addEventListener('submit', function(e) {
            const requiredRatings = [
                'academic_performance', 'stress_level', 'workplace_satisfaction', 
                'learning_progress', 'time_management', 'skill_development', 
                'mentor_support', 'work_life_balance', 'confidence_level'
            ];
            
            let isValid = true;
            const missingFields = [];
            
            requiredRatings.forEach(fieldName => {
                const selected = document.querySelector(`input[name="${fieldName}"]:checked`);
                if (!selected) {
                    isValid = false;
                    missingFields.push(fieldName.replace('_', ' ').toUpperCase());
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please rate all required fields: ' + missingFields.join(', '));
            }
        });

        // Enhanced JavaScript for weekly awareness
        document.addEventListener('DOMContentLoaded', function() {
            
            // Weekly reminder notifications
            function checkWeeklyReminder() {
                const canSubmit = <?php echo json_encode($can_submit_assessment); ?>;
                const isDeployed = <?php echo json_encode($is_deployed); ?>;
                const isCompleted = <?php echo json_encode($is_ojt_completed ?? false); ?>;
                
                if (isDeployed && !isCompleted && canSubmit) {
                    // Show weekly reminder if it's Monday and no assessment submitted
                    const today = new Date();
                    const dayOfWeek = today.getDay(); // 0 = Sunday, 1 = Monday
                    
                    if (dayOfWeek === 1) { // Monday
                        setTimeout(() => {
                            // Use sessionStorage instead of localStorage since localStorage is not available
                            const reminderKey = 'weekly_reminder_shown_' + today.toDateString();
                            if (!sessionStorage.getItem(reminderKey)) {
                                showWeeklyReminder();
                                sessionStorage.setItem(reminderKey, 'true');
                            }
                        }, 3000); // Show after 3 seconds
                    }
                }
            }
            
            function showWeeklyReminder() {
                const reminder = document.createElement('div');
                reminder.className = 'fixed top-4 right-4 bg-blue-600 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm';
                reminder.innerHTML = `
                    <div class="flex items-start">
                        <i class="fas fa-calendar-week text-xl mr-3 mt-1"></i>
                        <div class="flex-1">
                            <h4 class="font-semibold">Weekly Assessment Available</h4>
                            <p class="text-sm mt-1">Your weekly self-assessment is ready to submit.</p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-blue-200 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="mt-3">
                        <button onclick="document.getElementById('assessmentForm').scrollIntoView({behavior: 'smooth'}); this.parentElement.parentElement.remove();" 
                                class="bg-white text-blue-600 px-3 py-1 rounded text-sm font-medium hover:bg-blue-50">
                            Submit Now
                        </button>
                    </div>
                `;
                document.body.appendChild(reminder);
                
                setTimeout(() => {
                    if (reminder.parentElement) {
                        reminder.remove();
                    }
                }, 10000); // Auto-remove after 10 seconds
            }
            
            // Initialize weekly reminder check
            checkWeeklyReminder();
            
            // Progress tracking
            function updateProgressIndicators() {
                const progressBars = document.querySelectorAll('[data-progress]');
                progressBars.forEach(bar => {
                    const progress = bar.dataset.progress;
                    bar.style.width = progress + '%';
                });
            }
            
            updateProgressIndicators();
        });

        // Weekly statistics update
        function refreshWeeklyStats() {
            fetch('get_weekly_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update weekly status indicators
                        document.querySelectorAll('[data-weekly-stat]').forEach(element => {
                            const statType = element.dataset.weeklyStat;
                            if (data.stats[statType]) {
                                element.textContent = data.stats[statType];
                            }
                        });
                    }
                })
                .catch(error => console.error('Error updating weekly stats:', error));
        }

        // Refresh stats every 5 minutes
        setInterval(refreshWeeklyStats, 300000);

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('[id$="Alert"]');
            alerts.forEach(alert => {
                if (alert) alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>


