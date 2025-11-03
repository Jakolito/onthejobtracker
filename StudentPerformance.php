
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

    // Get adviser role and assignment details from database
    $adviser_query = "SELECT role, department, year_level, section, assigned_groups FROM academic_adviser WHERE id = ?";
    $adviser_stmt = mysqli_prepare($conn, $adviser_query);
    mysqli_stmt_bind_param($adviser_stmt, "i", $adviser_id);
    mysqli_stmt_execute($adviser_stmt);
    $adviser_result = mysqli_stmt_get_result($adviser_stmt);
    $adviser_data = mysqli_fetch_assoc($adviser_result);
    $adviser_role = $adviser_data['role'] ?? 'adviser';
    $adviser_department = $adviser_data['department'];
    $adviser_year_level = $adviser_data['year_level'];
    $adviser_section = $adviser_data['section'];
    $adviser_assigned_groups = $adviser_data['assigned_groups'];
    mysqli_stmt_close($adviser_stmt);

    $unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_type = 'adviser' AND sender_type = 'student' AND is_read = 0 AND is_deleted_by_recipient = 0";
    $unread_messages_result = mysqli_query($conn, $unread_messages_query);
    $unread_messages_count = mysqli_fetch_assoc($unread_messages_result)['count'];

    // Initialize variables
    $search = '';
    $department_filter = '';
    $section_filter = '';
    $status_filter = '';
    $company_filter = '';
    $total_pages = 1;

    // BUILD WHERE CLAUSE BASED ON ROLE AND ASSIGNMENTS
    $student_where_clause = "1=1";

    if ($adviser_role === 'coordinator') {
        if (!empty($adviser_assigned_groups) && $adviser_assigned_groups !== NULL) {
            $groups = array_map('trim', explode(',', $adviser_assigned_groups));
            $group_conditions = [];
            
            foreach ($groups as $group) {
                if (!empty($group)) {
                    $group_escaped = mysqli_real_escape_string($conn, $group);
                    $group_with_hyphen = str_replace(' G', '-G', $group_escaped);
                    $group_with_space = str_replace('-G', ' G', $group_escaped);
                    
                    $group_conditions[] = "(
                        TRIM(s.section) = TRIM('$group_escaped')
                        OR TRIM(s.section) = TRIM('$group_with_hyphen')
                        OR TRIM(s.section) = TRIM('$group_with_space')
                    )";
                }
            }
            
            if (!empty($group_conditions)) {
                $student_where_clause .= " AND (" . implode(" OR ", $group_conditions) . ")";
            }
        }
    } elseif ($adviser_role === 'adviser') {
        if (!empty($adviser_assigned_groups) && $adviser_assigned_groups !== NULL) {
            $groups = array_map('trim', explode(',', $adviser_assigned_groups));
            $group_conditions = [];
            
            foreach ($groups as $group) {
                if (!empty($group)) {
                    $group_escaped = mysqli_real_escape_string($conn, $group);
                    $group_with_hyphen = str_replace(' G', '-G', $group_escaped);
                    $group_with_space = str_replace('-G', ' G', $group_escaped);
                    
                    $group_conditions[] = "(
                        TRIM(s.section) = TRIM('$group_escaped')
                        OR TRIM(s.section) = TRIM('$group_with_hyphen')
                        OR TRIM(s.section) = TRIM('$group_with_space')
                    )";
                }
            }
            
            if (!empty($group_conditions)) {
                $student_where_clause .= " AND (" . implode(" OR ", $group_conditions) . ")";
            } else {
                $student_where_clause .= " AND 1=0";
            }
        } else {
            $student_where_clause .= " AND 1=0";
        }
    }

    $view_student = isset($_GET['view_student']) ? $_GET['view_student'] : null;

    if (!$view_student) {
        $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
        $section_filter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';
        $status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
        $company_filter = isset($_GET['company']) ? mysqli_real_escape_string($conn, $_GET['company']) : '';
    }

    $risk_filter = $status_filter;

    $records_per_page = 10;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $records_per_page;

    /**
     * Calculate Final OJT Grade (70% Supervisor + 30% Coordinator)
     */
    function calculateFinalOJTGrade($student_id, $conn) {
        $grade_data = [
            'has_supervisor_evaluation' => false,
            'has_coordinator_evaluation' => false,
            'supervisor_grade' => 0,
            'coordinator_grade' => 0,
            'final_grade' => 0,
            'final_percentage' => 0,
            'verbal_interpretation' => 'Not Yet Evaluated',
            'risk_level' => 'unknown',
            'is_complete' => false,
            'missing_components' => [],
            'supervisor_weight' => 70,
            'coordinator_weight' => 30
        ];
        
        // Get Company Supervisor's Evaluation (70%)
        $supervisor_query = "
            SELECT 
                se.equivalent_rating,
                se.verbal_interpretation,
                se.total_score,
                se.created_at,
                cs.full_name as supervisor_name,
                cs.company_name
            FROM student_evaluations se
            JOIN company_supervisors cs ON se.supervisor_id = cs.supervisor_id
            WHERE se.student_id = ?
            ORDER BY se.created_at DESC
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($supervisor_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $supervisor_result = $stmt->get_result();
        
        if ($supervisor_result && $supervisor_result->num_rows > 0) {
            $supervisor_data = $supervisor_result->fetch_assoc();
            $grade_data['has_supervisor_evaluation'] = true;
            $grade_data['supervisor_grade'] = floatval($supervisor_data['equivalent_rating']);
            $grade_data['supervisor_score'] = intval($supervisor_data['total_score']);
            $grade_data['supervisor_interpretation'] = $supervisor_data['verbal_interpretation'];
            $grade_data['supervisor_name'] = $supervisor_data['supervisor_name'];
            $grade_data['company_name'] = $supervisor_data['company_name'];
            $grade_data['supervisor_date'] = $supervisor_data['created_at'];
        } else {
            $grade_data['missing_components'][] = 'Company Supervisor Evaluation (70%)';
        }
        $stmt->close();
        
        // Get Coordinator's Evaluation (30%)
        $coordinator_query = "
            SELECT 
                ae.coordinator_grade,
                ae.quality_of_work,
                ae.completeness_of_work,
                ae.urgency_of_output,
                ae.attendance_promptness,
                ae.evaluated_at,
                aa.name as coordinator_name
            FROM academicstudentevaluation ae
            JOIN academic_adviser aa ON ae.evaluated_by = aa.id
            WHERE ae.student_id = ?
            ORDER BY ae.evaluated_at DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($coordinator_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $coordinator_result = $stmt->get_result();

        if ($coordinator_result && $coordinator_result->num_rows > 0) {
            $coordinator_data = $coordinator_result->fetch_assoc();
            $grade_data['has_coordinator_evaluation'] = true;
            
            // Calculate raw average from individual components (out of 100)
            $raw_average = (
                (floatval($coordinator_data['quality_of_work']) * 0.40) +
                (floatval($coordinator_data['completeness_of_work']) * 0.40) +
                (floatval($coordinator_data['urgency_of_output']) * 0.10) +
                (floatval($coordinator_data['attendance_promptness']) * 0.10)
            );
            
            // Store the raw score (this will be 81%)
            $grade_data['coordinator_raw_score'] = $raw_average;
            
            // Calculate contribution as 30% of total grade (81/100 * 30 = 24.3%)
            $grade_data['coordinator_grade'] = ($raw_average / 100) * 30;
            
            $grade_data['coordinator_quality'] = floatval($coordinator_data['quality_of_work']);
            $grade_data['coordinator_completeness'] = floatval($coordinator_data['completeness_of_work']);
            $grade_data['coordinator_urgency'] = floatval($coordinator_data['urgency_of_output']);
            $grade_data['coordinator_attendance'] = floatval($coordinator_data['attendance_promptness']);
            $grade_data['coordinator_name'] = $coordinator_data['coordinator_name'];
            $grade_data['coordinator_date'] = $coordinator_data['evaluated_at'];
        } else {
            $grade_data['missing_components'][] = 'Coordinator Evaluation (30%)';
        }
        $stmt->close();
        
        // Calculate current score and risk level (works for both complete and incomplete)
        $supervisor_contribution = 0;
        $coordinator_contribution = 0;
        
        if ($grade_data['has_supervisor_evaluation']) {
            $supervisor_contribution = ($grade_data['supervisor_grade'] / 100) * 70;
        }
        
        if ($grade_data['has_coordinator_evaluation']) {
            $coordinator_contribution = $grade_data['coordinator_grade'];
        }
        
        // Calculate current total (even if incomplete)
        $current_total = $supervisor_contribution + $coordinator_contribution;
        
        // If both evaluations exist, mark as complete
        if ($grade_data['has_supervisor_evaluation'] && $grade_data['has_coordinator_evaluation']) {
            $grade_data['final_grade'] = $current_total;
            $grade_data['final_percentage'] = round($grade_data['final_grade'], 2);
            $grade_data['is_complete'] = true;
            
            // Determine verbal interpretation based on final grade
            if ($grade_data['final_percentage'] >= 85) {
                $grade_data['verbal_interpretation'] = 'Excellent';
            } elseif ($grade_data['final_percentage'] >= 82) {
                $grade_data['verbal_interpretation'] = 'Very Good';
            } elseif ($grade_data['final_percentage'] >= 79) {
                $grade_data['verbal_interpretation'] = 'Good';
            } elseif ($grade_data['final_percentage'] >= 76) {
                $grade_data['verbal_interpretation'] = 'Fair';
            } elseif ($grade_data['final_percentage'] >= 75) {
                $grade_data['verbal_interpretation'] = 'Passed';
            } else {
                $grade_data['verbal_interpretation'] = 'Conditional Passed / Failed';
            }
        }
        
        // ALWAYS determine risk level based on current score (complete or incomplete)
        // Updated to match dropdown ranges: Low (82-100), Medium (75-81), Very High (<75)
        if ($current_total >= 82) {
            $grade_data['risk_level'] = 'low';
        } elseif ($current_total >= 75) {
            $grade_data['risk_level'] = 'medium';
        } else {
            $grade_data['risk_level'] = 'very_high';
        } 
        
        return $grade_data;
    }

    // Fetch filter dropdown data
    // Only fetch filter dropdown data if not viewing specific student
if (!$view_student) {
    // GET FILTER DROPDOWN DATA (with role-based filtering)
    try {
        // Get unique sections for filter dropdown (from visible students only)
        $sections_query = "SELECT DISTINCT s.section FROM students s 
                  INNER JOIN student_deployments sd ON s.id = sd.student_id 
                  WHERE s.section IS NOT NULL AND s.section != '' 
                  AND $student_where_clause
                  AND sd.status IN ('Active', 'Completed')
                  ORDER BY s.section";
        $sections_result = mysqli_query($conn, $sections_query);

        // Get unique companies for filter dropdown (from visible students only)
        $companies_query = "SELECT DISTINCT sd.company_name FROM student_deployments sd 
                   INNER JOIN students s ON sd.student_id = s.id
                   WHERE sd.company_name IS NOT NULL AND sd.company_name != '' 
                   AND $student_where_clause
                   AND sd.status IN ('Active', 'Completed')
                   ORDER BY sd.company_name";
        $companies_result = mysqli_query($conn, $companies_query);

    } catch (Exception $e) {
        $error_message = "Error fetching filter data: " . $e->getMessage();
    }
}

    if ($view_student) {
        try {
            // Get basic student info
            $student_query = "
                SELECT s.*, 
                    sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
                    sd.required_hours, sd.completed_hours, sd.status as deployment_status,
                    sd.company_name, sd.supervisor_name, sd.supervisor_email, sd.ojt_status
                FROM students s
                LEFT JOIN student_deployments sd ON s.id = sd.student_id
                WHERE s.id = ? AND s.verified = 1
            ";
            $stmt = $conn->prepare($student_query);
            $stmt->bind_param("i", $view_student);
            $stmt->execute();
            $student_details = $stmt->get_result()->fetch_assoc();
            
            if ($student_details) {
                $grade_data = calculateFinalOJTGrade($view_student, $conn);
                
                if ($student_details['required_hours'] > 0) {
                    $progress_percentage = ($student_details['completed_hours'] / $student_details['required_hours']) * 100;
                    $progress_percentage = min(100, round($progress_percentage, 1));
                } else {
                    $progress_percentage = 0;
                }
            }
        } catch (Exception $e) {
            $error_message = "Error fetching student details: " . $e->getMessage();
        }
    } else {
    try {
        // BUILD WHERE CONDITIONS FOR FILTERING
        $where_conditions = array();
        
        if (!empty($search)) {
            $where_conditions[] = "(s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%' OR s.student_id LIKE '%$search%' OR s.email LIKE '%$search%' OR sd.company_name LIKE '%$search%')";
        }

        if (!empty($section_filter)) {
            $where_conditions[] = "s.section = '$section_filter'";
        }

        if (!empty($company_filter)) {
            $where_conditions[] = "sd.company_name = '$company_filter'";
        }

        // Combine role-based filter with other conditions
        if (count($where_conditions) > 0) {
            $where_clause = $student_where_clause . " AND (" . implode(' AND ', $where_conditions) . ")";
        } else {
            $where_clause = $student_where_clause;
        }
        
        // Add deployment status filter
        $where_clause .= " AND sd.status IN ('Active', 'Completed') AND (sd.ojt_status IS NULL OR sd.ojt_status IN ('Active', 'Completed'))";
            // Main query - Get ALL students first
            $students_query = "
                SELECT DISTINCT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, 
                    s.program, s.year_level, s.profile_picture, s.department, s.section,
                    s.contact_number, s.email,
                    
                    sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
                    sd.required_hours, sd.completed_hours, sd.status as deployment_status,
                    sd.company_name, sd.supervisor_name, sd.ojt_status
                    
                FROM students s
                INNER JOIN student_deployments sd ON s.id = sd.student_id 
                    AND sd.status IN ('Active', 'Completed') 
                    AND (sd.ojt_status IS NULL OR sd.ojt_status IN ('Active', 'Completed'))
                
                WHERE $where_clause AND sd.deployment_id IS NOT NULL
                ORDER BY s.last_name, s.first_name
            ";

            $students_result = mysqli_query($conn, $students_query);
            
            if (!$students_result) {
                throw new Exception("Query failed: " . mysqli_error($conn));
            }
            
            $students_with_grades = [];
            
            // Calculate grades for ALL students first
            while ($row = mysqli_fetch_assoc($students_result)) {
                $grade_data = calculateFinalOJTGrade($row['id'], $conn);
                $row['grade_data'] = $grade_data;
                $students_with_grades[] = $row;
            }
            
            // Filter by risk level if selected
            if (!empty($risk_filter)) {
                $students_with_grades = array_filter($students_with_grades, function($student) use ($risk_filter) {
                    return isset($student['grade_data']['risk_level']) && 
                        $student['grade_data']['risk_level'] === $risk_filter;
                });
                
                // Re-index array after filtering
                $students_with_grades = array_values($students_with_grades);
            }
            
            // Update total records based on filtered results
            $total_records = count($students_with_grades);
            $total_pages = ceil($total_records / $records_per_page);
            
            // Apply pagination AFTER filtering
            $students_data = array_slice($students_with_grades, $offset, $records_per_page);
            
        } catch (Exception $e) {
            $error_message = "Error fetching students data: " . $e->getMessage();
            $students_data = [];
            $total_records = 0;
        }
    }

    function getRiskBadgeClass($riskLevel) {
        switch($riskLevel) {
            case 'low': return 'bg-green-100 text-green-800';
            case 'medium': return 'bg-yellow-100 text-yellow-800';
            case 'very_high': return 'bg-red-100 text-red-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    }

    function getRiskLabel($riskLevel) {
        switch($riskLevel) {
            case 'low': return 'Low Risk (82-100%)';
            case 'medium': return 'Medium Risk (75-81%)';
            case 'very_high': return 'Very High Risk (<75%)';
            default: return 'Unknown';
        }
    }

    $adviser_initials = strtoupper(substr($adviser_name, 0, 2));

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
        <title>OnTheJob Tracker - Student Performance</title>
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
            .notification-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                margin-left: 8px;
                background: #EF4444;
                color: white;
                font-size: 11px;
                font-weight: 600;
                border-radius: 10px;
                animation: pulse 2s infinite;
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
            
            .grade-breakdown-card {
                border-left: 4px solid;
                transition: all 0.3s ease;
            }
            
            .grade-breakdown-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
        </style>
    </head>
    <body class="bg-gray-50">
        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden sidebar-overlay"></div>

        <!-- Sidebar -->
        <div id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gradient-to-b from-bulsu-maroon to-bulsu-dark-maroon shadow-lg z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
        <div class="flex justify-end p-4 lg:hidden">
            <button id="closeSidebar" class="text-bulsu-light-gold hover:text-bulsu-gold">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-6 py-4 border-b border-bulsu-gold border-opacity-30">
            <div class="flex items-center">
                <img src="reqsample/bulsu12.png" alt="BULSU Logo 2" class="w-14 h-14 mr-2">
                <div class="flex items-center font-bold text-lg text-white">
                    <span>OnTheJob</span>
                    <span class="ml-1">Tracker</span>
                </div>
            </div>
        </div>
        
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
                <a href="StudentPerformance.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                    <i class="fas fa-chart-line mr-3 text-bulsu-gold"></i>
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
                <a href="AcademicStudentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-star mr-3"></i>
                    Student Evaluation
                </a>
                <?php if ($adviser_role === 'coordinator'): ?>
                <a href="AcademicAccounts.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-bulsu-light-gold hover:text-white hover:bg-bulsu-gold hover:bg-opacity-20 rounded-md transition-all duration-200">
                    <i class="fas fa-user-tie mr-3"></i>
                    Academic Accounts
                </a>
                <?php endif; ?>
            </nav>
        </div>
        
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
                    <p class="text-xs text-bulsu-light-gold"><?php echo ucfirst($adviser_role); ?></p>
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
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">
                            <?php if (!$view_student): ?>
                                Student Performance 
                            <?php else: ?>
                                <?php echo htmlspecialchars($student_details['first_name'] . ' ' . $student_details['last_name']); ?>
                            <?php endif; ?>
                        </h1>
                        <?php if ($view_student && isset($student_details)): ?>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php echo htmlspecialchars($student_details['program']); ?> - 
                                <?php echo htmlspecialchars($student_details['year_level']); ?> Year
                            </p>
                        <?php endif; ?>
                    </div>

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
            
            <?php if (!$view_student): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                    <div class="p-4 sm:p-6">
                        <form method="GET" action="" id="filterForm">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Search Students</label>
                                    <div class="relative">
                                        <input type="text" name="search" id="searchInput" 
                                            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                            placeholder="Search by name, email, or ID..." 
                                            value="<?php echo htmlspecialchars($search); ?>">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <i class="fas fa-search text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                                    <input type="text" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 cursor-not-allowed" 
                                        value="<?php echo htmlspecialchars($adviser_department ?? 'Not Assigned'); ?>" 
                                        readonly 
                                        title="Your assigned department">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Section</label>
                                    <select name="section" id="sectionFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Sections</option>
                                        <?php 
                                        if (isset($sections_result)) {
                                            mysqli_data_seek($sections_result, 0);
                                            while ($sect = mysqli_fetch_assoc($sections_result)): ?>
                                                <option value="<?php echo htmlspecialchars($sect['section']); ?>" 
                                                        <?php echo $section_filter === $sect['section'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($sect['section']); ?>
                                                </option>
                                            <?php endwhile;
                                        } ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Risk Level</label>
                                    <select name="status" id="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Risk Levels</option>
                                        <option value="low" <?php echo $status_filter === 'low' ? 'selected' : ''; ?>>Low Risk (82-100%)</option>
                                        <option value="medium" <?php echo $status_filter === 'medium' ? 'selected' : ''; ?>>Medium Risk (75-81%)</option>
                                        <option value="very_high" <?php echo $status_filter === 'very_high' ? 'selected' : ''; ?>>Very High Risk (&lt;75%)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Company</label>
                                    <select name="company" id="companyFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Companies</option>
                                        <?php 
                                        if (isset($companies_result)) {
                                            mysqli_data_seek($companies_result, 0);
                                            while ($company = mysqli_fetch_assoc($companies_result)): ?>
                                                <option value="<?php echo htmlspecialchars($company['company_name']); ?>" 
                                                        <?php echo $company_filter === $company['company_name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($company['company_name']); ?>
                                                </option>
                                            <?php endwhile;
                                        } ?>
                                    </select>
                                </div>
                            </div>

                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-gray-200">
                                <div class="flex space-x-3">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-filter mr-2"></i>
                                        Apply Filters
                                    </button>
                            
                                </div>
                                
                                <?php if (isset($total_records)): ?>
                                <div class="text-sm text-gray-600">
                                    Showing <?php echo count($students_data); ?> of <?php echo $total_records; ?> students
                                </div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <div class="p-4 sm:p-6">
                    <a href="StudentPerformance.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 mb-6">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Students List
                    </a>
                </div>
            <?php endif; ?>
            
            <div class="p-4 sm:p-6">
                <?php if (isset($error_message)): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$view_student): ?>
                    <!-- Students Overview Table -->
                    <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                        <div class="px-4 py-5 sm:px-6 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon">
                            <h3 class="text-lg leading-6 font-medium text-white flex items-center">
                                <i class="fas fa-chart-line text-bulsu-gold mr-2"></i>
                                Students Performance Dashboard
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm text-bulsu-light-gold">
                                Final grades calculated: 70% Supervisor + 30% Coordinator
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Risk Level</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($students_data)): ?>
                                        <?php foreach ($students_data as $student): ?>
                                            <?php
                                            $grade_data = $student['grade_data'];
                                            $risk_level = $grade_data['risk_level'];
                                            
                                            $progress_percentage = $student['required_hours'] > 0 ? 
                                                min(100, ($student['completed_hours'] / $student['required_hours']) * 100) : 0;
                                            ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10">
                                                            <?php if (!empty($student['profile_picture'])): ?>
                                                                <img class="h-10 w-10 rounded-full object-cover" 
                                                                    src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                                    alt="Profile">
                                                            <?php else: ?>
                                                                <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                                    <span class="text-sm font-medium text-gray-600">
                                                                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?php echo htmlspecialchars($student['student_id']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['program']); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['year_level']); ?> Year</div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($student['company_name'] ?? 'Not Assigned'); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($student['position'] ?? ''); ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900"><?php echo round($progress_percentage, 1); ?>%</div>
                                                    <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                                        <div class="bg-blue-600 h-2 rounded-full progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
                                                    </div>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?php echo $student['completed_hours']; ?>/<?php echo $student['required_hours']; ?> hours
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-center">
                                                        <?php
                                                        // Calculate current score from available evaluations
                                                        $current_score = 0;
                                                        
                                                        if ($grade_data['has_supervisor_evaluation']) {
                                                            $supervisor_contribution = ($grade_data['supervisor_grade'] / 100) * 70;
                                                            $current_score += $supervisor_contribution;
                                                        }
                                                        
                                                        if ($grade_data['has_coordinator_evaluation']) {
                                                            $coordinator_contribution = $grade_data['coordinator_grade'];
                                                            $current_score += $coordinator_contribution;
                                                        }
                                                        
                                                        // Determine display color based on risk level
                                                        $display_color = $risk_level === 'low' ? '#10B981' : 
                                                                        ($risk_level === 'medium' ? '#F59E0B' : 
                                                                        ($risk_level === 'high' ? '#F97316' : '#EF4444'));
                                                        
                                                        // If incomplete but has score, use orange color
                                                        if (!$grade_data['is_complete'] && $current_score > 0) {
                                                            $display_color = '#F59E0B'; // Orange for partial scores
                                                        }
                                                        ?>
                                                        
                                                        <div class="text-lg font-bold mb-1" style="color: <?php echo $display_color; ?>;">
                                                            <?php echo number_format($grade_data['is_complete'] ? $grade_data['final_percentage'] : $current_score, 2); ?>%
                                                        </div>
                                                        
                                                        <?php if ($grade_data['is_complete']): ?>
                                                            <div class="text-xs text-gray-600 mb-2">
                                                                <?php echo $grade_data['verbal_interpretation']; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-xs text-gray-500 mb-2">
                                                                Incomplete - <?php echo $current_score > 0 ? 'Partial Score' : 'No Evaluation'; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo getRiskBadgeClass($risk_level); ?>">
                                                            <?php echo getRiskLabel($risk_level); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <a href="?view_student=<?php echo $student['id']; ?>" 
                                                    class="text-indigo-600 hover:text-indigo-900">
                                                        View Details
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                                No students found matching your filters.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if (isset($total_pages) && $total_pages > 1): ?>
    <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
        <!-- Mobile pagination -->
        <div class="flex-1 flex justify-between sm:hidden">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&section=<?php echo urlencode($section_filter); ?>&status=<?php echo urlencode($status_filter); ?>&company=<?php echo urlencode($company_filter); ?>" 
                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Previous
                </a>
            <?php endif; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&section=<?php echo urlencode($section_filter); ?>&status=<?php echo urlencode($status_filter); ?>&company=<?php echo urlencode($company_filter); ?>" 
                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Next
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Desktop pagination - CENTERED -->
        <div class="hidden sm:flex sm:flex-col sm:items-center sm:space-y-3">
            <!-- Results info -->
            <div>
                <p class="text-sm text-gray-700">
                    Showing 
                    <span class="font-medium"><?php echo (($page - 1) * $records_per_page) + 1; ?></span>
                    to 
                    <span class="font-medium"><?php echo min($page * $records_per_page, $total_records); ?></span>
                    of 
                    <span class="font-medium"><?php echo $total_records; ?></span>
                    results
                </p>
            </div>
            
            <!-- Pagination controls - CENTERED -->
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <!-- Previous Button -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&section=<?php echo urlencode($section_filter); ?>&status=<?php echo urlencode($status_filter); ?>&company=<?php echo urlencode($company_filter); ?>" 
                           class="relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-left mr-1"></i>
                            Previous
                        </a>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&section=<?php echo urlencode($section_filter); ?>&status=<?php echo urlencode($status_filter); ?>&company=<?php echo urlencode($company_filter); ?>" 
                           class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i == $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 hover:bg-gray-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <!-- Next Button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&section=<?php echo urlencode($section_filter); ?>&status=<?php echo urlencode($status_filter); ?>&company=<?php echo urlencode($company_filter); ?>" 
                           class="relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            Next
                            <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
<?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Individual Student Detailed View -->
                    <?php if (isset($student_details) && isset($grade_data)): ?>
                        
                        <!-- Final Grade Breakdown Section -->
                        <div class="bg-white shadow-sm rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-graduation-cap text-green-600 mr-2"></i>
                                Final OJT Grade Breakdown
                            </h3>
                            
                            <?php
                            $current_score = 0;
                            $supervisor_contribution = 0;
                            $coordinator_contribution = 0;
                            
                            if ($grade_data['has_supervisor_evaluation']) {
                                $supervisor_contribution = ($grade_data['supervisor_grade'] / 100) * 70;
                                $current_score += $supervisor_contribution;
                            }
                            
                            if ($grade_data['has_coordinator_evaluation']) {
                                $coordinator_contribution = $grade_data['coordinator_grade'];
                                $current_score += $coordinator_contribution;
                            }
                            ?>
                            
                            <div class="<?php echo $grade_data['is_complete'] ? 'bg-gradient-to-r from-green-50 to-blue-50' : 'bg-gradient-to-r from-yellow-50 to-orange-50'; ?> rounded-lg p-6 mb-6">
                                <div class="text-center">
                                    <?php if ($grade_data['is_complete']): ?>
                                        <div class="text-5xl font-bold mb-2" style="color: <?php 
                                            echo $grade_data['risk_level'] === 'low' ? '#10B981' : 
                                                ($grade_data['risk_level'] === 'medium' ? '#F59E0B' : 
                                                ($grade_data['risk_level'] === 'high' ? '#F97316' : '#EF4444')); 
                                        ?>;">
                                            <?php echo number_format($grade_data['final_percentage'], 2); ?>%
                                        </div>
                                        <div class="text-xl font-semibold text-gray-700">
                                            <?php echo $grade_data['verbal_interpretation']; ?>
                                        </div>
                                        <div class="text-sm text-gray-600 mt-2">
                                            Final OJT Grade (70% Supervisor + 30% Coordinator)
                                        </div>
                                        <div class="mt-3">
                                            <span class="px-4 py-2 inline-flex text-sm font-semibold rounded-full <?php echo getRiskBadgeClass($grade_data['risk_level']); ?>">
                                                <?php echo getRiskLabel($grade_data['risk_level']); ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-3">
                                            <i class="fas fa-exclamation-triangle text-yellow-500 text-3xl"></i>
                                        </div>
                                        <div class="text-3xl font-bold text-gray-700 mb-2">
                                            <?php echo number_format($current_score, 2); ?>/100
                                        </div>
                                        <div class="text-lg font-semibold text-gray-600">
                                            Final Grade Not Yet Available
                                        </div>
                                        <div class="text-sm text-gray-600 mt-2">
                                            <?php if (!$grade_data['has_supervisor_evaluation']): ?>
                                                <div class="text-red-600">• Missing: Company Supervisor Evaluation (70%)</div>
                                            <?php endif; ?>
                                            <?php if (!$grade_data['has_coordinator_evaluation']): ?>
                                                <div class="text-red-600">• Missing: Coordinator Evaluation (30%)</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Component Breakdown -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Supervisor Evaluation (70%) -->
                                <div class="grade-breakdown-card <?php echo $grade_data['has_supervisor_evaluation'] ? 'border-green-500' : 'border-red-500'; ?> bg-white rounded-lg p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-semibold text-gray-700">
                                            <i class="fas fa-building <?php echo $grade_data['has_supervisor_evaluation'] ? 'text-green-600' : 'text-red-600'; ?> mr-2"></i>
                                            Company Supervisor Evaluation
                                        </h4>
                                        <span class="text-xs font-medium text-green-600 bg-green-100 px-2 py-1 rounded">70%</span>
                                    </div>
                                    
                                    <?php if ($grade_data['has_supervisor_evaluation']): ?>
                                        <div class="space-y-2">
                                            <div class="flex justify-between items-center">
                                                <span class="text-sm text-gray-600">Rating:</span>
                                                <span class="text-lg font-bold text-green-600">
                                                    <?php echo number_format($grade_data['supervisor_grade'], 2); ?>%
                                                </span>
                                            </div>
                                            <div class="flex justify-between items-center">
                                                <span class="text-sm text-gray-600">Interpretation:</span>
                                                <span class="text-sm font-medium text-gray-900">
                                                    <?php echo $grade_data['supervisor_interpretation']; ?>
                                                </span>
                                            </div>
                                            <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                                                <span class="text-sm text-gray-600">Contribution:</span>
                                                <span class="text-lg font-bold text-blue-600">
                                                    <?php echo number_format($supervisor_contribution, 2); ?>%
                                                </span>
                                            </div>
                                            <div class="pt-2 border-t border-gray-200">
                                                <div class="text-xs text-gray-500">
                                                    <div><strong>Evaluator:</strong> <?php echo htmlspecialchars($grade_data['supervisor_name']); ?></div>
                                                    <div><strong>Company:</strong> <?php echo htmlspecialchars($grade_data['company_name']); ?></div>
                                                    <div><strong>Date:</strong> <?php echo date('M j, Y', strtotime($grade_data['supervisor_date'])); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-6">
                                            <i class="fas fa-times-circle text-red-400 text-4xl mb-3"></i>
                                            <div class="text-sm font-medium text-red-600 mb-2">Not Yet Evaluated</div>
                                            <div class="text-xs text-gray-500">Waiting for company supervisor evaluation</div>
                                            <div class="mt-3 pt-3 border-t border-red-200">
                                                <div class="text-sm text-red-600 font-semibold">
                                                    0.00% contribution
                                                </div>
                                                <div class="text-xs text-gray-500">(0% out of 70%)</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Coordinator Evaluation (30%) -->
                                <div class="grade-breakdown-card <?php echo $grade_data['has_coordinator_evaluation'] ? 'border-blue-500' : 'border-red-500'; ?> bg-white rounded-lg p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-semibold text-gray-700">
                                            <i class="fas fa-user-tie <?php echo $grade_data['has_coordinator_evaluation'] ? 'text-blue-600' : 'text-red-600'; ?> mr-2"></i>
                                            Academic Coordinator Evaluation
                                        </h4>
                                        <span class="text-xs font-medium text-blue-600 bg-blue-100 px-2 py-1 rounded">30%</span>
                                    </div>
                                    
                                    <?php if ($grade_data['has_coordinator_evaluation']): ?>
                                        <div class="space-y-2">
                                            <div class="flex justify-between items-center">
                                                <span class="text-sm text-gray-600">Grade:</span>
                                                <span class="text-lg font-bold text-blue-600">
                                                    <?php echo number_format($grade_data['coordinator_grade'], 2); ?>%
                                                </span>
                                            </div>
                                            <div class="text-xs text-gray-600 space-y-1 p-2 bg-gray-50 rounded">
                                                <div class="flex justify-between">
                                                    <span>• Quality of Work (40%):</span>
                                                    <span class="font-medium"><?php echo number_format($grade_data['coordinator_quality'], 1); ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span>• Completeness (40%):</span>
                                                    <span class="font-medium"><?php echo number_format($grade_data['coordinator_completeness'], 1); ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span>• Urgency (10%):</span>
                                                    <span class="font-medium"><?php echo number_format($grade_data['coordinator_urgency'], 1); ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span>• Attendance (10%):</span>
                                                    <span class="font-medium"><?php echo number_format($grade_data['coordinator_attendance'], 1); ?></span>
                                                </div>
                                            </div>
                                            <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                                                <span class="text-sm text-gray-600">Contribution:</span>
                                                <span class="text-lg font-bold text-blue-600">
                                                    <?php echo number_format($coordinator_contribution, 2); ?>%
                                                </span>
                                            </div>
                                            <div class="pt-2 border-t border-gray-200">
                                                <div class="text-xs text-gray-500">
                                                    <div><strong>Evaluator:</strong> <?php echo htmlspecialchars($grade_data['coordinator_name']); ?></div>
                                                    <div><strong>Date:</strong> <?php echo date('M j, Y', strtotime($grade_data['coordinator_date'])); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-6">
                                            <i class="fas fa-times-circle text-red-400 text-4xl mb-3"></i>
                                            <div class="text-sm font-medium text-red-600 mb-2">Not Yet Evaluated</div>
                                            <div class="text-xs text-gray-500">Waiting for coordinator evaluation</div>
                                            <div class="mt-3 pt-3 border-t border-red-200">
                                                <div class="text-sm text-red-600 font-semibold">
                                                    0.00% contribution
                                                </div>
                                                <div class="text-xs text-gray-500">(0% out of 30%)</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Calculation Formula -->
                            <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h5 class="text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-calculator mr-2"></i>
                                    Calculation Formula
                                </h5>
                                <div class="text-sm text-gray-600 space-y-1">
                                    <div class="mb-2">Final Grade = (Supervisor Rating × 0.70) + Coordinator Grade</div>
                                    
                                    <?php if ($grade_data['is_complete']): ?>
                                        <div class="font-mono bg-white p-2 rounded border text-xs">
                                            = (<?php echo number_format($grade_data['supervisor_grade'], 2); ?>% × 0.70) + <?php echo number_format($grade_data['coordinator_grade'], 2); ?>%
                                        </div>
                                        <div class="font-mono bg-white p-2 rounded border text-xs">
                                            = <?php echo number_format($supervisor_contribution, 2); ?>% + <?php echo number_format($coordinator_contribution, 2); ?>%
                                        </div>
                                        <div class="font-mono bg-green-50 p-2 rounded border border-green-300 font-bold text-xs">
                                            = <?php echo number_format($grade_data['final_percentage'], 2); ?>%
                                        </div>
                                    <?php else: ?>
                                        <div class="font-mono bg-white p-2 rounded border text-xs">
                                            = (<?php echo $grade_data['has_supervisor_evaluation'] ? number_format($grade_data['supervisor_grade'], 2) : '0.00'; ?>% × 0.70) + <?php echo $grade_data['has_coordinator_evaluation'] ? number_format($grade_data['coordinator_grade'], 2) : '0.00'; ?>%
                                        </div>
                                        <div class="font-mono bg-white p-2 rounded border text-xs">
                                            = <?php echo number_format($supervisor_contribution, 2); ?>% + <?php echo number_format($coordinator_contribution, 2); ?>%
                                        </div>
                                        <div class="font-mono bg-yellow-50 p-2 rounded border border-yellow-300 font-bold text-xs">
                                            = <?php echo number_format($current_score, 2); ?>% (Incomplete)
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="bg-red-50 border border-red-200 rounded-md p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-red-800">
                                        Student not found or no data available.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Mobile sidebar
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebarBtn = document.getElementById('closeSidebar');
        
        function openSidebar() {
            if (sidebar && sidebarOverlay) {
                sidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebar() {
            if (sidebar && sidebarOverlay) {
                sidebar.classList.add('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openSidebar();
            });
        }

        if (closeSidebarBtn) {
            closeSidebarBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeSidebar();
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function(e) {
                e.preventDefault();
                closeSidebar();
            });
        }

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                if (sidebar && sidebarOverlay) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebarOverlay.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebarOverlay && !sidebarOverlay.classList.contains('hidden')) {
                closeSidebar();
            }
        });

        // Profile dropdown
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');
        
        if (profileBtn && profileDropdown) {
            profileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                profileDropdown.classList.toggle('hidden');
            });

            document.addEventListener('click', function(e) {
                if (!profileBtn.contains(e.target) && !profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                }
            });
        }

        // Filter functionality
        // ===========================================
