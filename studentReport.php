<?php
include('connect.php');
date_default_timezone_set('Asia/Manila');
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
$conn->query("SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Fetch student data from database
try {
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, email, student_id, department, program, year_level, section, profile_picture FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        
        // Build full name
        $full_name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
        $first_name = $student['first_name'];
        $middle_name = $student['middle_name'];
        $last_name = $student['last_name'];
        $email = $student['email'];
        $student_id = $student['student_id'];
        $department = $student['department'];
        $program = $student['program'];
        $year_level = $student['year_level'];
        $section = $student['section'];
        $profile_picture = $student['profile_picture'];
        
        // Create initials for avatar
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

// Enhanced deployment status check function (same as studentAttendance.php)
function checkDeploymentStatus($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT sd.*, cs.company_name, cs.company_address, cs.work_schedule_start, cs.work_schedule_end, cs.work_days, cs.internship_start_date, cs.internship_end_date FROM student_deployments sd JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id WHERE sd.student_id = ? AND sd.status = 'Active'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $deployment = $result->fetch_assoc();
            
            // Check if current date is within the internship period
            $today = date('Y-m-d');
            $start_date = $deployment['start_date'];
            $end_date = $deployment['end_date'];
            
            // Calculate total completed hours from attendance records
            $hours_stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as total_completed_hours FROM student_attendance WHERE student_id = ? AND deployment_id = ?");
            $hours_stmt->bind_param("ii", $user_id, $deployment['deployment_id']);
            $hours_stmt->execute();
            $hours_result = $hours_stmt->get_result();
            $hours_data = $hours_result->fetch_assoc();
            $completed_hours = (float)$hours_data['total_completed_hours'];
            $hours_stmt->close();
            
            // Update completed_hours in database
            $update_hours_stmt = $conn->prepare("UPDATE student_deployments SET completed_hours = ? WHERE deployment_id = ?");
            $update_hours_stmt->bind_param("di", $completed_hours, $deployment['deployment_id']);
            $update_hours_stmt->execute();
            $update_hours_stmt->close();
            
            // Check if OJT should be marked as completed
            $required_hours = (int)$deployment['required_hours'];
            $hours_completed = $completed_hours >= $required_hours;
            $date_ended = $today > $end_date;
            
            // Auto-update OJT status to Completed if requirements are met
            if ($hours_completed || $date_ended) {
                if ($deployment['ojt_status'] !== 'Completed') {
                    $completion_reason = $hours_completed ? 'Required hours completed' : 'End date reached';
                    $update_status_stmt = $conn->prepare("UPDATE student_deployments SET ojt_status = 'Completed', status = 'Completed' WHERE deployment_id = ?");
                    $update_status_stmt->bind_param("i", $deployment['deployment_id']);
                    $update_status_stmt->execute();
                    $update_status_stmt->close();
                    
                    // Update deployment array to reflect new status
                    $deployment['ojt_status'] = 'Completed';
                    $deployment['status'] = 'Completed';
                }
            }
            
            // Add comprehensive status flags
            $deployment['can_record_today'] = (
                $deployment['ojt_status'] === 'Active' && 
                $today >= $start_date && 
                $today <= $end_date &&
                !$hours_completed
            );
            
            $deployment['is_before_start'] = ($today < $start_date);
            $deployment['is_after_end'] = ($today > $end_date);
            $deployment['is_ojt_completed'] = ($deployment['ojt_status'] === 'Completed');
            $deployment['is_hours_completed'] = $hours_completed;
            $deployment['days_until_start'] = $today < $start_date ? (strtotime($start_date) - strtotime($today)) / (60 * 60 * 24) : 0;
            $deployment['completed_hours'] = $completed_hours;
            $deployment['hours_progress_percentage'] = $required_hours > 0 ? min(100, ($completed_hours / $required_hours) * 100) : 0;
            
            return $deployment;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Deployment check error: " . $e->getMessage());
        return false;
    }
}

$is_deployed = checkDeploymentStatus($conn, $user_id);

// Enhanced Attendance Tracking with Company Work Schedule and OJT Completion
$all_attendance = [];
$attendance_stats = [
    'total_work_days' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'late_days' => 0,
    'total_hours_completed' => 0,
    'attendance_rate' => 0,
    'hours_progress' => 0
];

if ($is_deployed) {
    // Get work schedule from company supervisor
    $work_schedule_start = $is_deployed['work_schedule_start']; // e.g., "08:00:00"
    $work_schedule_end = $is_deployed['work_schedule_end'];     // e.g., "17:00:00"
    $work_days_string = $is_deployed['work_days'];             // e.g., "Monday,Tuesday,Wednesday,Thursday,Friday"
    
    // Parse work days
    $work_days_array = array_map('trim', explode(',', strtolower($work_days_string)));
    $work_days_map = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 
        'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0
    ];
    
    // Generate date range from internship start to appropriate end date
    $start_date = new DateTime($is_deployed['start_date']);
    $end_date = new DateTime($is_deployed['end_date']);
    $today_dt = new DateTime();
    
    // Determine the cutoff date based on OJT completion status
    if ($is_deployed['is_ojt_completed']) {
        // For completed OJT, show history up to the last attendance record or end date
        $last_attendance_stmt = $conn->prepare("SELECT MAX(date) as last_date FROM student_attendance WHERE student_id = ? AND deployment_id = ?");
        $last_attendance_stmt->bind_param("ii", $user_id, $is_deployed['deployment_id']);
        $last_attendance_stmt->execute();
        $last_result = $last_attendance_stmt->get_result();
        $last_data = $last_result->fetch_assoc();
        $last_attendance_stmt->close();
        
        if ($last_data['last_date']) {
            $last_attendance_date = new DateTime($last_data['last_date']);
            $limit_date = ($end_date < $last_attendance_date) ? $end_date : $last_attendance_date;
        } else {
            $limit_date = ($end_date < $today_dt) ? $end_date : $today_dt;
        }
    } else {
        // For active OJT, don't go beyond today's date
        $limit_date = ($end_date < $today_dt) ? $end_date : $today_dt;
    }
    
    // Loop through each day in the internship period
    $current_date = clone $start_date;
    while ($current_date <= $limit_date) {
        $day_name = strtolower($current_date->format('l'));
        $date_string = $current_date->format('Y-m-d');
        
        // Check if this day is a working day according to company schedule
        if (in_array($day_name, $work_days_array)) {
            $attendance_stats['total_work_days']++;
            
            // Check if student has attendance record for this day
            $attendance_stmt = $conn->prepare("SELECT * FROM student_attendance WHERE student_id = ? AND date = ?");
            $attendance_stmt->bind_param("is", $user_id, $date_string);
            $attendance_stmt->execute();
            $attendance_result = $attendance_stmt->get_result();
            
            if ($attendance_result->num_rows > 0) {
                // Student has record - check if late
                $record = $attendance_result->fetch_assoc();
                $attendance_stats['present_days']++;
                
                // Calculate if late based on company work schedule
                if ($record['time_in'] && $work_schedule_start) {
                    $time_in = new DateTime($date_string . ' ' . $record['time_in']);
                    $scheduled_start = new DateTime($date_string . ' ' . $work_schedule_start);
                    
                    // Add 15-minute grace period
                    $scheduled_start->modify('+15 minutes');
                    
                    if ($time_in > $scheduled_start) {
                        $minutes_late = ($time_in->getTimestamp() - $scheduled_start->getTimestamp()) / 60;
                        $record['is_late'] = true;
                        $record['minutes_late'] = round($minutes_late);
                        $attendance_stats['late_days']++;
                    } else {
                        $record['is_late'] = false;
                        $record['minutes_late'] = 0;
                    }
                }
                
                // Add to total hours
                $attendance_stats['total_hours_completed'] += (float)($record['total_hours'] ?? 0);
                
                $all_attendance[] = $record;
            } else {
                // Student is absent - but only if the OJT is not completed yet
                // If OJT is completed, don't count future dates as absent
                if (!$is_deployed['is_ojt_completed'] || $current_date <= $today_dt) {
                    $attendance_stats['absent_days']++;
                    $all_attendance[] = [
                        'attendance_id' => null,
                        'student_id' => $user_id,
                        'date' => $date_string,
                        'time_in' => null,
                        'time_out' => null,
                        'total_hours' => 0,
                        'status' => 'Absent',
                        'is_absent' => true,
                        'is_late' => false,
                        'minutes_late' => 0,
                        'day_name' => ucfirst($day_name),
                        'scheduled_start' => $work_schedule_start,
                        'scheduled_end' => $work_schedule_end,
                        'attendance_method' => null,
                        'notes' => 'No attendance recorded'
                    ];
                }
            }
            $attendance_stmt->close();
        }
        
        // Move to next day
        $current_date->modify('+1 day');
    }
    
    // Calculate statistics
    if ($attendance_stats['total_work_days'] > 0) {
        $attendance_stats['attendance_rate'] = ($attendance_stats['present_days'] / $attendance_stats['total_work_days']) * 100;
    }
    
    if ($is_deployed['required_hours'] > 0) {
        $attendance_stats['hours_progress'] = ($attendance_stats['total_hours_completed'] / $is_deployed['required_hours']) * 100;
    }
    
    // Sort attendance by date (newest first for report)
    usort($all_attendance, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
}

// Update variables for compatibility with existing code
$attendance_records = $all_attendance;
$total_hours = $attendance_stats['total_hours_completed'];
$required_hours = $is_deployed ? $is_deployed['required_hours'] : 400;
$progress_percentage = $required_hours > 0 ? round(($total_hours / $required_hours) * 100, 1) : 0;

// Update attendance stats for compatibility with chart data
$old_attendance_stats = [
    'present' => $attendance_stats['present_days'],
    'late' => $attendance_stats['late_days'], 
    'absent' => $attendance_stats['absent_days'],
    'half_day' => 0 // Keep this for compatibility but not used in new logic
];

// Fetch tasks and submissions
$tasks = [];
$task_stats = ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'overdue' => 0];

