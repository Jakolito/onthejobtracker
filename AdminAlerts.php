<?php
include('connect.php');
session_start();

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
$adviser_email = $_SESSION['email'];

$unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
$unread_messages_result = mysqli_query($conn, $unread_messages_query);
$unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

// Handle AJAX requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_notification':
            if (isset($_POST['student_id']) && isset($_POST['title']) && isset($_POST['message']) && isset($_POST['type'])) {
                $student_id = (int)$_POST['student_id'];
                $title = mysqli_real_escape_string($conn, $_POST['title']);
                $message = mysqli_real_escape_string($conn, $_POST['message']);
                $type = mysqli_real_escape_string($conn, $_POST['type']);
                $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : null;
                $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : null;
                
                // Verify the student exists
                $check_student = "SELECT first_name, last_name, student_id FROM students WHERE id = ?";
                $check_stmt = $conn->prepare($check_student);
                $check_stmt->bind_param("i", $student_id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Student not found']);
                    exit();
                }
                
                $student_info = $result->fetch_assoc();
                
                // Insert notification for this specific student
                $insert_query = "INSERT INTO student_notifications (student_id, title, message, type, task_id, document_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("isssii", $student_id, $title, $message, $type, $task_id, $document_id);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Notification sent successfully to ' . $student_info['first_name'] . ' ' . $student_info['last_name']]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to send notification: ' . $stmt->error]);
                }
                exit();
            }
            break;
            
    case 'resolve_alert':
    if (isset($_POST['student_id']) && isset($_POST['alert_type'])) {
        $student_id = (int)$_POST['student_id'];
        $alert_type = mysqli_real_escape_string($conn, $_POST['alert_type']);
        $alert_details = isset($_POST['alert_details']) ? mysqli_real_escape_string($conn, $_POST['alert_details']) : '';
        
        // Check if alert is already resolved within the last 24 hours
        if (isAlertResolved($conn, $student_id, $alert_type)) {
            echo json_encode(['success' => false, 'message' => 'Alert was already resolved recently']);
            exit();
        }
        
        // Insert into resolved_alerts table with current timestamp
        $resolve_query = "INSERT INTO resolved_alerts (student_id, adviser_id, alert_type, alert_details, resolved_at, resolved_by, notes) 
                         VALUES (?, ?, ?, ?, NOW(), ?, 'Alert resolved by adviser')";
        $resolve_stmt = $conn->prepare($resolve_query);
        $resolve_stmt->bind_param("iisss", $student_id, $adviser_id, $alert_type, $alert_details, $adviser_name);
        
        if ($resolve_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Alert resolved successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to resolve alert: ' . $resolve_stmt->error]);
        }
        exit();
    }
    break;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// Initialize alerts arrays
