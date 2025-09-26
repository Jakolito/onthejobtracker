<?php
include('connect.php');
session_start();

// Prevent caching - add these headers at the top
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

// Handle report generation
if (isset($_POST['generate_report'])) {
    $report_type = $_POST['report_type'];
    $date_from = $_POST['date_from'];
    $date_to = $_POST['date_to'];
    $student_filter = $_POST['student_filter'] ?? '';
    
    // Generate report based on type
    $report_data = generateReport($conn, $report_type, $date_from, $date_to, $student_filter);
}

function generateReport($conn, $type, $date_from, $date_to, $student_filter) {
    $data = array();
    
    switch($type) {
        case 'student_information':
            $data = generateStudentInformationReport($conn, $date_from, $date_to, $student_filter);
            break;
        case 'student_deployment':
            $data = generateStudentDeploymentReport($conn, $date_from, $date_to, $student_filter);
            break;
        case 'student_attendance':
            $data = generateStudentAttendanceReport($conn, $date_from, $date_to, $student_filter);
            break;
        case 'student_complete_ojt':
            $data = generateStudentCompleteOJTReport($conn, $date_from, $date_to, $student_filter);
            break;
    }
    
    return $data;
}

function generateStudentInformationReport($conn, $date_from, $date_to, $student_filter) {
    $where_clause = "WHERE s.created_at BETWEEN '$date_from' AND '$date_to'";
    if (!empty($student_filter)) {
        $where_clause .= " AND s.id = '$student_filter'";
    }
    
    $query = "SELECT 
        s.id,
        s.student_id,
        CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) as full_name,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.gender,
        s.date_of_birth,
        s.contact_number,
        s.email,
        s.address,
        s.year_level,
        s.department,
        s.program,
        s.section,
        s.status,
        s.verified,
        s.created_at,
        s.last_login,
        CASE 
            WHEN s.verified = 1 THEN 'Verified'
            WHEN s.verified = 0 THEN 'Not Verified'
            ELSE 'Unknown Status'
        END as verification_status
    FROM students s
    $where_clause
    ORDER BY s.created_at DESC, s.last_name ASC";
    
    $result = mysqli_query($conn, $query);
    $students = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        $row['age'] = calculateAge($row['date_of_birth']);
        $row['ai_insights'] = generateStudentInfoInsights($row);
        $students[] = $row;
    }
    
    return array('students' => $students, 'type' => 'student_information');
}


function generateStudentDeploymentReport($conn, $date_from, $date_to, $student_filter) {
    $where_clause = "WHERE sd.created_at BETWEEN '$date_from' AND '$date_to'";
    if (!empty($student_filter)) {
        $where_clause .= " AND s.id = '$student_filter'";
    }
    
    $query = "SELECT 
        s.student_id,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.program,
        s.section,
        sd.deployment_id,
        sd.company_name,
        sd.company_address,
        sd.company_contact,
        sd.supervisor_name,
        sd.supervisor_position,
        sd.supervisor_email,
        sd.supervisor_phone,
        sd.position,
        sd.start_date,
        sd.end_date,
        sd.required_hours,
        sd.completed_hours,
        sd.work_days,
        sd.status as deployment_status,
        sd.ojt_status,
        sd.created_at as deployment_date,
        DATEDIFF(sd.end_date, sd.start_date) as duration_days,
        ROUND((sd.completed_hours / sd.required_hours) * 100, 2) as completion_percentage
    FROM students s
    LEFT JOIN student_deployments sd ON s.id = sd.student_id
    $where_clause AND sd.deployment_id IS NOT NULL
    ORDER BY sd.created_at DESC";
    
    $result = mysqli_query($conn, $query);
    $deployments = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        $row['ai_insights'] = generateDeploymentStatusInsights($row);
        $deployments[] = $row;
    }
    
    return array('deployments' => $deployments, 'type' => 'student_deployment');
}

function generateStudentAttendanceReport($conn, $date_from, $date_to, $student_filter) {
    $where_clause = "WHERE sa.date BETWEEN '$date_from' AND '$date_to'";
    if (!empty($student_filter)) {
        $where_clause .= " AND s.id = '$student_filter'";
    }
    
    $query = "SELECT 
        s.student_id,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.program,
        s.section,
        COUNT(sa.attendance_id) as total_days_recorded,
        COUNT(CASE WHEN sa.status = 'Present' THEN 1 END) as present_days,
        COUNT(CASE WHEN sa.status = 'Absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN sa.status = 'Late' THEN 1 END) as late_days,
        COUNT(CASE WHEN sa.status = 'Excused' THEN 1 END) as excused_days,
        SUM(sa.total_hours) as total_hours_completed,
        AVG(sa.total_hours) as avg_daily_hours,
        ROUND((COUNT(CASE WHEN sa.status = 'Present' THEN 1 END) / COUNT(sa.attendance_id)) * 100, 2) as attendance_rate,
        MIN(sa.date) as first_attendance_date,
        MAX(sa.date) as last_attendance_date
    FROM students s
    LEFT JOIN student_attendance sa ON s.id = sa.student_id
    $where_clause AND sa.attendance_id IS NOT NULL
    GROUP BY s.id
    ORDER BY attendance_rate DESC, total_hours_completed DESC";
    
    $result = mysqli_query($conn, $query);
    $attendance_data = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        $row['ai_insights'] = generateAttendanceAnalysisInsights($row);
        $row['recommendations'] = generateAttendanceRecommendations($row);
        $attendance_data[] = $row;
    }
    
    return array('attendance_data' => $attendance_data, 'type' => 'student_attendance');
}