// FILTER FUNCTIONALITY
// ===========================================

function applyFilters() {
    const search = searchInput?.value || '';
    const section = document.getElementById('sectionFilter')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const company = document.getElementById('companyFilter')?.value || '';
    
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (section) params.append('section', section);
    if (status) params.append('status', status);
    if (company) params.append('company', company);
    
    window.location.href = `${window.location.pathname}?${params.toString()}`;
}

// Search input Enter key
if (searchInput) {
    searchInput.addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
}

// Filter dropdowns
const filters = ['sectionFilter', 'statusFilter', 'companyFilter'];
filters.forEach(filterId => {
    const filterElement = document.getElementById(filterId);
    if (filterElement) {
        filterElement.addEventListener('change', applyFilters);
    }
});

// Clear filters
if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (searchInput) searchInput.value = '';
        filters.forEach(filterId => {
            const element = document.getElementById(filterId);
            if (element) element.value = '';
        });
        if (filterForm) {
            filterForm.submit();
        }
    });
}

        const clearFiltersBtn = document.getElementById('clearFilters');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'StudentPerformance.php';
            });
        }

        // Progress bar animations
        setTimeout(function() {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(function(bar) {
                const width = bar.style.width;
                if (width) {
                    bar.style.width = '0%';
                    setTimeout(function() {
                        bar.style.width = width;
                    }, 100);
                }
            });
        }, 500);
    });

    function confirmLogout() {
        return confirm('Are you sure you want to logout?');
    }
    </script>
    </body>
    </html>