function isAlertResolved($conn, $student_id, $alert_type) {
    $check_query = "SELECT id FROM resolved_alerts 
                    WHERE student_id = ? AND alert_type = ? 
                    ORDER BY resolved_at DESC LIMIT 1";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("is", $student_id, $alert_type);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Initialize alerts arrays
$task_alerts = [];
$performance_alerts = [];
$system_alerts = [];
$attendance_alerts = [];
$all_alerts = [];

try {
    // 1. TASK-RELATED ALERTS - Modified with resolved check
    $overdue_tasks_query = "
        SELECT 
            s.first_name, s.last_name, s.student_id, s.email, s.id as student_db_id,
            COUNT(t.task_id) as overdue_count,
            MAX(DATEDIFF(CURDATE(), t.due_date)) as max_days_overdue,
            GROUP_CONCAT(DISTINCT t.priority ORDER BY 
                CASE t.priority 
                    WHEN 'Critical' THEN 1 
                    WHEN 'High' THEN 2 
                    WHEN 'Medium' THEN 3 
                    WHEN 'Low' THEN 4 
                END
            ) as priorities,
            GROUP_CONCAT(DISTINCT t.task_title ORDER BY t.due_date SEPARATOR ' | ') as overdue_tasks
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN tasks t ON sd.deployment_id = t.deployment_id AND s.id = t.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type = 'Overdue Tasks'
        WHERE sd.ojt_status = 'Active' 
        AND t.status IN ('Pending', 'In Progress')
        AND t.due_date < CURDATE()
        AND ra.id IS NULL
        GROUP BY s.id
        ORDER BY max_days_overdue DESC, overdue_count DESC
    ";
    $overdue_result = mysqli_query($conn, $overdue_tasks_query);
    
    while ($row = mysqli_fetch_assoc($overdue_result)) {
        $severity = 'warning';
        $priorities = explode(',', $row['priorities']);
        
        if (in_array('Critical', $priorities) || $row['max_days_overdue'] > 7) {
            $severity = 'critical';
        } elseif (in_array('High', $priorities) || $row['max_days_overdue'] > 3) {
            $severity = 'warning';
        }
        
        $task_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => 'Overdue Tasks',
            'severity' => $severity,
            'details' => $row['overdue_count'] . ' overdue tasks (up to ' . $row['max_days_overdue'] . ' days late)',
            'additional_info' => substr($row['overdue_tasks'], 0, 100) . '...',
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Contact student immediately'
        ];
    }
    
    // No task submissions alert - Modified with resolved check
    $no_submissions_query = "
        SELECT 
            s.first_name, s.last_name, s.student_id, s.email, s.id as student_db_id,
            COUNT(t.task_id) as assigned_tasks,
            COALESCE(MAX(ts.submitted_at), 'Never') as last_submission,
            DATEDIFF(CURDATE(), COALESCE(MAX(ts.submitted_at), sd.start_date)) as days_no_submission
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN tasks t ON sd.deployment_id = t.deployment_id AND s.id = t.student_id
        LEFT JOIN task_submissions ts ON t.task_id = ts.task_id AND s.id = ts.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type = 'No Task Submissions'
        WHERE sd.ojt_status = 'Active' 
        AND t.status IN ('Pending', 'In Progress')
        AND t.created_at <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND ra.id IS NULL
        GROUP BY s.id
        HAVING days_no_submission >= 7 AND assigned_tasks > 0
        ORDER BY days_no_submission DESC
    ";
    $no_submissions_result = mysqli_query($conn, $no_submissions_query);
    
    while ($row = mysqli_fetch_assoc($no_submissions_result)) {
        $severity = ($row['days_no_submission'] >= 14) ? 'critical' : 'warning';
        
        $task_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => 'No Task Submissions',
            'severity' => $severity,
            'details' => 'No submissions for ' . $row['days_no_submission'] . ' days (' . $row['assigned_tasks'] . ' tasks assigned)',
            'additional_info' => 'Last submission: ' . ($row['last_submission'] === 'Never' ? 'Never' : date('M j, Y', strtotime($row['last_submission']))),
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Follow up on task progress'
        ];
    }
    
    // 2. ATTENDANCE-RELATED ALERTS - Modified with resolved check
    $attendance_issues_query = "
        SELECT 
            s.first_name, 
            s.last_name, 
            s.student_id, 
            s.email, 
            s.id as student_db_id,
            sd.deployment_id,
            cs.work_schedule_start,
            cs.work_schedule_end,
            cs.work_days,
            sd.start_date,
            sd.end_date,
            sd.ojt_status,
            COUNT(DISTINCT work_days_calendar.work_date) as total_expected_days,
            COUNT(DISTINCT sa.date) as days_with_attendance,
            COUNT(DISTINCT CASE WHEN sa.time_in IS NOT NULL AND sa.time_out IS NOT NULL THEN sa.date END) as complete_days,
            COUNT(DISTINCT CASE WHEN sa.time_in IS NULL AND sa.time_out IS NULL THEN work_days_calendar.work_date END) as absent_days,
            MAX(sa.date) as last_attendance_date,
            DATEDIFF(CURDATE(), MAX(sa.date)) as days_since_last_attendance
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
        JOIN (
            SELECT 
                DATE_ADD(sd_inner.start_date, INTERVAL numbers.n DAY) as work_date,
                sd_inner.student_id,
                cs_inner.work_days
            FROM student_deployments sd_inner
            JOIN company_supervisors cs_inner ON sd_inner.supervisor_id = cs_inner.supervisor_id
            JOIN (
                SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 
                UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 
                UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 
                UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 
                UNION SELECT 28 UNION SELECT 29 UNION SELECT 30 UNION SELECT 31 UNION SELECT 32 UNION SELECT 33 UNION SELECT 34 
                UNION SELECT 35 UNION SELECT 36 UNION SELECT 37 UNION SELECT 38 UNION SELECT 39 UNION SELECT 40 UNION SELECT 41 
                UNION SELECT 42 UNION SELECT 43 UNION SELECT 44 UNION SELECT 45 UNION SELECT 46 UNION SELECT 47 UNION SELECT 48 
                UNION SELECT 49 UNION SELECT 50 UNION SELECT 51 UNION SELECT 52 UNION SELECT 53 UNION SELECT 54 UNION SELECT 55 
                UNION SELECT 56 UNION SELECT 57 UNION SELECT 58 UNION SELECT 59 UNION SELECT 60 UNION SELECT 61 UNION SELECT 62 
                UNION SELECT 63 UNION SELECT 64 UNION SELECT 65 UNION SELECT 66 UNION SELECT 67 UNION SELECT 68 UNION SELECT 69 
                UNION SELECT 70 UNION SELECT 71 UNION SELECT 72 UNION SELECT 73 UNION SELECT 74 UNION SELECT 75 UNION SELECT 76 
                UNION SELECT 77 UNION SELECT 78 UNION SELECT 79 UNION SELECT 80 UNION SELECT 81 UNION SELECT 82 UNION SELECT 83 
                UNION SELECT 84 UNION SELECT 85 UNION SELECT 86 UNION SELECT 87 UNION SELECT 88 UNION SELECT 89 UNION SELECT 90
            ) numbers ON DATE_ADD(sd_inner.start_date, INTERVAL numbers.n DAY) <= LEAST(sd_inner.end_date, CURDATE())
            WHERE sd_inner.ojt_status = 'Active'
            AND FIND_IN_SET(LOWER(DAYNAME(DATE_ADD(sd_inner.start_date, INTERVAL numbers.n DAY))), LOWER(cs_inner.work_days)) > 0
        ) work_days_calendar ON work_days_calendar.student_id = s.id
        LEFT JOIN student_attendance sa ON s.id = sa.student_id AND sa.date = work_days_calendar.work_date
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND (
            ra.alert_type IN ('Poor Attendance', 'Excessive Absences', 'High Absence Rate', 'Multiple Absences', 
                            'No Recent Attendance', 'Missing Recent Attendance', 'No Attendance Records')
        )
        WHERE sd.ojt_status = 'Active'
        AND work_days_calendar.work_date >= sd.start_date 
        AND work_days_calendar.work_date <= CURDATE()
        AND ra.id IS NULL
        GROUP BY s.id
        HAVING 
            (absent_days >= 3 AND total_expected_days > 0) OR 
            (days_since_last_attendance >= 3 AND last_attendance_date IS NOT NULL) OR
            (total_expected_days >= 5 AND days_with_attendance = 0)
        ORDER BY absent_days DESC, days_since_last_attendance DESC
    ";
    
    $attendance_result = mysqli_query($conn, $attendance_issues_query);
    
    while ($row = mysqli_fetch_assoc($attendance_result)) {
        $attendance_rate = $row['total_expected_days'] > 0 ? 
            round(($row['complete_days'] / $row['total_expected_days']) * 100, 1) : 0;
            
        $severity = 'warning';
        $alert_type = 'Poor Attendance';
        $details = '';
        
        if ($row['absent_days'] >= 10) {
            $severity = 'critical';
            $alert_type = 'Excessive Absences';
            $details = $row['absent_days'] . ' absent days out of ' . $row['total_expected_days'] . ' expected work days';
        } elseif ($row['absent_days'] >= 5) {
            $severity = 'critical';
            $alert_type = 'High Absence Rate';
            $details = $row['absent_days'] . ' absent days out of ' . $row['total_expected_days'] . ' expected work days';
        } elseif ($row['absent_days'] >= 3) {
            $severity = 'warning';
            $alert_type = 'Multiple Absences';
            $details = $row['absent_days'] . ' absent days out of ' . $row['total_expected_days'] . ' expected work days';
        } elseif ($row['days_since_last_attendance'] >= 7) {
            $severity = 'critical';
            $alert_type = 'No Recent Attendance';
            $details = 'No attendance recorded for ' . $row['days_since_last_attendance'] . ' days';
        } elseif ($row['days_since_last_attendance'] >= 3) {
            $severity = 'warning';
            $alert_type = 'Missing Recent Attendance';
            $details = 'No attendance for ' . $row['days_since_last_attendance'] . ' days';
        } elseif ($row['total_expected_days'] >= 5 && $row['days_with_attendance'] == 0) {
            $severity = 'critical';
            $alert_type = 'No Attendance Records';
            $details = 'No attendance recorded since OJT started (' . $row['total_expected_days'] . ' work days expected)';
        }
        
        $additional_info = 'Attendance rate: ' . $attendance_rate . '%';
        if ($row['last_attendance_date']) {
            $additional_info .= ' | Last attendance: ' . date('M j, Y', strtotime($row['last_attendance_date']));
        } else {
            $additional_info .= ' | No attendance records found';
        }
        
        $attendance_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => $alert_type,
            'severity' => $severity,
            'details' => $details,
            'additional_info' => $additional_info,
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Check student welfare and verify attendance'
        ];
    }
    
    // Check for consecutive absences - Modified with resolved check
    $consecutive_absence_query = "
        SELECT 
            s.first_name, 
            s.last_name, 
            s.student_id, 
            s.email, 
            s.id as student_db_id,
            consecutive_absences.consecutive_count,
            consecutive_absences.absence_start,
            consecutive_absences.absence_end,
            consecutive_absences.latest_absence
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN (
            SELECT 
                student_id,
                COUNT(*) as consecutive_count,
                MIN(absence_date) as absence_start,
                MAX(absence_date) as absence_end,
                MAX(absence_date) as latest_absence
            FROM (
                SELECT 
                    sa.student_id,
                    sa.date as absence_date,
                    ROW_NUMBER() OVER (PARTITION BY sa.student_id ORDER BY sa.date) -
                    ROW_NUMBER() OVER (PARTITION BY sa.student_id, (sa.time_in IS NULL AND sa.time_out IS NULL) ORDER BY sa.date) as grp
                FROM student_attendance sa
                WHERE sa.date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                AND (sa.time_in IS NULL AND sa.time_out IS NULL)
            ) grouped_absences
            GROUP BY student_id, grp
            HAVING consecutive_count >= 3
        ) consecutive_absences ON s.id = consecutive_absences.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type = 'Consecutive Absences'
        WHERE sd.ojt_status = 'Active'
        AND ra.id IS NULL
        ORDER BY consecutive_absences.consecutive_count DESC
    ";
    
    $consecutive_result = mysqli_query($conn, $consecutive_absence_query);
    
    while ($row = mysqli_fetch_assoc($consecutive_result)) {
        $severity = ($row['consecutive_count'] >= 5) ? 'critical' : 'warning';
        
        $attendance_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => 'Consecutive Absences',
            'severity' => $severity,
            'details' => $row['consecutive_count'] . ' consecutive days absent',
            'additional_info' => 'From ' . date('M j', strtotime($row['absence_start'])) . ' to ' . date('M j', strtotime($row['absence_end'])),
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Immediate intervention required'
        ];
    }
    
    // Check for pattern of lateness - Modified with resolved check
    $tardiness_pattern_query = "
        SELECT 
            s.first_name, 
            s.last_name, 
            s.student_id, 
            s.email, 
            s.id as student_db_id,
            COUNT(*) as late_days,
            AVG(TIMESTAMPDIFF(MINUTE, 
                ADDTIME(sa.date, cs.work_schedule_start), 
                ADDTIME(sa.date, sa.time_in)
            )) as avg_late_minutes,
            MAX(TIMESTAMPDIFF(MINUTE, 
                ADDTIME(sa.date, cs.work_schedule_start), 
                ADDTIME(sa.date, sa.time_in)
            )) as max_late_minutes
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
        JOIN student_attendance sa ON s.id = sa.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type = 'Frequent Tardiness'
        WHERE sd.ojt_status = 'Active'
        AND sa.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND sa.time_in IS NOT NULL
        AND TIMESTAMPDIFF(MINUTE, 
            ADDTIME(sa.date, cs.work_schedule_start), 
            ADDTIME(sa.date, sa.time_in)
        ) > 15
        AND ra.id IS NULL
        GROUP BY s.id
        HAVING late_days >= 5
        ORDER BY late_days DESC, avg_late_minutes DESC
    ";
    
    $tardiness_result = mysqli_query($conn, $tardiness_pattern_query);
    
    while ($row = mysqli_fetch_assoc($tardiness_result)) {
        $severity = ($row['late_days'] >= 10 || $row['avg_late_minutes'] >= 30) ? 'critical' : 'warning';
        
        $attendance_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => 'Frequent Tardiness',
            'severity' => $severity,
            'details' => $row['late_days'] . ' late arrivals in the past 30 days',
            'additional_info' => 'Average late by ' . round($row['avg_late_minutes']) . ' minutes | Worst: ' . round($row['max_late_minutes']) . ' minutes',
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Discuss punctuality and time management'
        ];
    }
    
    // 3. PERFORMANCE-RELATED ALERTS - Modified with resolved check
    $declining_performance_query = "
        SELECT 
            s.first_name, s.last_name, s.student_id, s.email, s.id as student_db_id,
            se.total_score,
            se.equivalent_rating,
            se.verbal_interpretation,
            se.created_at as evaluation_date,
            DATEDIFF(CURDATE(), se.created_at) as days_since_evaluation
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        JOIN student_evaluations se ON s.id = se.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type IN ('Low Performance Score', 'Critical Performance Issue')
        WHERE sd.ojt_status = 'Active'
        AND (
            se.equivalent_rating < 80 OR 
            se.total_score < 120 OR 
            se.verbal_interpretation IN ('Fair', 'Passed', 'Conditional Passed')
        )
        AND ra.id IS NULL
        ORDER BY se.equivalent_rating ASC, se.total_score ASC
    ";
    $performance_result = mysqli_query($conn, $declining_performance_query);

    while ($row = mysqli_fetch_assoc($performance_result)) {
        $severity = 'warning';
        $alert_type = 'Low Performance Score';
        
        if ($row['equivalent_rating'] < 75 || $row['verbal_interpretation'] === 'Conditional Passed') {
            $severity = 'critical';
            $alert_type = 'Critical Performance Issue';
        } elseif ($row['equivalent_rating'] < 80 || in_array($row['verbal_interpretation'], ['Fair', 'Passed'])) {
            $severity = 'warning';
            $alert_type = 'Low Performance Score';
        }
        
        $performance_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => $alert_type,
            'severity' => $severity,
            'details' => 'Score: ' . $row['total_score'] . '/200 (' . $row['equivalent_rating'] . '%) - ' . $row['verbal_interpretation'],
            'additional_info' => 'Evaluated: ' . date('M j, Y', strtotime($row['evaluation_date'])) . ' (' . $row['days_since_evaluation'] . ' days ago)',
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Review evaluation and provide additional support'
        ];
    }
    
    // 4. SYSTEM-RELATED ALERTS - Modified with resolved check
    $inactive_students_query = "
        SELECT 
            s.first_name, s.last_name, s.student_id, s.email, s.id as student_db_id,
            COALESCE(s.last_login, s.created_at) as last_activity,
            DATEDIFF(CURDATE(), COALESCE(s.last_login, s.created_at)) as days_inactive,
            s.status,
            sd.start_date
        FROM students s
        JOIN student_deployments sd ON s.id = sd.student_id
        LEFT JOIN resolved_alerts ra ON s.id = ra.student_id AND ra.alert_type = 'System Inactivity'
        WHERE sd.ojt_status = 'Active'
        AND s.status = 'Active'
        AND DATEDIFF(CURDATE(), COALESCE(s.last_login, s.created_at)) >= 7
        AND ra.id IS NULL
        ORDER BY days_inactive DESC
    ";
    $inactive_result = mysqli_query($conn, $inactive_students_query);
    
    while ($row = mysqli_fetch_assoc($inactive_result)) {
        $severity = ($row['days_inactive'] >= 14) ? 'critical' : 'warning';
        
        $system_alerts[] = [
            'student_name' => $row['first_name'] . ' ' . $row['last_name'],
            'student_id' => $row['student_id'],
            'student_email' => $row['email'],
            'student_db_id' => $row['student_db_id'],
            'type' => 'System Inactivity',
            'severity' => $severity,
            'details' => 'No system login for ' . $row['days_inactive'] . ' days',
            'additional_info' => 'Last login: ' . date('M j, Y', strtotime($row['last_activity'])),
            'date_detected' => date('M j, Y'),
            'raw_date' => date('Y-m-d H:i:s'),
            'action_needed' => 'Check if student needs technical support'
        ];
    }

    // Combine all alerts and sort
    $all_alerts = array_merge($task_alerts, $attendance_alerts, $performance_alerts, $system_alerts);
    
    // Sort by severity and date
    usort($all_alerts, function($a, $b) {
        $severity_order = ['critical' => 1, 'warning' => 2, 'info' => 3];
        if ($severity_order[$a['severity']] != $severity_order[$b['severity']]) {
            return $severity_order[$a['severity']] - $severity_order[$b['severity']];
        }
        return strtotime($b['raw_date']) - strtotime($a['raw_date']);
    });

} catch (Exception $e) {
    $error_message = "Error fetching alerts: " . $e->getMessage();
}
// Get alert counts
$total_alerts = count($all_alerts);
$critical_alerts = count(array_filter($all_alerts, function($alert) { return $alert['severity'] === 'critical'; }));
$warning_alerts = count(array_filter($all_alerts, function($alert) { return $alert['severity'] === 'warning'; }));
$info_alerts = count(array_filter($all_alerts, function($alert) { return $alert['severity'] === 'info'; }));