function generateStudentCompleteOJTReport($conn, $date_from, $date_to, $student_filter) {
    // Changed the WHERE clause to filter by ojt_status instead of status
    $where_clause = "WHERE sd.updated_at BETWEEN '$date_from' AND '$date_to' AND sd.ojt_status = 'Completed'";
    if (!empty($student_filter)) {
        $where_clause .= " AND s.id = '$student_filter'";
    }
    
    $query = "SELECT 
        s.student_id,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.program,
        s.section,
        sd.company_name,
        sd.supervisor_name,
        sd.position,
        sd.start_date,
        sd.end_date,
        sd.required_hours,
        sd.completed_hours,
        DATEDIFF(sd.end_date, sd.start_date) as total_days,
        sd.updated_at as completion_date,
        sd.status as deployment_status,
        sd.ojt_status,
        CASE 
            WHEN sd.completed_hours >= sd.required_hours THEN 'Fully Completed'
            WHEN sd.completed_hours >= (sd.required_hours * 0.8) THEN 'Nearly Completed'
            ELSE 'Incomplete Hours'
        END as completion_status,
        ROUND((sd.completed_hours / sd.required_hours) * 100, 2) as hours_completion_rate,
        -- Get average evaluation score from new schema
        (SELECT AVG(se.equivalent_rating) 
         FROM student_evaluations se 
         WHERE se.student_id = s.id) as avg_evaluation_score,
        -- Get total score average
        (SELECT AVG(se.total_score) 
         FROM student_evaluations se 
         WHERE se.student_id = s.id) as avg_total_score,
        -- Get task completion rate
        (SELECT ROUND((COUNT(CASE WHEN t.status = 'Completed' THEN 1 END) / COUNT(t.task_id)) * 100, 2)
         FROM tasks t 
         WHERE t.student_id = s.id) as task_completion_rate
    FROM students s
    JOIN student_deployments sd ON s.id = sd.student_id
    $where_clause
    ORDER BY sd.updated_at DESC";
    
    $result = mysqli_query($conn, $query);
    $completed_ojt = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        $row['ai_insights'] = generateOJTCompletionInsights($row);
        $row['performance_analysis'] = generatePerformanceAnalysis($row);
        $completed_ojt[] = $row;
    }
    
    return array('completed_ojt' => $completed_ojt, 'type' => 'student_complete_ojt');
}
// Helper Functions
function calculateAge($date_of_birth) {
    $today = new DateTime();
    $birthDate = new DateTime($date_of_birth);
    return $today->diff($birthDate)->y;
}

function generateStudentInfoInsights($data) {
    $insights = array();
    
    if (!$data['verified']) {
        $insights[] = "Account requires verification.";
    }
    
    if ($data['status'] !== 'Active') {
        $insights[] = "Account status is " . $data['status'] . " - may require attention.";
    }
    
    if (empty($data['last_login'])) {
        $insights[] = "Student has never logged into the system.";
    } else {
        $last_login = new DateTime($data['last_login']);
        $now = new DateTime();
        $days_since_login = $now->diff($last_login)->days;
        
        if ($days_since_login > 30) {
            $insights[] = "Student hasn't logged in for over 30 days - may need engagement.";
        }
    }
    
    return empty($insights) ? "Student profile appears complete and active." : implode(" ", $insights);
}
function generateDeploymentStatusInsights($data) {
    $insights = array();
    
    if ($data['deployment_status'] === 'Active') {
        $progress = $data['completion_percentage'];
        if ($progress >= 80) {
            $insights[] = "Excellent progress - near completion at " . $progress . "%.";
        } elseif ($progress >= 50) {
            $insights[] = "Good progress at " . $progress . "% completion.";
        } else {
            $insights[] = "Slow progress at " . $progress . "% - may need monitoring.";
        }
    } elseif ($data['deployment_status'] === 'Completed') {
        $insights[] = "Successfully completed OJT deployment.";
    } elseif ($data['deployment_status'] === 'Terminated') {
        $insights[] = "Deployment was terminated - requires investigation.";
    }
    
    if ($data['duration_days'] > 150) {
        $insights[] = "Extended deployment duration of " . $data['duration_days'] . " days.";
    }
    
    return implode(" ", $insights);
}

function generateAttendanceAnalysisInsights($data) {
    $insights = array();
    
    $rate = $data['attendance_rate'];
    if ($rate >= 95) {
        $insights[] = "Excellent attendance rate of " . $rate . "% - highly reliable student.";
    } elseif ($rate >= 85) {
        $insights[] = "Good attendance rate of " . $rate . "% with minor areas for improvement.";
    } elseif ($rate >= 75) {
        $insights[] = "Fair attendance rate of " . $rate . "% - needs improvement.";
    } else {
        $insights[] = "Poor attendance rate of " . $rate . "% - requires immediate intervention.";
    }
    
    if ($data['late_days'] > ($data['total_days_recorded'] * 0.1)) {
        $insights[] = "High frequency of tardiness (" . $data['late_days'] . " late days) indicates time management issues.";
    }
    
    if ($data['avg_daily_hours'] > 0) {
        if ($data['avg_daily_hours'] >= 8) {
            $insights[] = "Consistently meeting full-time hour requirements.";
        } else {
            $insights[] = "Averaging " . round($data['avg_daily_hours'], 1) . " hours per day - below full-time expectations.";
        }
    }
    
    return implode(" ", $insights);
}