try {
    $task_stmt = $conn->prepare("
        SELECT t.*, ts.submission_description, ts.status as submission_status, ts.feedback, ts.submitted_at
        FROM tasks t
        LEFT JOIN task_submissions ts ON t.task_id = ts.task_id
        WHERE t.student_id = ?
        ORDER BY t.created_at DESC
    ");
    $task_stmt->bind_param("i", $user_id);
    $task_stmt->execute();
    $task_result = $task_stmt->get_result();
    
    while ($row = $task_result->fetch_assoc()) {
        $tasks[] = $row;
        
        $status = strtolower($row['status']);
        if (isset($task_stats[$status])) {
            $task_stats[$status]++;
        }
    }
    $task_stmt->close();
} catch (Exception $e) {
    echo "Error fetching tasks: " . $e->getMessage();
}

// Fetch evaluations
$evaluations = [];
try {
    $eval_stmt = $conn->prepare("
        SELECT e.*, cs.full_name as supervisor_name
        FROM student_evaluations e
        LEFT JOIN company_supervisors cs ON e.supervisor_id = cs.supervisor_id
        WHERE e.student_id = ?
        ORDER BY e.created_at DESC
    ");
    $eval_stmt->bind_param("i", $user_id);
    $eval_stmt->execute();
    $eval_result = $eval_stmt->get_result();
    
    while ($row = $eval_result->fetch_assoc()) {
        $evaluations[] = $row;
    }
    $eval_stmt->close();
} catch (Exception $e) {
    echo "Error fetching evaluations: " . $e->getMessage();
}

// Fetch self-assessments
$self_assessments = [];
try {
    $self_stmt = $conn->prepare("
        SELECT * FROM student_self_assessments 
        WHERE student_id = ?
        ORDER BY created_at DESC
    ");
    $self_stmt->bind_param("i", $user_id);
    $self_stmt->execute();
    $self_result = $self_stmt->get_result();
    
    while ($row = $self_result->fetch_assoc()) {
        $self_assessments[] = $row;
    }
    $self_stmt->close();
} catch (Exception $e) {
    echo "Error fetching self-assessments: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - Student Report</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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

        .progress-fill {
            transition: width 2s ease-in-out;
        }

        .rating-stars {
            display: flex;
            gap: 2px;
        }

        .star {
            color: #ffc107;
            font-size: 1rem;
        }

        .star.empty {
            color: #e5e7eb;
        }
/* Print-Optimized CSS - Replace the existing print styles in your <style> section */

@media print {
    /* Hide non-essential elements */
    .sidebar,
    .print-btn,
    .export-btn,
    .report-actions,
    .dropdown-content,
    .top-bar,
    .header,
    #mobileMenuBtn,
    #profileBtn,
    #profileDropdown,
    .notification-badge,
    button[onclick*="print"],
    .bg-gradient-to-r.from-bulsu-maroon {
        display: none !important;
    }

    /* Reset body and main layout */
    body {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
        color: #000 !important;
        background: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .main-content,
    .lg\:ml-64 {
        margin-left: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    /* Page settings */
    @page {
        margin: 0.4in 0.5in;
        size: letter;
    }

    /* Compact header */
    .bg-white.shadow-sm.border-b.border-gray-200 {
        padding: 8px 0 !important;
        margin-bottom: 12px !important;
        border-bottom: 1px solid #ddd !important;
    }

    .bg-white.shadow-sm.border-b.border-gray-200 h1 {
        font-size: 16px !important;
        font-weight: bold !important;
        margin: 0 !important;
    }

    .bg-white.shadow-sm.border-b.border-gray-200 p {
        font-size: 9px !important;
        margin: 2px 0 0 0 !important;
        color: #666 !important;
    }

    /* Compact containers */
    .p-4,
    .p-6,
    .sm\:p-6,
    .lg\:p-8 {
        padding: 8px !important;
    }

    .mb-6,
    .mb-8,
    .sm\:mb-8 {
        margin-bottom: 8px !important;
    }

    .mt-6 {
        margin-top: 6px !important;
    }

    /* Card styling */
    .bg-white.rounded-lg.shadow-sm.border {
        border: 1px solid #ddd !important;
        border-radius: 3px !important;
        box-shadow: none !important;
        margin-bottom: 8px !important;
        page-break-inside: avoid;
        background: white !important;
    }

    /* Section headers */
    .border-b.border-gray-200 {
        padding: 6px 8px !important;
        border-bottom: 1px solid #ddd !important;
        background: #f8f9fa !important;
    }

    .border-b.border-gray-200 h3 {
        font-size: 13px !important;
        font-weight: 600 !important;
        margin: 0 !important;
        color: #000 !important;
    }

    .w-10.h-10.bg-gradient-to-r {
        width: 16px !important;
        height: 16px !important;
        font-size: 10px !important;
        margin-right: 6px !important;
        background: #666 !important;
        color: white !important;
    }

    /* Grid layouts - make more compact */
    .grid.grid-cols-1.md\:grid-cols-2 {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
    }

    .grid.grid-cols-2.lg\:grid-cols-4 {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 6px !important;
    }

    .grid.grid-cols-1.lg\:grid-cols-2 {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
    }

    /* Compact stat boxes */
    .bg-gray-50.p-4.rounded-lg,
    .bg-gray-50.p-6.rounded-lg {
        padding: 6px !important;
        background: #f8f9fa !important;
        border: 1px solid #e9ecef !important;
        border-radius: 2px !important;
        text-align: center !important;
    }

    .text-2xl.font-bold,
    .text-3xl.font-bold,
    .sm\:text-3xl.font-bold {
        font-size: 16px !important;
        font-weight: 600 !important;
        margin: 0 0 2px 0 !important;
    }

    .text-sm.text-gray-600 {
        font-size: 9px !important;
        color: #666 !important;
        margin: 0 !important;
    }

    /* Progress bars */
    .w-full.bg-gray-200.rounded-full {
        height: 8px !important;
        background: #e9ecef !important;
        border-radius: 4px !important;
        margin: 4px 0 !important;
    }

    .bg-gradient-to-r.from-blue-500.to-purple-600 {
        background: #666 !important;
        height: 8px !important;
        border-radius: 4px !important;
    }

    /* Tables */
    .overflow-x-auto {
        overflow: visible !important;
    }

    table {
        width: 100% !important;
        font-size: 9px !important;
        border-collapse: collapse !important;
        margin: 6px 0 !important;
    }

    th {
        padding: 4px !important;
        background: #f8f9fa !important;
        font-weight: 600 !important;
        font-size: 8px !important;
        text-transform: uppercase !important;
        border: 1px solid #dee2e6 !important;
    }

    td {
        padding: 3px 4px !important;
        border: 1px solid #dee2e6 !important;
        font-size: 8px !important;
        vertical-align: top !important;
    }

    /* Status badges */
    .inline-flex.items-center.px-2\.5.py-0\.5.rounded-full {
        padding: 1px 4px !important;
        font-size: 7px !important;
        font-weight: 500 !important;
        border-radius: 8px !important;
        background: #e9ecef !important;
        color: #495057 !important;
    }

    /* Alert boxes */
    .bg-green-50.border.border-green-200,
    .bg-yellow-50.border.border-yellow-200,
    .bg-blue-50.border.border-blue-200 {
        padding: 6px !important;
        margin: 4px 0 !important;
        border: 1px solid #ddd !important;
        border-radius: 2px !important;
        background: #f8f9fa !important;
        page-break-inside: avoid;
    }

    .bg-green-50 h5,
    .bg-yellow-50 h5,
    .bg-blue-50 h5 {
        font-size: 10px !important;
        font-weight: 600 !important;
        margin: 0 0 2px 0 !important;
    }

    .bg-green-50 p,
    .bg-yellow-50 p,
    .bg-blue-50 p {
        font-size: 9px !important;
        margin: 0 !important;
    }

    /* Charts - hide or minimize */
    canvas {
        display: none !important;
    }

    /* Evaluation sections - more compact */
    .border.border-gray-200.rounded-lg.p-4,
    .border.border-gray-200.rounded-lg.p-6 {
        padding: 6px !important;
        margin: 4px 0 !important;
        border: 1px solid #ddd !important;
        border-radius: 2px !important;
        page-break-inside: avoid;
    }

    .grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3 {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 4px !important;
    }

    /* Rating stars */
    .rating-stars {
        font-size: 8px !important;
    }

    .star {
        font-size: 8px !important;
    }

    /* Summary sections */
    .bg-gradient-to-br.from-blue-50,
    .bg-gradient-to-br.from-green-50 {
        background: #f8f9fa !important;
        border: 1px solid #e9ecef !important;
        padding: 6px !important;
    }

    .bg-gradient-to-br h4 {
        font-size: 11px !important;
        font-weight: 600 !important;
        margin: 0 0 4px 0 !important;
    }

    /* Footer */
    .bg-gray-50.border.border-gray-200.rounded-lg {
        background: #f8f9fa !important;
        border: 1px solid #ddd !important;
        padding: 8px !important;
        text-align: center !important;
        page-break-inside: avoid;
    }

    .bg-gray-50 p {
        font-size: 9px !important;
        margin: 2px 0 !important;
    }

    /* Force page breaks at logical points */
    .bg-white.rounded-lg.shadow-sm.border.border-gray-200 {
        page-break-inside: avoid;
    }

    /* Specific sections that should break */
    .bg-white.rounded-lg.shadow-sm.border.border-gray-200:nth-of-type(3) {
        page-break-before: page;
    }

    /* Hide action buttons */
    .flex.flex-col.sm\:flex-row.gap-3,
    .flex.flex-wrap.justify-center.gap-4 {
        display: none !important;
    }

    /* Compact spacing for lists */
    ul {
        margin: 4px 0 !important;
        padding-left: 12px !important;
    }

    li {
        margin: 2px 0 !important;
        font-size: 9px !important;
        line-height: 1.2 !important;
    }

    /* Make sure text is readable */
    * {
        color: #000 !important;
    }

    .text-gray-500,
    .text-gray-600,
    .text-gray-700 {
        color: #666 !important;
    }
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
            <a href="studentReport.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-book mr-3 text-bulsu-gold"></i>
                Report
            </a>
            <a href="studentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
                Evaluation
            </a>
            <a href="studentSelf-Assessment.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                <i class="fas fa-star mr-3"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Report</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Comprehensive overview of your OJT progress and performance</p>
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
            <!-- Report Header -->
    <div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white p-6 rounded-lg shadow-sm mb-6 sm:mb-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold mb-2 flex items-center">
                            <i class="fas fa-file-alt mr-3"></i>
                            OJT Progress Report
                        </h1>
                        <p class="text-blue-100">Generated on <?php echo date('F j, Y g:i A'); ?></p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-3 mt-4 sm:mt-0">
                        <button onclick="window.print()" class="flex items-center justify-center px-4 py-2 bg-white bg-opacity-20 hover:bg-opacity-30 rounded-md transition-colors">
                            <i class="fas fa-print mr-2"></i>
                            <span class="hidden sm:inline">Print Report</span>
                            <span class="sm:hidden">Print</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Student Information Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-user"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Student Information</h3>
                    </div>
                </div>
                
                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Full Name:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($full_name); ?></span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Student ID:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($student_id); ?></span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Course:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($program); ?></span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Year & Section:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($year_level . ' - ' . $section); ?></span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Department:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($department); ?></span>
                        </div>
                        <div class="flex justify-between py-3 border-b border-gray-200">
                            <span class="font-medium text-gray-600">Email:</span>
                            <span class="text-gray-900"><?php echo htmlspecialchars($email); ?></span>
                        </div>
                    </div>

                    <?php if ($is_deployed): ?>
                    <div class="mt-6">
                        <h4 class="font-medium text-gray-900 mb-4">OJT Deployment Details</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">OJT Company:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($is_deployed['company_name']); ?></span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Position:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($is_deployed['position'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Work Schedule:</span>
                                <span class="text-gray-900">
                                    <?php echo date('g:i A', strtotime($is_deployed['work_schedule_start'])); ?> - 
                                    <?php echo date('g:i A', strtotime($is_deployed['work_schedule_end'])); ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Work Days:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($is_deployed['work_days']); ?></span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">OJT Period:</span>
                                <span class="text-gray-900"><?php echo date('M j, Y', strtotime($is_deployed['start_date'])) . ' - ' . date('M j, Y', strtotime($is_deployed['end_date'])); ?></span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Required Hours:</span>
                                <span class="text-gray-900"><?php echo $is_deployed['required_hours']; ?> hours</span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Completed Hours:</span>
                                <span class="text-gray-900"><?php echo number_format($is_deployed['completed_hours'], 1); ?> hours</span>
                            </div>
                            <div class="flex justify-between py-3 border-b border-gray-200">
                                <span class="font-medium text-gray-600">Status:</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $is_deployed['is_ojt_completed'] ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                    <?php echo $is_deployed['is_ojt_completed'] ? 'Completed' : 'Active'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($is_deployed['is_ojt_completed']): ?>
                        <div class="mt-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                            <div class="flex">
                                <i class="fas fa-trophy text-green-600 mr-3 mt-1"></i>
                                <div>
                                    <h5 class="font-medium text-green-800">OJT Completed!</h5>
                                    <p class="text-sm text-green-700 mt-1">
                                        <?php if ($is_deployed['is_hours_completed']): ?>
                                            Completed by reaching required hours (<?php echo number_format($is_deployed['completed_hours'], 1); ?>/<?php echo $is_deployed['required_hours']; ?> hours)
                                        <?php else: ?>
                                            Completed at end of internship period
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="flex">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mr-3 mt-1"></i>
                            <div>
                                <h3 class="font-medium text-yellow-800">No OJT Deployment</h3>
                                <p class="text-sm text-yellow-700 mt-1">No active OJT deployment found. Please contact your academic adviser for deployment assignment.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Overall Progress Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Overall Progress Summary</h3>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="bg-gray-50 p-4 sm:p-6 rounded-lg mb-6">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="font-medium text-gray-900">OJT Hours Completion</h4>
                            <span class="text-2xl font-bold text-blue-600"><?php echo $progress_percentage; ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3 mb-2">
                            <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-3 rounded-full progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                        </div>
                        <p class="text-sm text-gray-600">
                            <?php echo $total_hours; ?> of <?php echo $required_hours; ?> required hours completed
                        </p>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-blue-500">
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo number_format($total_hours, 1); ?></div>
                            <div class="text-sm text-gray-600">Total Hours</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-green-500">
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $attendance_stats['total_work_days']; ?></div>
                            <div class="text-sm text-gray-600">Total Work Days</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-purple-500">
                            <div class="text-2xl sm:text-3xl font-bold text-purple-600 mb-1"><?php echo $task_stats['completed']; ?></div>
                            <div class="text-sm text-gray-600">Tasks Completed</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-indigo-500">
                            <div class="text-2xl sm:text-3xl font-bold text-indigo-600 mb-1"><?php echo count($evaluations); ?></div>
                            <div class="text-sm text-gray-600">Evaluations Received</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Record Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Attendance Record</h3>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6">
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-green-500">
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $attendance_stats['present_days']; ?></div>
                            <div class="text-sm text-gray-600">Present</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-yellow-500">
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $attendance_stats['late_days']; ?></div>
                            <div class="text-sm text-gray-600">Late</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-red-500">
                            <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $attendance_stats['absent_days']; ?></div>
                            <div class="text-sm text-gray-600">Absent</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-blue-500">
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo round($attendance_stats['attendance_rate'], 1); ?>%</div>
                            <div class="text-sm text-gray-600">Attendance Rate</div>
                        </div>
                    </div>

                    <?php if (!empty($attendance_records)): ?>
                    <div class="mb-6">
                        <canvas id="attendanceChart" class="w-full" style="height: 300px;"></canvas>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time In</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time Out</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Hours</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach (array_slice($attendance_records, 0, 10) as $record): ?>
                                <tr class="hover:bg-gray-50 <?php 
                                    if (isset($record['is_absent']) && $record['is_absent']) {
                                        echo 'bg-red-50';
                                    } elseif ($record['is_late']) {
                                        echo 'bg-yellow-50';
                                    }
                                ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('l', strtotime($record['date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '--'; ?>
                                        <?php if (isset($record['is_late']) && $record['is_late'] && $record['minutes_late'] > 0): ?>
                                            <span class="text-yellow-600 text-xs ml-1">(<?php echo $record['minutes_late']; ?>min late)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '--'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $record['total_hours'] ? number_format($record['total_hours'], 1) . 'h' : '--'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        if (isset($record['is_absent']) && $record['is_absent']) {
                                            $statusClass = 'bg-red-100 text-red-800';
                                            $statusText = 'Absent';
                                        } elseif ($record['is_late']) {
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                            $statusText = 'Late';
                                        } elseif ($record['time_in'] && $record['time_out']) {
                                            $statusClass = 'bg-green-100 text-green-800';
                                            $statusText = 'Present';
                                        } elseif ($record['time_in']) {
                                            $statusClass = 'bg-blue-100 text-blue-800';
                                            $statusText = 'In Progress';
                                        } else {
                                            $statusClass = 'bg-gray-100 text-gray-800';
                                            $statusText = 'No Record';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if (isset($record['attendance_method']) && $record['attendance_method'] === 'facial'): ?>
                                            <span class="text-blue-600"><i class="fas fa-user-check mr-1"></i>Facial</span>
                                        <?php else: ?>
                                            <span class="text-gray-500"><i class="fas fa-edit mr-1"></i>Manual</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($attendance_records) > 15): ?>
                    <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mr-3 mt-1"></i>
                            <p class="text-blue-700">Showing latest 15 attendance records. Total records: <?php echo count($attendance_records); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-calendar-times text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Attendance Records</h3>
                        <p class="text-gray-600">No attendance data available yet. Start logging your attendance to see records here.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Task Management Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Task Management Summary</h3>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6">
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-green-500">
                            <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $task_stats['completed']; ?></div>
                            <div class="text-sm text-gray-600">Completed</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-blue-500">
                            <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $task_stats['in_progress']; ?></div>
                            <div class="text-sm text-gray-600">In Progress</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-yellow-500">
                            <div class="text-2xl sm:text-3xl font-bold text-yellow-600 mb-1"><?php echo $task_stats['pending']; ?></div>
                            <div class="text-sm text-gray-600">Pending</div>
                        </div>
                        <div class="bg-gray-50 p-4 sm:p-6 rounded-lg text-center border-l-4 border-l-red-500">
                            <div class="text-2xl sm:text-3xl font-bold text-red-600 mb-1"><?php echo $task_stats['overdue']; ?></div>
                            <div class="text-sm text-gray-600">Overdue</div>
                        </div>
                    </div>

                    <?php if (!empty($tasks)): ?>
                    <div class="mb-6">
                        <canvas id="taskChart" class="w-full" style="height: 300px;"></canvas>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submission</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach (array_slice($tasks, 0, 7) as $task): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($task['task_title']); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars(substr($task['task_description'], 0, 100)) . (strlen($task['task_description']) > 100 ? '...' : ''); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status = str_replace(' ', '_', strtolower($task['status']));
                                        $statusClasses = [
                                            'completed' => 'bg-green-100 text-green-800',
                                            'in_progress' => 'bg-blue-100 text-blue-800',
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'overdue' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($task['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($task['submission_status']): ?>
                                            <?php
                                            $subStatus = strtolower($task['submission_status']);
                                            $subStatusClasses = [
                                                'approved' => 'bg-green-100 text-green-800',
                                                'pending' => 'bg-yellow-100 text-yellow-800',
                                                'rejected' => 'bg-red-100 text-red-800'
                                            ];
                                            $subStatusClass = $subStatusClasses[$subStatus] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $subStatusClass; ?>">
                                                <?php echo htmlspecialchars($task['submission_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                Not Submitted
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($tasks) > 10): ?>
                    <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mr-3 mt-1"></i>
                            <p class="text-blue-700">Showing latest 10 tasks. Total tasks: <?php echo count($tasks); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-clipboard-list text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Tasks Assigned</h3>
                        <p class="text-gray-600">No tasks have been assigned to you yet. Tasks will appear here when your supervisor assigns them.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Evaluations Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
    <div class="p-4 sm:p-6 border-b border-gray-200">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                <i class="fas fa-star"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900">Performance Evaluations</h3>
        </div>
    </div>

    <div class="p-4 sm:p-6">
        <?php if (!empty($evaluations)): ?>
        <div class="space-y-6">
            <?php foreach ($evaluations as $evaluation): ?>
            <div class="border border-gray-200 rounded-lg p-4 sm:p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                    <div>
                        <h4 class="font-medium text-gray-900">Evaluation by: <?php echo htmlspecialchars($evaluation['evaluator_name'] ?? 'N/A'); ?></h4>
                        <p class="text-sm text-gray-500">Date: <?php echo date('M j, Y', strtotime($evaluation['created_at'])); ?></p>
                        <p class="text-sm text-gray-500">Position: <?php echo htmlspecialchars($evaluation['evaluator_position'] ?? 'N/A'); ?></p>
                    </div>
                    
                    </div>
                </div>

                <!-- Evaluation Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="font-medium text-gray-900 mb-2">Training Period</div>
                        <p class="text-gray-600">
                            <?php echo date('M j, Y', strtotime($evaluation['training_period_start'])); ?> - 
                            <?php echo date('M j, Y', strtotime($evaluation['training_period_end'])); ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="font-medium text-gray-900 mb-2">Total Hours Rendered</div>
                        <p class="text-gray-600"><?php echo $evaluation['total_hours_rendered']; ?> hours</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="font-medium text-gray-900 mb-2">Cooperating Agency</div>
                        <p class="text-gray-600"><?php echo htmlspecialchars($evaluation['cooperating_agency']); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="font-medium text-gray-900 mb-2">Equivalent Rating</div>
                        <p class="text-gray-600">
                            <?php echo $evaluation['equivalent_rating']; ?> 
                            (<?php echo htmlspecialchars($evaluation['verbal_interpretation']); ?>)
                        </p>
                    </div>
                </div>

                <!-- Evaluation Categories Summary -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                    <?php
                    // Calculate category scores
                    $categories = [
                        'Teamwork' => ['teamwork_1', 'teamwork_2', 'teamwork_3', 'teamwork_4', 'teamwork_5'],
                        'Communication' => ['communication_6', 'communication_7', 'communication_8', 'communication_9'],
                        'Attendance' => ['attendance_10', 'attendance_11', 'attendance_12'],
                        'Productivity' => ['productivity_13', 'productivity_14', 'productivity_15', 'productivity_16', 'productivity_17'],
                        'Initiative' => ['initiative_18', 'initiative_19', 'initiative_20', 'initiative_21', 'initiative_22', 'initiative_23'],
                        'Judgement' => ['judgement_24', 'judgement_25', 'judgement_26'],
                        'Dependability' => ['dependability_27', 'dependability_28', 'dependability_29', 'dependability_30', 'dependability_31'],
                        'Attitude' => ['attitude_32', 'attitude_33', 'attitude_34', 'attitude_35', 'attitude_36'],
                        'Professionalism' => ['professionalism_37', 'professionalism_38', 'professionalism_39', 'professionalism_40']
                    ];

                    foreach ($categories as $category => $fields):
                        $category_score = 0;
                        $category_max = count($fields);
                        
                        foreach ($fields as $field) {
                            $category_score += $evaluation[$field] ?? 0;
                        }
                        
                        $category_rating = $category_max > 0 ? round(($category_score / $category_max) * 5, 1) : 0;
                    ?>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="font-medium text-gray-900 mb-2"><?php echo $category; ?></div>
                        <div class="rating-stars">
                            <?php
                            $rating = floor($category_rating);
                            for ($i = 1; $i <= 5; $i++) {
                                if ($i <= $rating) {
                                    echo '<i class="fas fa-star star"></i>';
                                } else {
                                    echo '<i class="far fa-star star empty"></i>';
                                }
                            }
                            ?>
                            <span class="ml-2 text-gray-600">(<?php echo $category_rating; ?>/5)</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Comments and Suggestions -->
                <?php if (!empty($evaluation['remarks_comments_suggestions'])): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex">
                        <i class="fas fa-comment text-blue-600 mr-3 mt-1"></i>
                        <div>
                            <h5 class="font-medium text-blue-800 mb-2">Remarks, Comments & Suggestions</h5>
                            <p class="text-blue-700"><?php echo htmlspecialchars($evaluation['remarks_comments_suggestions']); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-8">
            <i class="fas fa-star-half-alt text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Evaluations Yet</h3>
            <p class="text-gray-600">Performance evaluations from your supervisor will appear here once they are completed.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

            <!-- Self-Assessments Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Self-Assessments</h3>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <?php if (!empty($self_assessments)): ?>
                    <div class="space-y-6">
                        <?php foreach ($self_assessments as $assessment): ?>
                        <?php
                        // Calculate overall rating from self-assessment fields
                        $self_rating_fields = [
                            'academic_performance', 'workplace_satisfaction', 'learning_progress', 
                            'time_management', 'skill_development', 'confidence_level'
                        ];
                        
                        $total_ratings = 0;
                        $rating_count = 0;
                        
                        foreach ($self_rating_fields as $field) {
                            if (isset($assessment[$field]) && $assessment[$field] > 0) {
                                $total_ratings += $assessment[$field];
                                $rating_count++;
                            }
                        }
                        
                        $calculated_overall = $rating_count > 0 ? round($total_ratings / $rating_count, 1) : 0;
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4 sm:p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                                <div>
                                    <h4 class="font-medium text-gray-900">Self-Assessment</h4>
                                    <p class="text-sm text-gray-500">Submitted: <?php echo date('M j, Y', strtotime($assessment['created_at'])); ?></p>
                                </div>
                                <div class="text-2xl font-bold text-blue-600 mt-2 sm:mt-0">
                                    Overall: <?php echo $calculated_overall; ?>/5
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                                <?php
                                $self_criteria = [
                                    'academic_performance' => 'Academic Performance',
                                    'workplace_satisfaction' => 'Workplace Satisfaction',
                                    'learning_progress' => 'Learning Progress',
                                    'time_management' => 'Time Management',
                                    'skill_development' => 'Skill Development',
                                    'confidence_level' => 'Confidence Level'
                                ];

                                foreach ($self_criteria as $field => $label):
                                    if (isset($assessment[$field]) && $assessment[$field] > 0):
                                ?>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="font-medium text-gray-900 mb-2"><?php echo $label; ?></div>
                                    <div class="rating-stars">
                                        <?php
                                        $rating = intval($assessment[$field]);
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $rating) {
                                                echo '<i class="fas fa-star star"></i>';
                                            } else {
                                                echo '<i class="far fa-star star empty"></i>';
                                            }
                                        }
                                        ?>
                                        <span class="ml-2 text-gray-600">(<?php echo $rating; ?>/5)</span>
                                    </div>
                                </div>
                                <?php
                                    endif;
                                endforeach;
                                ?>

                                <!-- Additional assessment fields -->
                                <?php if (isset($assessment['stress_level']) && $assessment['stress_level'] > 0): ?>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="font-medium text-gray-900 mb-2">Stress Level</div>
                                    <div>
                                        <?php
                                        $stress = intval($assessment['stress_level']);
                                        $stress_labels = [1 => 'Very Low', 2 => 'Low', 3 => 'Moderate', 4 => 'High', 5 => 'Very High'];
                                        $stress_colors = [1 => 'bg-green-100 text-green-800', 2 => 'bg-blue-100 text-blue-800', 3 => 'bg-yellow-100 text-yellow-800', 4 => 'bg-orange-100 text-orange-800', 5 => 'bg-red-100 text-red-800'];
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $stress_colors[$stress]; ?>">
                                            <?php echo $stress_labels[$stress]; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($assessment['challenges_faced'])): ?>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-3">
                                <div class="flex">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 mr-3 mt-1"></i>
                                    <div>
                                        <h5 class="font-medium text-yellow-800 mb-2">Challenges Faced</h5>
                                        <p class="text-yellow-700"><?php echo htmlspecialchars($assessment['challenges_faced']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($assessment['support_needed'])): ?>
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-3">
                                <div class="flex">
                                    <i class="fas fa-hands-helping text-blue-600 mr-3 mt-1"></i>
                                    <div>
                                        <h5 class="font-medium text-blue-800 mb-2">Support Needed</h5>
                                        <p class="text-blue-700"><?php echo htmlspecialchars($assessment['support_needed']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($assessment['overall_reflection'])): ?>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <div class="flex">
                                    <i class="fas fa-lightbulb text-gray-600 mr-3 mt-1"></i>
                                    <div>
                                        <h5 class="font-medium text-gray-800 mb-2">Overall Reflection</h5>
                                        <p class="text-gray-700"><?php echo htmlspecialchars($assessment['overall_reflection']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-clipboard-user text-gray-400 text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Self-Assessments Yet</h3>
                        <p class="text-gray-600">Self-assessments help track your personal growth and reflection. Complete self-assessments regularly to monitor your progress.</p>
                        <a href="studentSelf-Assessment.php" class="inline-flex items-center px-4 py-2 mt-4 bg-blue-600 hover:bg-blue-700 text-white rounded-md transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Complete Self-Assessment
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Report Summary Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white mr-3">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900">Report Summary</h3>
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Progress Overview -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-100 p-6 rounded-lg">
                            <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-chart-line mr-2 text-blue-600"></i>
                                Progress Overview
                            </h4>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">OJT Completion:</span>
                                    <span class="font-semibold text-blue-600"><?php echo $progress_percentage; ?>%</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Total Hours Logged:</span>
                                    <span class="font-semibold text-blue-600"><?php echo number_format($total_hours, 1); ?> hrs</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Days Attended:</span>
                                    <span class="font-semibold text-blue-600"><?php echo $attendance_stats['present_days']; ?> days</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Tasks Completed:</span>
                                    <span class="font-semibold text-blue-600"><?php echo $task_stats['completed']; ?> tasks</span>
                                </div>
                            </div>
                        </div>

                        <!-- Performance Insights -->
                        <div class="bg-gradient-to-br from-green-50 to-emerald-100 p-6 rounded-lg">
                            <h4 class="font-semibold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-trophy mr-2 text-green-600"></i>
                                Performance Insights
                            </h4>
                            <div class="space-y-3">
                                <?php 
                                $attendance_rate = round($attendance_stats['attendance_rate'], 1);
                                $task_completion_rate = count($tasks) > 0 ? 
                                    round(($task_stats['completed'] / count($tasks)) * 100, 1) : 0;
                                ?>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Attendance Rate:</span>
                                    <span class="font-semibold text-green-600"><?php echo $attendance_rate; ?>%</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Task Completion:</span>
                                    <span class="font-semibold text-green-600"><?php echo $task_completion_rate; ?>%</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Evaluations Received:</span>
                                    <span class="font-semibold text-green-600"><?php echo count($evaluations); ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-700">Self-Assessments:</span>
                                    <span class="font-semibold text-green-600"><?php echo count($self_assessments); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Recommendations -->
                    <div class="mt-6 p-6 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <h4 class="font-semibold text-yellow-800 mb-3 flex items-center">
                            <i class="fas fa-lightbulb mr-2"></i>
                            Key Recommendations
                        </h4>
                        <ul class="space-y-2 text-yellow-700">
                            <?php if ($progress_percentage < 50): ?>
                            <li class="flex items-start">
                                <i class="fas fa-arrow-right mr-2 mt-1 text-xs"></i>
                                Focus on consistent attendance to accumulate more OJT hours and reach the required completion percentage.
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($task_stats['pending'] > 0 || $task_stats['overdue'] > 0): ?>
                            <li class="flex items-start">
                                <i class="fas fa-arrow-right mr-2 mt-1 text-xs"></i>
                                Complete pending and overdue tasks to improve your task completion rate and performance evaluation.
                            </li>
                            <?php endif; ?>
                            
                            <?php if (count($self_assessments) < 2): ?>
                            <li class="flex items-start">
                                <i class="fas fa-arrow-right mr-2 mt-1 text-xs"></i>
                                Regular self-assessment submissions help track your personal growth and identify areas for improvement.
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($attendance_stats['late_days'] > ($attendance_stats['present_days'] * 0.2)): ?>
                            <li class="flex items-start">
                                <i class="fas fa-arrow-right mr-2 mt-1 text-xs"></i>
                                Improve punctuality to enhance your professional image and evaluation scores.
                            </li>
                            <?php endif; ?>
                            
                            <li class="flex items-start">
                                <i class="fas fa-arrow-right mr-2 mt-1 text-xs"></i>
                                Continue maintaining good communication with your supervisor and seek feedback regularly.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Report Footer -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center">
                <p class="text-gray-600 mb-4">This report was generated automatically based on your OJT activities and performance data.</p>
                <p class="text-sm text-gray-500">
                    Report Generated: <?php echo date('F j, Y g:i A'); ?> | 
                    Academic Year: <?php echo date('Y') . '-' . (date('Y') + 1); ?> |
                    OnTheJob Tracker System
                </p>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Mobile sidebar functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        function toggleSidebar() {
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
        }

        mobileMenuBtn.addEventListener('click', toggleSidebar);
        closeSidebar.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);

        // Profile dropdown functionality
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', function() {
            profileDropdown.classList.add('hidden');
        });

        // Attendance Chart
        <?php if (!empty($attendance_records)): ?>
        const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
        const attendanceChart = new Chart(attendanceCtx, {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Late', 'Absent'],
                datasets: [{
                    data: [
                        <?php echo $attendance_stats['present_days']; ?>,
                        <?php echo $attendance_stats['late_days']; ?>,
                        <?php echo $attendance_stats['absent_days']; ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#f59e0b',
                        '#ef4444'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Task Chart
        <?php if (!empty($tasks)): ?>
        const taskCtx = document.getElementById('taskChart').getContext('2d');
        const taskChart = new Chart(taskCtx, {
            type: 'bar',
            data: {
                labels: ['Completed', 'In Progress', 'Pending', 'Overdue'],
                datasets: [{
                    label: 'Number of Tasks',
                    data: [
                        <?php echo $task_stats['completed']; ?>,
                        <?php echo $task_stats['in_progress']; ?>,
                        <?php echo $task_stats['pending']; ?>,
                        <?php echo $task_stats['overdue']; ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#3b82f6',
                        '#f59e0b',
                        '#ef4444'
                    ],
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' tasks';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Print optimization
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('printing');
        });

        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing');
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>