// Get category counts
$task_count = count($task_alerts);
$attendance_count = count($attendance_alerts);
$performance_count = count($performance_alerts);
$system_count = count($system_alerts);

// Create adviser initials
$adviser_initials = strtoupper(substr($adviser_name, 0, 2));

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
    <title>OnTheJob Tracker - Administrative Alerts</title>
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
        /* Custom CSS for enhanced features */
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }

        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notification-message {
    white-space: pre-line;
    word-wrap: break-word;
    line-height: 1.6;
    font-family: 'Segoe UI', system-ui, sans-serif;
}

        /* Alert severity indicators */
        .severity-critical {
            @apply bg-red-500;
        }
        .severity-warning {
            @apply bg-yellow-500;
        }
        .severity-info {
            @apply bg-blue-500;
        }

        /* Custom animations */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

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
            <a href="AdminAlerts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-bell mr-3 text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Administrative Alerts</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Monitor deployed students' tasks, attendance, performance, and system issues</p>
                </div>
                
                <!-- Header Actions -->
                <div class="flex items-center space-x-4">
                    <button onclick="refreshAlerts()" class="flex items-center px-3 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                        <i class="fas fa-sync-alt mr-2"></i>
                        <span class="hidden sm:inline">Refresh</span>
                    </button>
                    
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

            <!-- Alert Summary -->
            <?php if ($total_alerts > 0): ?>
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-blue-600 mt-1 mr-3"></i>
                        <div>
                            <h3 class="text-lg font-semibold text-blue-800 mb-2">Alert Summary</h3>
                            <p class="text-blue-700">
                                You have <strong><?php echo $total_alerts; ?></strong> active alerts requiring attention. 
                                <strong><?php echo $critical_alerts; ?></strong> are critical and need immediate action.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            
            <!-- Category Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tasks text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h4 class="text-lg font-semibold text-gray-900">Task Issues</h4>
                            <p class="text-sm text-gray-600"><?php echo $task_count; ?> alerts</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-check text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h4 class="text-lg font-semibold text-gray-900">Attendance Issues</h4>
                            <p class="text-sm text-gray-600"><?php echo $attendance_count; ?> alerts</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h4 class="text-lg font-semibold text-gray-900">Performance Issues</h4>
                            <p class="text-sm text-gray-600"><?php echo $performance_count; ?> alerts</p>
                        </div>
                    </div>
                </div>

                
            </div>

            <!-- Filter Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                        <div class="flex-1 max-w-md">
                            <div class="relative">
                                <input type="text" id="searchInput" placeholder="Search by student name, ID, or alert type..." 
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                            </div>
                        </div>
                        