function generateOJTCompletionInsights($data) {
    $insights = array();
    
    if ($data['hours_completion_rate'] >= 100) {
        $insights[] = "Successfully completed all required hours (" . $data['completed_hours'] . "/" . $data['required_hours'] . ").";
    } else {
        $insights[] = "Completed " . $data['hours_completion_rate'] . "% of required hours (" . $data['completed_hours'] . "/" . $data['required_hours'] . ").";
    }
    
    if ($data['avg_evaluation_score']) {
        // Using equivalent_rating (decimal 5,2) - assuming scale is 1.00-5.00
        if ($data['avg_evaluation_score'] >= 4.0) {
            $insights[] = "Excellent performance with " . round($data['avg_evaluation_score'], 2) . "/5.00 average evaluation rating.";
        } elseif ($data['avg_evaluation_score'] >= 3.0) {
            $insights[] = "Good performance with " . round($data['avg_evaluation_score'], 2) . "/5.00 average evaluation rating.";
        } else {
            $insights[] = "Below average performance with " . round($data['avg_evaluation_score'], 2) . "/5.00 evaluation rating.";
        }
    }
    
    if ($data['avg_total_score']) {
        // Assuming total_score is out of 40 (based on your evaluation criteria)
        $percentage = ($data['avg_total_score'] / 40) * 100;
        $insights[] = "Average total evaluation score: " . round($data['avg_total_score'], 1) . "/40 (" . round($percentage, 1) . "%).";
    }
    
    if ($data['task_completion_rate']) {
        if ($data['task_completion_rate'] >= 90) {
            $insights[] = "Excellent task completion rate of " . $data['task_completion_rate'] . "%.";
        } elseif ($data['task_completion_rate'] >= 70) {
            $insights[] = "Good task completion rate of " . $data['task_completion_rate'] . "%.";
        } else {
            $insights[] = "Low task completion rate of " . $data['task_completion_rate'] . "% - needs attention.";
        }
    }
    
    return implode(" ", $insights);
}

function generateAttendanceRecommendations($data) {
    $recommendations = array();
    
    if ($data['attendance_rate'] < 85) {
        $recommendations[] = "Schedule one-on-one counseling to identify attendance barriers";
        $recommendations[] = "Create personalized attendance improvement plan";
        $recommendations[] = "Consider flexible scheduling if circumstances allow";
    }
    
    if ($data['late_days'] > 3) {
        $recommendations[] = "Provide time management workshop";
        $recommendations[] = "Discuss transportation or scheduling challenges";
    }
    
    if ($data['avg_daily_hours'] < 7) {
        $recommendations[] = "Review daily hour requirements with student";
        $recommendations[] = "Ensure proper time tracking procedures";
    }
    
    return $recommendations;
}

function generatePerformanceAnalysis($data) {
    $analysis = array();
    
    // Overall assessment based on equivalent_rating and task completion
    if ($data['avg_evaluation_score'] >= 4.0 && $data['task_completion_rate'] >= 90) {
        $analysis[] = "Outstanding overall performance - excellent candidate for recognition";
    } elseif ($data['avg_evaluation_score'] >= 3.0 && $data['task_completion_rate'] >= 70) {
        $analysis[] = "Solid overall performance - meets program expectations";
    } else {
        $analysis[] = "Performance below expectations - may need additional support in future placements";
    }
    
    // Add analysis based on total score if available
    if ($data['avg_total_score']) {
        $score_percentage = ($data['avg_total_score'] / 40) * 100;
        if ($score_percentage >= 85) {
            $analysis[] = "High evaluation scores indicate strong competency development";
        } elseif ($score_percentage >= 70) {
            $analysis[] = "Satisfactory evaluation scores show adequate skill development";
        } else {
            $analysis[] = "Lower evaluation scores suggest need for additional training support";
        }
    }
    
    return $analysis;
}

 $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

// Get filter options for students
$students_query = "SELECT id, CONCAT(first_name, ' ', last_name) as name, student_id FROM students WHERE verified = 1 ORDER BY first_name";
$students_result = mysqli_query($conn, $students_query);

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
    <title>Generate Reports - OnTheJob Tracker</title>
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
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        .sidebar-overlay {
            transition: opacity 0.3s ease-in-out;
        }
        /* Enhanced Print Styles - Add this to your existing <style> section */