<!-- Replace the existing filter buttons section with this -->
<div class="flex flex-wrap gap-2">
    <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors active" data-filter="all">
        <i class="fas fa-list mr-2"></i> All Alerts
    </button>
    <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-filter="critical">
        <i class="fas fa-exclamation-triangle mr-2"></i> Critical
    </button>
    <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-filter="warning">
        <i class="fas fa-exclamation-circle mr-2"></i> Warning
    </button>
</div>
                    </div>
                    
                    <div class="flex flex-wrap gap-2">
                        <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-filter="task">
                            <i class="fas fa-tasks mr-2"></i> Task Issues
                        </button>
                        <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-filter="attendance">
                            <i class="fas fa-calendar-check mr-2"></i> Attendance
                        </button>
                        <button class="filter-btn flex items-center px-3 py-2 text-sm font-medium bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors" data-filter="performance">
                            <i class="fas fa-chart-line mr-2"></i> Performance
                        </button>
                       
                    </div>
                </div>
            </div>

            <!-- Alerts Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <?php if ($total_alerts > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="w-4 px-6 py-3"></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Information</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alert Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Detected</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="alertsTableBody" class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_alerts as $index => $alert): 
                                    // Determine category for filtering
                                    $category = 'system';
                                    if (strpos($alert['type'], 'Task') !== false || strpos($alert['type'], 'Submission') !== false || strpos($alert['type'], 'Rejection') !== false) {
                                        $category = 'task';
                                    } elseif (strpos($alert['type'], 'Attendance') !== false || strpos($alert['type'], 'Absence') !== false || strpos($alert['type'], 'Tardiness') !== false) {
                                        $category = 'attendance';
                                    } elseif (strpos($alert['type'], 'Performance') !== false || strpos($alert['type'], 'Evaluation') !== false) {
                                        $category = 'performance';
                                    }
                                    
                                    $severityColor = '';
                                    switch($alert['severity']) {
                                        case 'critical':
                                            $severityColor = 'bg-red-500';
                                            break;
                                        case 'warning':
                                            $severityColor = 'bg-yellow-500';
                                            break;
                                        case 'info':
                                            $severityColor = 'bg-blue-500';
                                            break;
                                    }
                                ?>
                                    <tr class="alert-row hover:bg-gray-50 transition-colors" 
                                        data-severity="<?php echo $alert['severity']; ?>"
                                        data-type="<?php echo $alert['type']; ?>"
                                        data-category="<?php echo $category; ?>"
                                        data-student-id="<?php echo $alert['student_db_id']; ?>">
                                        <td class="px-6 py-4">
                                            <div class="w-3 h-3 rounded-full <?php echo $severityColor; ?>"></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-r from-green-500 to-teal-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                                    <?php echo strtoupper(substr($alert['student_name'], 0, 1)); ?>
                                                </div>
                                                <div class="ml-4">
                                                    <h4 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($alert['student_name']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($alert['student_id']); ?> • <?php echo htmlspecialchars($alert['student_email']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?php echo $alert['severity'] === 'critical' ? 'bg-red-100 text-red-800' : 
                                                         ($alert['severity'] === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800'); ?>">
                                                <?php echo htmlspecialchars($alert['type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($alert['details']); ?></div>
                                            <?php if (isset($alert['additional_info'])): ?>
                                                <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($alert['additional_info']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($alert['date_detected']); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-2">
                                                <button onclick="showNotificationModal(<?php echo $alert['student_db_id']; ?>, '<?php echo addslashes($alert['student_name']); ?>', '<?php echo addslashes($alert['type']); ?>', '<?php echo addslashes($alert['details']); ?>')"
                                                        class="inline-flex items-center px-3 py-2 text-xs font-medium text-blue-600 bg-blue-100 rounded-md hover:bg-blue-200 transition-colors">
                                                    <i class="fas fa-bell mr-1"></i> Notify
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-check-circle text-green-500 text-6xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Active Alerts</h3>
                        <p class="text-gray-600">Excellent! All students are performing well with no critical issues detected.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification Modal -->
    <div id="notificationModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto" style="animation: modalSlideIn 0.3s ease-out;">
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4 rounded-t-lg">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white">
                            <i class="fas fa-bell mr-2"></i> Send Notification
                        </h3>
                        <button onclick="closeNotificationModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-full p-2 transition-colors">
                            <i class="fas fa-times text-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6">
                    <form id="notificationForm">
                        <input type="hidden" id="notificationStudentId" name="student_id">
                        
                        <div class="mb-4">
                            <label for="notificationTitle" class="block text-sm font-medium text-gray-700 mb-2">Title:</label>
                            <input type="text" id="notificationTitle" name="title" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div class="mb-4">
                            <label for="notificationMessage" class="block text-sm font-medium text-gray-700 mb-2">Message:</label>
                            <textarea id="notificationMessage" name="message" rows="15" required
          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 notification-message"
          style="font-size: 14px; line-height: 1.5;"></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label for="notificationType" class="block text-sm font-medium text-gray-700 mb-2">Type:</label>
                            <select id="notificationType" name="type" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="general">General</option>
                                <option value="system">System Alert</option>
                            </select>
                        </div>
                        
                        <div class="bg-gray-50 p-4 rounded-lg border-l-4 border-blue-500 mb-4">
                            <p class="text-sm text-gray-700"><strong>Student:</strong> <span id="studentNameDisplay"></span></p>
                            <p class="text-sm text-gray-700 mt-1"><strong>Alert:</strong> <span id="alertTypeDisplay"></span></p>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-lg flex justify-end space-x-3">
                    <button type="button" onclick="closeNotificationModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <button type="button" onclick="sendNotification()" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors">
                        <i class="fas fa-paper-plane mr-2"></i> Send Notification
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Administrative Alerts JavaScript Functions
        document.addEventListener('DOMContentLoaded', function() {
    initializeFilters();
    initializeSearch();
    setupEventListeners();
    updateFilterCounts(); // Add this line
});

        // Mobile menu functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('closeSidebar').addEventListener('click', closeSidebar);
        document.getElementById('sidebarOverlay').addEventListener('click', closeSidebar);

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Profile dropdown functionality
        document.getElementById('profileBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const profileDropdown = document.getElementById('profileDropdown');
            if (!e.target.closest('#profileBtn') && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Initialize filter functionality
        function initializeFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons
            filterButtons.forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-200', 'text-gray-700');
            });
            // Add active class to clicked button
            this.classList.remove('bg-gray-200', 'text-gray-700');
            this.classList.add('bg-blue-600', 'text-white');
            
            const filterType = this.getAttribute('data-filter');
            filterAlerts(filterType);
        });
    });
}

       function filterAlerts(filterType) {
    const alertRows = document.querySelectorAll('.alert-row');
    let visibleCount = 0;

    alertRows.forEach(row => {
        let shouldShow = true;
        const isResolved = row.hasAttribute('data-resolved');
        const severity = row.getAttribute('data-severity');
        const category = row.getAttribute('data-category');

        if (filterType === 'all') {
            // Show all alerts (both resolved and unresolved)
            shouldShow = true;
        } else if (filterType === 'resolved') {
            // Show only resolved alerts
            shouldShow = isResolved;
        } else if (['critical', 'warning', 'info'].includes(filterType)) {
            // Show alerts of specific severity (both resolved and unresolved)
            shouldShow = severity === filterType;
        } else if (['task', 'attendance', 'performance', 'system'].includes(filterType)) {
            // Show alerts of specific category (both resolved and unresolved)
            shouldShow = category === filterType;
        }

        if (shouldShow) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    toggleNoResultsMessage(visibleCount === 0);
    updateFilterCounts();
}

// New function to update filter button counts
function updateFilterCounts() {
    const allRows = document.querySelectorAll('.alert-row');
    const resolvedRows = document.querySelectorAll('.alert-row[data-resolved="true"]');
    const criticalRows = document.querySelectorAll('.alert-row[data-severity="critical"]');
    const warningRows = document.querySelectorAll('.alert-row[data-severity="warning"]');
    
    const buttons = document.querySelectorAll('.filter-btn');
    buttons.forEach(button => {
        const filter = button.getAttribute('data-filter');
        const icon = button.querySelector('i').outerHTML;
        let text = '';
        let count = 0;
        
        switch(filter) {
            case 'all':
                text = 'All Alerts';
                count = allRows.length;
                break;
            case 'resolved':
                text = 'Resolved Only';
                count = resolvedRows.length;
                break;
            case 'critical':
                text = 'Critical';
                count = criticalRows.length;
                break;
            case 'warning':
                text = 'Warning';
                count = warningRows.length;
                break;
        }
        
        if (count > 0) {
            button.innerHTML = `${icon} ${text} (${count})`;
        } else {
            button.innerHTML = `${icon} ${text}`;
        }
    });
}

        // Initialize search functionality
        function initializeSearch() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase().trim();
                    searchAlerts(searchTerm);
                });
            }
        }

        // Search through alerts
        function searchAlerts(searchTerm) {
            const alertRows = document.querySelectorAll('.alert-row');
            let visibleCount = 0;

            alertRows.forEach(row => {
                const studentName = row.querySelector('h4').textContent.toLowerCase();
                const studentInfo = row.querySelector('p').textContent.toLowerCase();
                const alertType = row.querySelector('.inline-flex').textContent.toLowerCase();
                const alertDetails = row.querySelector('.text-sm.text-gray-900').textContent.toLowerCase();

                const searchableText = `${studentName} ${studentInfo} ${alertType} ${alertDetails}`;
                
                if (searchTerm === '' || searchableText.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            toggleNoResultsMessage(visibleCount === 0);
        }

        // Toggle no results message
        function toggleNoResultsMessage(show) {
            let noResultsMsg = document.getElementById('noResultsMessage');
            
            if (show) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('tr');
                    noResultsMsg.id = 'noResultsMessage';
                    noResultsMsg.innerHTML = `
                        <td colspan="6" class="text-center py-12">
                            <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">No alerts found</h3>
                            <p class="text-gray-600">Try adjusting your search criteria or filters</p>
                        </td>
                    `;
                    document.getElementById('alertsTableBody').appendChild(noResultsMsg);
                }
                noResultsMsg.style.display = '';
            } else {
                if (noResultsMsg) {
                    noResultsMsg.style.display = 'none';
                }
            }
        }

        // Setup additional event listeners
        function setupEventListeners() {
            // Close modal when clicking outside or pressing escape
            document.addEventListener('click', function(event) {
                const modal = document.getElementById('notificationModal');
                if (event.target === modal) {
                    closeNotificationModal();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(event) {
                // Ctrl/Cmd + F to focus search
                if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
                    event.preventDefault();
                    document.getElementById('searchInput').focus();
                }
                
                // Escape to clear search or close modal
                if (event.key === 'Escape') {
                    const modal = document.getElementById('notificationModal');
                    if (!modal.classList.contains('hidden')) {
                        closeNotificationModal();
                    } else {
                        const searchInput = document.getElementById('searchInput');
                        if (searchInput.value) {
                            searchInput.value = '';
                            searchAlerts('');
                        }
                    }
                }
            });
        }

        // Logout confirmation
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Refresh alerts
        function refreshAlerts() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'flex';
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }

        // Replace your existing showNotificationModal function with this improved version

function showNotificationModal(studentId, studentName, alertType, alertDetails, assessmentData = null) {
    const modal = document.getElementById('notificationModal');
    const studentNameDisplay = document.getElementById('studentNameDisplay');
    const alertTypeDisplay = document.getElementById('alertTypeDisplay');
    const notificationStudentId = document.getElementById('notificationStudentId');
    const notificationTitle = document.getElementById('notificationTitle');
    const notificationMessage = document.getElementById('notificationMessage');
    
    // Set student information
    studentNameDisplay.textContent = studentName;
    alertTypeDisplay.textContent = alertType;
    notificationStudentId.value = studentId;
    
    // Pre-fill notification content based on alert type
    notificationTitle.value = `Alert: ${alertType}`;
    
    let messageContent = '';
    
    // Simple, direct messages based on alert type
    switch (alertType) {
        case 'Consecutive Absences':
        case 'Excessive Absences':
        case 'High Absence Rate':
        case 'Multiple Absences':
            messageContent = `Hi ${studentName}, I noticed you have ${alertDetails.toLowerCase()}. This is affecting your OJT progress. Please contact me within 24 hours so we can discuss what's causing the absences, find solutions to help you attend regularly, and ensure you meet program requirements. I'm here to support you and we can work together to get you back on track. Your Academic Adviser`;
            break;
            
        case 'Overdue Tasks':
            messageContent = `Hi ${studentName}, You currently have ${alertDetails.toLowerCase()}. Meeting deadlines is important for your OJT success. Please reach out within 24 hours so we can review your current workload, identify any challenges you're facing, and create a plan to catch up. I'm here to help you succeed. Your Academic Adviser`;
            break;
            
        case 'No Task Submissions':
            messageContent = `Hi ${studentName}, I noticed ${alertDetails.toLowerCase()}. This may impact your OJT evaluation. Please contact me within 48 hours so we can discuss any technical or other issues, review pending tasks, and get back on track with submissions. Let's resolve this together. Your Academic Adviser`;
            break;
            
        case 'Low Performance Score':
        case 'Critical Performance Issue':
            messageContent = `Hi ${studentName}, Your recent evaluation shows ${alertDetails.toLowerCase()}. This is an opportunity for improvement. Let's schedule a meeting this week so we can review your evaluation feedback, identify areas for growth, and create an improvement plan. I believe you can improve with the right support. Your Academic Adviser`;
            break;
            
        case 'Frequent Tardiness':
            messageContent = `Hi ${studentName}, I noticed ${alertDetails.toLowerCase()}. Punctuality is important for professional development. Please contact me so we can discuss any challenges causing lateness, work on time management strategies, and improve your attendance pattern. Your Academic Adviser`;
            break;
            
        case 'System Inactivity':
            messageContent = `Hi ${studentName}, You haven't logged into the system for ${alertDetails.toLowerCase()}. You might be missing important updates. Please contact me if you need help with password reset, technical support, or system navigation. Let's ensure you stay connected. Your Academic Adviser`;
            break;
            
        default:
            messageContent = `Hi ${studentName}, I'm reaching out regarding: ${alertDetails}. Please contact me within 48 hours so we can discuss this matter and provide any support you need. I'm here to help you succeed in your OJT program. Your Academic Adviser`;
    }
    
    // Set the message
    notificationMessage.value = messageContent;
    modal.classList.remove('hidden');
    
    // Store assessment data for potential use
    if (assessmentData) {
        modal.setAttribute('data-assessment', JSON.stringify(assessmentData));
    }
}

        // Close notification modal
        function closeNotificationModal() {
            const modal = document.getElementById('notificationModal');
            modal.classList.add('hidden');
            
            // Reset form
            document.getElementById('notificationForm').reset();
        }

        // Send notification
        function sendNotification() {
            const form = document.getElementById('notificationForm');
            const formData = new FormData(form);
            formData.append('action', 'send_notification');
            
            // Show loading state
            const sendButton = event.target;
            const originalText = sendButton.innerHTML;
            sendButton.innerHTML = '<i class="fas fa-spinner animate-spin mr-2"></i> Sending...';
            sendButton.disabled = true;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                sendButton.innerHTML = originalText;
                sendButton.disabled = false;
                
                if (data.success) {
                    showNotification('Notification sent successfully!', 'success');
                    closeNotificationModal();
                } else {
                    showNotification('Failed to send notification: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error sending notification:', error);
                sendButton.innerHTML = originalText;
                sendButton.disabled = false;
                showNotification('Error sending notification: ' + error.message, 'error');
            });
        }

        // Mark alert as resolved
// Modified markAsResolved function
function markAsResolved(button, studentId, alertType) {
    if (confirm(`Mark this ${alertType} alert as resolved?`)) {
        const row = button.closest('tr');
        const alertDetails = row.querySelector('.text-sm.text-gray-900').textContent;
        
        // Visual feedback
        button.innerHTML = '<i class="fas fa-spinner animate-spin mr-1"></i> Resolving...';
        button.disabled = true;
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'resolve_alert');
        formData.append('student_id', row.getAttribute('data-student-id'));
        formData.append('alert_type', alertType);
        formData.append('alert_details', alertDetails);
        
        // Send resolve request
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Mark row as resolved instead of removing
                markRowAsResolved(row, button);
                showNotification('Alert marked as resolved successfully', 'success');
            } else {
                button.innerHTML = '<i class="fas fa-check mr-1"></i> Resolve';
                button.disabled = false;
                showNotification('Failed to resolve alert: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            button.innerHTML = '<i class="fas fa-check mr-1"></i> Resolve';
            button.disabled = false;
            showNotification('Error resolving alert: ' + error.message, 'error');
        });
    }
}

function markRowAsResolved(row, button) {
    // Add resolved styling to the row
    row.classList.add('bg-green-50', 'border-l-4', 'border-green-400');
    
    // Update the severity indicator to show resolved status
    const severityIndicator = row.querySelector('.w-3.h-3.rounded-full');
    severityIndicator.className = 'w-3 h-3 rounded-full bg-green-500';
    
    // Update the alert type badge to show resolved status
    const alertTypeBadge = row.querySelector('.inline-flex.items-center');
    const originalText = alertTypeBadge.textContent;
    alertTypeBadge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800';
    alertTypeBadge.innerHTML = `<i class="fas fa-check-circle mr-1"></i>${originalText} (Resolved)`;
    
    // Add resolved timestamp to details
    const detailsDiv = row.querySelector('.text-sm.text-gray-900').parentElement;
    const resolvedTime = document.createElement('div');
    resolvedTime.className = 'text-sm text-green-600 mt-1 font-medium';
    resolvedTime.innerHTML = '<i class="fas fa-check mr-1"></i>Resolved on ' + new Date().toLocaleDateString() + ' at ' + new Date().toLocaleTimeString();
    detailsDiv.appendChild(resolvedTime);
    
    // Replace action buttons with resolved status
    const actionsCell = row.querySelector('td:last-child');
    actionsCell.innerHTML = `
        <div class="flex items-center space-x-2">
            <span class="inline-flex items-center px-3 py-2 text-xs font-medium text-green-600 bg-green-100 rounded-md">
                <i class="fas fa-check-circle mr-1"></i> Resolved
            </span>
            <button onclick="unresolveAlert(this)" 
                    class="inline-flex items-center px-3 py-2 text-xs font-medium text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
                    title="Mark as unresolved">
                <i class="fas fa-undo mr-1"></i> Unresolve
            </button>
        </div>
    `;
    
    // Update row data attributes
    row.setAttribute('data-resolved', 'true');
    row.setAttribute('data-resolved-date', new Date().toISOString());
    
    // Update filter counts after marking as resolved
    updateFilterCounts();
}