@media print {
    /* Hide non-essential elements */
    .sidebar, .topbar, .report-filters, .export-options, 
    #mobileMenuBtn, #profileBtn, #profileDropdown {
        display: none !important;
    }
    
    /* Reset layout for print */
    .main-content, body {
        margin: 0 !important;
        padding: 10px !important;
        background: white !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
    }
    
    /* Page setup */
    @page {
        size: A4 landscape; /* Changed to landscape for better table display */
        margin: 0.5in;
    }
    
    /* Container adjustments */
    .lg\\:ml-64 {
        margin-left: 0 !important;
    }
    
    /* Header styling for print */
    .bg-white.shadow-sm.border-b {
        box-shadow: none !important;
        border-bottom: 2px solid #000 !important;
        margin-bottom: 15px !important;
        padding-bottom: 10px !important;
    }
    
    /* Report title */
    h1, h2 {
        font-size: 16px !important;
        font-weight: bold !important;
        margin-bottom: 8px !important;
        color: #000 !important;
    }
    
    h3 {
        font-size: 14px !important;
        font-weight: bold !important;
        margin-bottom: 6px !important;
        color: #000 !important;
    }
    
    /* Table styling */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 9px !important;
        margin-bottom: 20px !important;
        page-break-inside: auto !important;
    }
    
    /* Table headers */
    th {
        background-color: #f5f5f5 !important;
        border: 1px solid #000 !important;
        padding: 4px 2px !important;
        font-weight: bold !important;
        font-size: 8px !important;
        text-align: left !important;
        vertical-align: top !important;
        word-wrap: break-word !important;
    }
    
    /* Table cells */
    td {
        border: 1px solid #ccc !important;
        padding: 3px 2px !important;
        font-size: 8px !important;
        vertical-align: top !important;
        word-wrap: break-word !important;
        max-width: 120px !important;
        overflow: hidden !important;
    }
    
    /* Prevent table rows from breaking across pages */
    tr {
        page-break-inside: avoid !important;
    }
    
    /* Make text in cells more readable */
    td div {
        font-size: 8px !important;
        line-height: 1.2 !important;
        margin: 0 !important;
    }
    
    /* Status badges - convert to simple text */
    .bg-green-100, .bg-blue-100, .bg-red-100, .bg-yellow-100 {
        background: none !important;
        border: 1px solid #000 !important;
        padding: 1px 3px !important;
        font-size: 7px !important;
        border-radius: 0 !important;
    }
    
    /* Progress bars - show as text */
    .bg-gray-200 {
        display: none !important;
    }
    
    .bg-green-600, .bg-blue-600, .bg-red-600 {
        display: none !important;
    }
    
    /* Analysis cards */
    .bg-gray-50.rounded-lg {
        background: white !important;
        border: 1px solid #000 !important;
        border-radius: 0 !important;
        margin-bottom: 15px !important;
        padding: 8px !important;
        page-break-inside: avoid !important;
    }
    
    /* Insight boxes */
    .bg-blue-50, .bg-red-50, .bg-green-50 {
        background: white !important;
        border: 1px solid #000 !important;
        border-radius: 0 !important;
        padding: 6px !important;
        margin-bottom: 8px !important;
    }
    
    .border-l-4 {
        border-left: 4px solid #000 !important;
    }
    
    /* Icons - convert to text or hide */
    .fas, .far {
        display: none !important;
    }
    
    /* Grid layouts - stack vertically */
    .grid.grid-cols-1.md\\:grid-cols-2,
    .grid.grid-cols-1.md\\:grid-cols-3,
    .grid.grid-cols-1.md\\:grid-cols-4,
    .grid.grid-cols-1.lg\\:grid-cols-2 {
        display: block !important;
    }
    
    .grid > div {
        margin-bottom: 8px !important;
        width: 100% !important;
    }
    
    /* Statistics cards */
    .bg-blue-50.rounded-lg,
    .bg-green-50.rounded-lg,
    .bg-purple-50.rounded-lg,
    .bg-yellow-50.rounded-lg {
        background: white !important;
        border: 1px solid #000 !important;
        border-radius: 0 !important;
        padding: 6px !important;
        text-align: center !important;
        display: inline-block !important;
        width: 23% !important;
        margin-right: 2% !important;
        vertical-align: top !important;
    }
    
    /* Force text to black */
    * {
        color: #000 !important;
        text-shadow: none !important;
    }
    
    /* Ensure tables don't break awkwardly */
    .overflow-x-auto {
        overflow: visible !important;
    }
    
    /* Summary statistics on same line */
    .mt-6.grid.grid-cols-1.md\\:grid-cols-4 {
        display: flex !important;
        flex-wrap: wrap !important;
        margin-top: 10px !important;
    }
    
    /* Page breaks */
    .bg-white.rounded-lg.shadow-sm.border.border-gray-200 {
        page-break-before: auto !important;
        page-break-after: auto !important;
        page-break-inside: avoid !important;
        margin-bottom: 20px !important;
    }
    
    /* Hide empty elements */
    .text-center.py-8 {
        display: none !important;
    }
    
    /* Compact spacing */
    .p-4, .p-6, .px-4, .px-6, .py-4, .py-6 {
        padding: 4px !important;
    }
    
    .mb-2, .mb-4, .mb-6, .mb-8 {
        margin-bottom: 6px !important;
    }
    
    .mt-2, .mt-4, .mt-6, .mt-8 {
        margin-top: 6px !important;
    }
    
    /* Whitespace handling for long text */
    .whitespace-nowrap {
        white-space: normal !important;
    }
    
    /* Add print header */
    body::before {
        content: "OnTheJob Tracker - Student Reports | Generated: " attr(data-print-date);
        display: block;
        text-align: center;
        font-weight: bold;
        border-bottom: 2px solid #000;
        padding-bottom: 8px;
        margin-bottom: 15px;
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
            <a href="GenerateReports.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-file-alt mr-3  text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Student Reports</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">Generate comprehensive student reports with AI-powered insights</p>
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
            <!-- Report Generation Form -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 sm:mb-8">
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <div class="flex items-center">
                        <i class="fas fa-chart-line text-blue-600 mr-3"></i>
                        <h3 class="text-lg font-medium text-gray-900">Report Configuration</h3>
                    </div>
                </div>
                
                <form method="POST" action="" class="p-4 sm:p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                        <div>
                            <label for="report_type" class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                            <select name="report_type" id="report_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Report Type</option>
                                <option value="student_information">Student Information Report</option>
                                <option value="student_deployment">Student Deployment Report</option>
                                <option value="student_attendance">Student Attendance Report</option>
                                <option value="student_complete_ojt">Student Complete OJT Report</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?php echo date('Y-m-01'); ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?php echo date('Y-m-d'); ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="student_filter" class="block text-sm font-medium text-gray-700 mb-2">Filter by Student (Optional)</label>
                            <select name="student_filter" id="student_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Students</option>
                                <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name'] . ' (' . $student['student_id'] . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 text-center">
                        <button type="submit" name="generate_report" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                            <i class="fas fa-chart-line mr-2"></i>
                            Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <?php if (isset($report_data)): ?>
            <!-- Export Options -->
            <div class="mb-6 flex justify-end space-x-2 export-options">
                <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-print mr-2"></i>
                    Print Report
                </button>
            </div>

            <!-- Report Results -->
            <?php if ($report_data['type'] === 'student_information'): ?>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <h2 class="text-xl font-bold text-gray-900 mb-2">Student Information Report</h2>
                            <p class="text-sm text-gray-600">Comprehensive student profile and status analysis</p>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if (!empty($report_data['students'])): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($report_data['students'] as $student): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['student_id']); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                        <div class="text-sm text-gray-500">Age: <?php echo $student['age']; ?> | <?php echo ucfirst($student['gender']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['program']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['section']); ?> | Year <?php echo htmlspecialchars($student['year_level']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['email']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['contact_number']); ?></div>
                                                    </td>

                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo $student['last_login'] ? date('M j, Y', strtotime($student['last_login'])) : 'Never'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Student Analysis Cards -->
                                <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <?php foreach ($report_data['students'] as $student): ?>
                                        <div class="bg-gray-50 rounded-lg p-6">
                                            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($student['full_name']); ?></h3>
                                            
                                            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                                                <div class="flex">
                                                    <div class="flex-shrink-0">
                                                        <i class="fas fa-user-check text-blue-400"></i>
                                                    </div>
                                                    <div class="ml-3">
                                                        <h4 class="text-sm font-medium text-blue-800">Profile Analysis</h4>
                                                        <p class="mt-1 text-sm text-blue-700"><?php echo htmlspecialchars($student['ai_insights']); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span class="font-medium text-gray-900">Department:</span>
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($student['department']); ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Created:</span>
                                                    <span class="text-gray-600"><?php echo date('M j, Y', strtotime($student['created_at'])); ?></span>
                                                </div>
                                                <div class="col-span-2">
                                                    <span class="font-medium text-gray-900">Address:</span>
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($student['address']); ?></span>
                                                </div>
                                                
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Students Found</h3>
                                    <p class="text-gray-600">No student information found for the selected criteria.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($report_data['type'] === 'student_deployment'): ?>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <h2 class="text-xl font-bold text-gray-900 mb-2">Student Deployment Report</h2>
                            <p class="text-sm text-gray-600">Current and historical deployment information</p>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if (!empty($report_data['deployments'])): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours Progress</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supervisor</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($report_data['deployments'] as $deployment): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($deployment['student_name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($deployment['program'] . ' - ' . $deployment['section']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($deployment['company_name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($deployment['company_contact'] ?: 'No contact'); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($deployment['position']); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($deployment['start_date'])); ?></div>
                                                        <div class="text-sm text-gray-500">to <?php echo date('M j, Y', strtotime($deployment['end_date'])); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo $deployment['duration_days']; ?> days</div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900"><?php echo $deployment['completed_hours']; ?> / <?php echo $deployment['required_hours']; ?> hrs</div>
                                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo min($deployment['completion_percentage'], 100); ?>%"></div>
                                                        </div>
                                                        <div class="text-xs text-gray-500"><?php echo $deployment['completion_percentage']; ?>%</div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php 
                                                        $status_class = '';
                                                        switch($deployment['deployment_status']) {
                                                            case 'Active': $status_class = 'bg-green-100 text-green-800'; break;
                                                            case 'Completed': $status_class = 'bg-blue-100 text-blue-800'; break;
                                                            case 'Terminated': $status_class = 'bg-red-100 text-red-800'; break;
                                                            case 'On Hold': $status_class = 'bg-yellow-100 text-yellow-800'; break;
                                                        }
                                                        ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                                            <?php echo $deployment['deployment_status']; ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($deployment['supervisor_name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($deployment['supervisor_position']); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($deployment['supervisor_email']); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Deployment Analysis -->
                                <div class="mt-8 space-y-6">
                                    <?php foreach ($report_data['deployments'] as $deployment): ?>
                                        <div class="bg-gray-50 rounded-lg p-6">
                                            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($deployment['student_name']); ?> - <?php echo htmlspecialchars($deployment['company_name']); ?></h3>
                                            
                                            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                                                <div class="flex">
                                                    <div class="flex-shrink-0">
                                                        <i class="fas fa-building text-blue-400"></i>
                                                    </div>
                                                    <div class="ml-3">
                                                        <h4 class="text-sm font-medium text-blue-800">Deployment Analysis</h4>
                                                        <p class="mt-1 text-sm text-blue-700"><?php echo htmlspecialchars($deployment['ai_insights']); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                                <div>
                                                    <span class="font-medium text-gray-900">Work Days:</span>
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($deployment['work_days'] ?: 'Not specified'); ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Deployment Date:</span>
                                                    <span class="text-gray-600"><?php echo date('M j, Y', strtotime($deployment['deployment_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Company Address:</span>
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($deployment['company_address']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-paper-plane text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Deployments Found</h3>
                                    <p class="text-gray-600">No student deployment data found for the selected criteria.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($report_data['type'] === 'student_attendance'): ?>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <h2 class="text-xl font-bold text-gray-900 mb-2">Student Attendance Report</h2>
                            <p class="text-sm text-gray-600">Attendance patterns and performance analysis</p>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if (!empty($report_data['attendance_data'])): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Days</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Late</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance Rate</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Hours</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Daily Hours</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($report_data['attendance_data'] as $attendance): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($attendance['student_name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($attendance['program'] . ' - ' . $attendance['section']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo $attendance['total_days_recorded']; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">
                                                        <?php echo $attendance['present_days']; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                                        <?php echo $attendance['absent_days']; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                                        <?php echo $attendance['late_days']; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php 
                                                        $rate = $attendance['attendance_rate'];
                                                        $rate_class = '';
                                                        if ($rate >= 95) $rate_class = 'bg-green-100 text-green-800';
                                                        elseif ($rate >= 85) $rate_class = 'bg-blue-100 text-blue-800';
                                                        elseif ($rate >= 75) $rate_class = 'bg-yellow-100 text-yellow-800';
                                                        else $rate_class = 'bg-red-100 text-red-800';
                                                        ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $rate_class; ?>">
                                                            <?php echo $rate; ?>%
                                                        </span>
                                                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                                            <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $rate; ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo $attendance['total_hours_completed'] ?: '0'; ?> hrs
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo round($attendance['avg_daily_hours'] ?: 0, 1); ?> hrs/day
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Attendance Analysis -->
                                <div class="mt-8 space-y-6">
                                    <?php foreach ($report_data['attendance_data'] as $attendance): ?>
                                        <div class="bg-gray-50 rounded-lg p-6">
                                            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($attendance['student_name']); ?> - Attendance Analysis</h3>
                                            
                                            <?php if ($attendance['attendance_rate'] < 85): ?>
                                                <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4">
                                                    <div class="flex">
                                                        <div class="flex-shrink-0">
                                                            <i class="fas fa-exclamation-triangle text-red-400"></i>
                                                        </div>
                                                        <div class="ml-3">
                                                            <h4 class="text-sm font-medium text-red-800">ATTENTION REQUIRED</h4>
                                                            <p class="mt-1 text-sm text-red-700"><?php echo htmlspecialchars($attendance['ai_insights']); ?></p>
                                                            <?php if (!empty($attendance['recommendations'])): ?>
                                                                <div class="mt-2">
                                                                    <h5 class="text-sm font-medium text-red-800">Recommendations:</h5>
                                                                    <ul class="mt-1 text-sm text-red-700 list-disc list-inside space-y-1">
                                                                        <?php foreach ($attendance['recommendations'] as $rec): ?>
                                                                            <li><?php echo htmlspecialchars($rec); ?></li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                                                    <div class="flex">
                                                        <div class="flex-shrink-0">
                                                            <i class="fas fa-chart-line text-blue-400"></i>
                                                        </div>
                                                        <div class="ml-3">
                                                            <h4 class="text-sm font-medium text-blue-800">Attendance Performance</h4>
                                                            <p class="mt-1 text-sm text-blue-700"><?php echo htmlspecialchars($attendance['ai_insights']); ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span class="font-medium text-gray-900">First Attendance:</span>
                                                    <span class="text-gray-600"><?php echo date('M j, Y', strtotime($attendance['first_attendance_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Last Attendance:</span>
                                                    <span class="text-gray-600"><?php echo date('M j, Y', strtotime($attendance['last_attendance_date'])); ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Excused Days:</span>
                                                    <span class="text-gray-600"><?php echo $attendance['excused_days']; ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium text-gray-900">Period:</span>
                                                    <span class="text-gray-600"><?php echo date('M j', strtotime($attendance['first_attendance_date'])) . ' - ' . date('M j, Y', strtotime($attendance['last_attendance_date'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-calendar-check text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Attendance Records Found</h3>
                                    <p class="text-gray-600">No attendance data found for the selected criteria.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

              <?php elseif ($report_data['type'] === 'student_complete_ojt'): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-4 sm:p-6 border-b border-gray-200">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Student Complete OJT Report</h2>
            <p class="text-sm text-gray-600">Comprehensive analysis of completed OJT programs</p>
        </div>
        
        <div class="p-4 sm:p-6">
            <?php if (!empty($report_data['completed_ojt'])): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours Completed</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evaluation Score</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Completion</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completion Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data['completed_ojt'] as $ojt): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ojt['student_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($ojt['program'] . ' - ' . $ojt['section']); ?></div>
                                        <div class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($ojt['student_id']); ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($ojt['company_name']); ?></div>
                                        <div class="text-xs text-gray-500">Supervisor: <?php echo htmlspecialchars($ojt['supervisor_name']); ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($ojt['position']); ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M j', strtotime($ojt['start_date'])); ?> - <?php echo date('M j, Y', strtotime($ojt['end_date'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500"><?php echo $ojt['total_days']; ?> days</div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 mb-1">
                                            <?php echo $ojt['completed_hours']; ?> / <?php echo $ojt['required_hours']; ?> hrs
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2 mb-1">
                                            <div class="bg-green-500 h-2 rounded-full transition-all duration-300" style="width: <?php echo min($ojt['hours_completion_rate'], 100); ?>%"></div>
                                        </div>
                                        <div class="text-xs text-center text-gray-600"><?php echo $ojt['hours_completion_rate']; ?>%</div>
                                        <?php 
                                        $completion_class = '';
                                        switch($ojt['completion_status']) {
                                            case 'Fully Completed': $completion_class = 'bg-green-100 text-green-800'; break;
                                            case 'Nearly Completed': $completion_class = 'bg-blue-100 text-blue-800'; break;
                                            case 'Incomplete Hours': $completion_class = 'bg-red-100 text-red-800'; break;
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $completion_class; ?> mt-1">
                                            <?php echo $ojt['completion_status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <?php if ($ojt['avg_total_score']): ?>
                                            <div class="text-lg font-bold text-gray-900"><?php echo round($ojt['avg_total_score'], 1); ?></div>
                                            <div class="text-xs text-gray-500">out of 40</div>
                                            <?php 
                                            $score_percentage = ($ojt['avg_total_score'] / 40) * 100;
                                            $score_class = '';
                                            if ($score_percentage >= 85) $score_class = 'bg-green-500';
                                            elseif ($score_percentage >= 70) $score_class = 'bg-blue-500';
                                            else $score_class = 'bg-red-500';
                                            ?>
                                            
                                            <?php if ($ojt['avg_evaluation_score']): ?>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    Rating: <?php echo round($ojt['avg_evaluation_score'], 1); ?>/5.0
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-500">No evaluations</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <?php if ($ojt['task_completion_rate']): ?>
                                            <div class="text-lg font-bold text-gray-900"><?php echo $ojt['task_completion_rate']; ?>%</div>
                                            <?php 
                                            $task_class = '';
                                            if ($ojt['task_completion_rate'] >= 90) $task_class = 'bg-green-500';
                                            elseif ($ojt['task_completion_rate'] >= 70) $task_class = 'bg-blue-500';
                                            else $task_class = 'bg-red-500';
                                            ?>
                                            <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                                <div class="<?php echo $task_class; ?> h-2 rounded-full transition-all duration-300" style="width: <?php echo $ojt['task_completion_rate']; ?>%"></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-sm text-gray-500">No tasks</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-center">
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M j, Y', strtotime($ojt['completion_date'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('g:i A', strtotime($ojt['completion_date'])); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary Statistics -->
                <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                    <?php
                    $total_students = count($report_data['completed_ojt']);
                    $avg_hours_completion = array_sum(array_column($report_data['completed_ojt'], 'hours_completion_rate')) / $total_students;
                    $completed_evaluations = array_filter(array_column($report_data['completed_ojt'], 'avg_total_score'));
                    $avg_total_score = !empty($completed_evaluations) ? array_sum($completed_evaluations) / count($completed_evaluations) : 0;
                    $completed_tasks = array_filter(array_column($report_data['completed_ojt'], 'task_completion_rate'));
                    $avg_task_completion = !empty($completed_tasks) ? array_sum($completed_tasks) / count($completed_tasks) : 0;
                    ?>
                    
                    <div class="bg-blue-50 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $total_students; ?></div>
                        <div class="text-sm text-blue-800">Students Completed</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-green-600"><?php echo round($avg_hours_completion, 1); ?>%</div>
                        <div class="text-sm text-green-800">Avg Hours Completion</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-purple-600"><?php echo $avg_total_score ? round($avg_total_score, 1) : 'N/A'; ?></div>
                        <div class="text-sm text-purple-800">Avg Total Score (out of 40)</div>
                    </div>
                    <div class="bg-yellow-50 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-yellow-600"><?php echo $avg_task_completion ? round($avg_task_completion, 1) . '%' : 'N/A'; ?></div>
                        <div class="text-sm text-yellow-800">Avg Task Completion</div>
                    </div>
                </div>
                
                <!-- OJT Completion Analysis -->
                <div class="mt-8 space-y-6">
                    <?php foreach ($report_data['completed_ojt'] as $ojt): ?>
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($ojt['student_name']); ?> - OJT Performance Summary</h3>
                            
                            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-award text-blue-400"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h4 class="text-sm font-medium text-blue-800">OJT Completion Analysis</h4>
                                        <p class="mt-1 text-sm text-blue-700"><?php echo htmlspecialchars($ojt['ai_insights']); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($ojt['performance_analysis'])): ?>
                                <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-4">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-chart-bar text-green-400"></i>
                                        </div>
                                        <div class="ml-3">
                                            <h4 class="text-sm font-medium text-green-800">Overall Performance Assessment</h4>
                                            <div class="mt-1 text-sm text-green-700">
                                                <?php foreach ($ojt['performance_analysis'] as $analysis): ?>
                                                    <p class="mb-1"><?php echo htmlspecialchars($analysis); ?></p>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-blue-600"><?php echo $ojt['hours_completion_rate']; ?>%</div>
                                        <div class="text-gray-600">Hours Completion</div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $ojt['completed_hours']; ?>/<?php echo $ojt['required_hours']; ?> hours
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                            <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" style="width: <?php echo min($ojt['hours_completion_rate'], 100); ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600"><?php echo $ojt['avg_total_score'] ? round($ojt['avg_total_score'], 1) : 'N/A'; ?></div>
                                        <div class="text-gray-600">Total Score</div>
                                        <div class="text-xs text-gray-500 mt-1">out of 40</div>
                                        <?php if ($ojt['avg_total_score']): ?>
                                            <?php $score_percentage = ($ojt['avg_total_score'] / 40) * 100; ?>
                                            
                                        <?php endif; ?>
                                       
                                    </div>
                                </div>
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-purple-600"><?php echo $ojt['task_completion_rate'] ?: 'N/A'; ?><?php echo $ojt['task_completion_rate'] ? '%' : ''; ?></div>
                                        <div class="text-gray-600">Task Completion</div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            OJT Status: <?php echo $ojt['ojt_status']; ?>
                                        </div>
                                        <?php if ($ojt['task_completion_rate']): ?>
                                            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                                <div class="bg-purple-500 h-2 rounded-full transition-all duration-300" style="width: <?php echo $ojt['task_completion_rate']; ?>%"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-graduation-cap text-gray-400 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Completed OJT Records Found</h3>
                    <p class="text-gray-600">No completed OJT data found for the selected criteria.</p>
                    <div class="mt-4 text-sm text-gray-500">
                        <p>Make sure students have their <strong>ojt_status</strong> set to 'Completed' in the database.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        }

        function closeSidebarMenu() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        }

        mobileMenuBtn.addEventListener('click', openSidebar);
        closeSidebar.addEventListener('click', closeSidebarMenu);
        sidebarOverlay.addEventListener('click', closeSidebarMenu);

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

        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

      
        document.body.setAttribute('data-print-date', new Date().toLocaleDateString());


        // Confirm logout
        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Report type change handler
        document.getElementById('report_type').addEventListener('change', function() {
            const selectedType = this.value;
            const descriptions = {
                'student_information': 'Generate a comprehensive report of student profiles, verification status, and deployment readiness.',
                'student_deployment': 'Analyze current and historical student deployments including company details and progress tracking.',
                'student_attendance': 'Review attendance patterns, tardiness, and hour completion across all students.',
                'student_complete_ojt': 'Comprehensive analysis of completed OJT programs with performance metrics and evaluations.'
            };
            
            // You could add a description display here if needed
            console.log('Selected report type:', selectedType);
        });

        function printReport() {
    // Set print date
    document.body.setAttribute('data-print-date', new Date().toLocaleDateString());
    
    // Store original title
    const originalTitle = document.title;
    
    // Set a descriptive title for printing
    const reportType = document.getElementById('report_type').value;
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    if (reportType) {
        document.title = `Student Reports - ${reportType.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())} (${dateFrom} to ${dateTo})`;
    }
    
    // Add print-specific adjustments before printing
    addPrintOptimizations();
    
    // Print
    window.print();
    
    // Restore original title
    document.title = originalTitle;
    
    // Clean up print optimizations after print dialog closes
    setTimeout(() => {
        removePrintOptimizations();
    }, 1000);
}

function addPrintOptimizations() {
    // Convert progress bars to text
    const progressBars = document.querySelectorAll('.w-full.bg-gray-200.rounded-full.h-2');
    progressBars.forEach(bar => {
        const progressDiv = bar.querySelector('[style*="width"]');
        if (progressDiv) {
            const width = progressDiv.style.width;
            const textSpan = document.createElement('span');
            textSpan.textContent = ` (${width})`;
            textSpan.className = 'print-progress-text';
            bar.parentNode.insertBefore(textSpan, bar.nextSibling);
        }
    });
    
    // Add print headers to each report section
    const reportSections = document.querySelectorAll('.bg-white.rounded-lg.shadow-sm.border.border-gray-200');
    reportSections.forEach((section, index) => {
        if (index > 0) { // Skip the form section
            const header = section.querySelector('h2');
            if (header) {
                const printHeader = document.createElement('div');
                printHeader.className = 'print-section-header';
                printHeader.innerHTML = `
                    <div style="border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 10px;">
                        <strong>OnTheJob Tracker - ${header.textContent}</strong><br>
                        <small>Generated: ${new Date().toLocaleString()}</small>
                    </div>
                `;
                section.insertBefore(printHeader, section.firstChild);
            }
        }
    });
    
    // Optimize table cell content for printing
    const tableCells = document.querySelectorAll('td');
    tableCells.forEach(cell => {
        // Combine multiple divs in cells into single text
        const divs = cell.querySelectorAll('div');
        if (divs.length > 1) {
            let combinedText = [];
            divs.forEach(div => {
                if (div.textContent.trim() && !div.querySelector('.bg-gray-200')) {
                    combinedText.push(div.textContent.trim());
                }
            });
            if (combinedText.length > 0) {
                const newSpan = document.createElement('span');
                newSpan.textContent = combinedText.join(' | ');
                newSpan.className = 'print-combined-text';
                cell.appendChild(newSpan);
            }
        }
    });
    
    // Convert status badges to simple text
    const badges = document.querySelectorAll('.inline-flex.items-center.px-2\\.5.py-0\\.5.rounded-full');
    badges.forEach(badge => {
        const textSpan = document.createElement('span');
        textSpan.textContent = `[${badge.textContent.trim()}]`;
        textSpan.className = 'print-badge-text';
        badge.parentNode.replaceChild(textSpan, badge);
    });
}

function removePrintOptimizations() {
    // Remove added print elements
    const printElements = document.querySelectorAll('.print-progress-text, .print-section-header, .print-combined-text, .print-badge-text');
    printElements.forEach(el => el.remove());
    
    // Restore original elements would be complex, so we could reload if needed
    // For now, the page should work fine with these additions
}

// Update the existing print button event
document.addEventListener('DOMContentLoaded', function() {
    // Replace existing print button functionality
    const printButtons = document.querySelectorAll('[onclick="window.print()"]');
    printButtons.forEach(button => {
        button.removeAttribute('onclick');
        button.addEventListener('click', printReport);
    });
    
    // Add keyboard shortcut for printing
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printReport();
        }
    });
    
    // Rest of your existing DOMContentLoaded code...
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
});
    </script>
</body>
</html>