function unresolveAlert(button) {
    if (confirm('Mark this alert as unresolved? This will restore the original alert actions.')) {
        const row = button.closest('tr');
        
        // Remove resolved styling
        row.classList.remove('bg-gray-50', 'opacity-75');
        
        // Get original alert data
        const severity = row.getAttribute('data-severity');
        const alertType = row.getAttribute('data-type');
        const studentDbId = row.getAttribute('data-student-id');
        const studentName = row.querySelector('h4').textContent;
        
        // Restore original severity indicator
        const severityIndicator = row.querySelector('.w-3.h-3.rounded-full');
        const severityColor = severity === 'critical' ? 'bg-red-500' : 
                             severity === 'warning' ? 'bg-yellow-500' : 'bg-blue-500';
        severityIndicator.className = `w-3 h-3 rounded-full ${severityColor}`;
        
        // Restore original alert type badge
        const alertTypeBadge = row.querySelector('.inline-flex.items-center');
        const badgeColor = severity === 'critical' ? 'bg-red-100 text-red-800' : 
                          severity === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800';
        alertTypeBadge.className = `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${badgeColor}`;
        alertTypeBadge.textContent = alertType;
        
        // Remove resolved timestamp
        const resolvedTime = row.querySelector('.text-green-600');
        if (resolvedTime) {
            resolvedTime.remove();
        }
        
        // Restore action buttons
        const actionsCell = row.querySelector('td:last-child');
        actionsCell.innerHTML = `
            <div class="flex space-x-2">
                <button onclick="showNotificationModal(${studentDbId}, '${studentName.replace(/'/g, "\\'")}', '${alertType.replace(/'/g, "\\'")}', 'Alert details')"
                        class="inline-flex items-center px-3 py-2 text-xs font-medium text-blue-600 bg-blue-100 rounded-md hover:bg-blue-200 transition-colors">
                    <i class="fas fa-bell mr-1"></i> Notify
                </button>
                <button onclick="markAsResolved(this, '${row.querySelector('p').textContent.split(' • ')[0]}', '${alertType.replace(/'/g, "\\'")}')"
                        class="inline-flex items-center px-3 py-2 text-xs font-medium text-green-600 bg-green-100 rounded-md hover:bg-green-200 transition-colors">
                    <i class="fas fa-check mr-1"></i> Resolve
                </button>
            </div>
        `;
        
        // Remove resolved data attributes
        row.removeAttribute('data-resolved');
        row.removeAttribute('data-resolved-date');
        
        showNotification('Alert marked as unresolved', 'success');
    }
}
        // Update alert counts after resolution
        function updateAlertCounts() {
            const visibleRows = document.querySelectorAll('.alert-row[style=""], .alert-row:not([style*="display: none"])');
            const totalCount = visibleRows.length;
            
            let criticalCount = 0, warningCount = 0, infoCount = 0;
            
            visibleRows.forEach(row => {
                const severity = row.getAttribute('data-severity');
                if (severity === 'critical') criticalCount++;
                else if (severity === 'warning') warningCount++;
                else if (severity === 'info') infoCount++;
            });
            
            // Update the page if all alerts are resolved
            if (totalCount === 0) {
                const tableContainer = document.querySelector('.bg-white.rounded-lg.shadow-sm.border.border-gray-200:last-child');
                tableContainer.innerHTML = `
                    <div class="text-center py-12">
                        <i class="fas fa-check-circle text-green-500 text-6xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">All Alerts Resolved!</h3>
                        <p class="text-gray-600">Great work! You've addressed all student alerts.</p>
                    </div>
                `;
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification-toast');
            existingNotifications.forEach(notif => notif.remove());
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'notification-toast fixed top-4 right-4 z-50 max-w-sm';
            
            const bgColor = type === 'success' ? 'bg-green-500' : 
                           type === 'error' ? 'bg-red-500' : 'bg-blue-500';
            const icon = type === 'success' ? 'fa-check-circle' : 
                        type === 'error' ? 'fa-exclamation-triangle' : 'fa-info-circle';
            
            notification.innerHTML = `
                <div class="${bgColor} text-white p-4 rounded-lg shadow-lg flex items-center space-x-3" style="animation: slideInRight 0.3s ease;">
                    <i class="fas ${icon} text-lg"></i>
                    <span class="flex-1">${message}</span>
                    <button onclick="this.closest('.notification-toast').remove()" class="text-white hover:text-gray-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Auto-refresh alerts every 5 minutes if user is active
        setInterval(() => {
            if (document.hasFocus()) {
                // You could implement a silent refresh here if needed
                console.log('Auto-refresh check - page is active');
            }
        }, 300000); // 5 minutes

        // Handle escape key and other keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('notificationModal');
                if (!modal.classList.contains('hidden')) {
                    closeNotificationModal();
                }
            }
        });
    </script>
</body>
</html>