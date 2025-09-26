<?php
include('connect.php');
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
include_once('notification_functions.php');
$unread_count = getUnreadNotificationCount($conn, $user_id);

// Initialize variables
$student = null;
$full_name = '';
$first_name = '';
$initials = '';
$profile_picture = '';
$evaluations = [];
$deployment_info = null;
$supervisor_info = null;
$evaluation_stats = [
    'total_evaluations' => 0,
    'avg_overall' => 0,
    'latest_overall' => 0,
    'avg_teamwork' => 0,
    'avg_communication' => 0,
    'avg_productivity' => 0,
    'avg_initiative' => 0,
    'avg_judgement' => 0,
    'avg_dependability' => 0,
    'avg_attitude' => 0,
    'avg_professionalism' => 0,
    'trend' => 'stable'
];

try {
    // Fetch student data
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        $first_name = $student['first_name'];
        $full_name = $student['first_name'];
        if (!empty($student['middle_name'])) {
            $full_name .= ' ' . $student['middle_name'];
        }
        $full_name .= ' ' . $student['last_name'];
        $profile_picture = $student['profile_picture'];
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    } else {
        header("Location: login.php");
        exit();
    }
    $stmt->close();

    // Get current deployment information
    $deployment_stmt = $conn->prepare("
        SELECT 
            sd.*,
            cs.full_name as supervisor_name,
            cs.company_name,
            cs.position as supervisor_position,
            cs.email as supervisor_email,
            cs.phone_number as supervisor_phone
        FROM student_deployments sd
        JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
        WHERE sd.student_id = ? AND sd.status = 'Active'
        ORDER BY sd.deployment_id DESC
        LIMIT 1
    ");
    
    if ($deployment_stmt) {
        $deployment_stmt->bind_param("i", $user_id);
        $deployment_stmt->execute();
        $deployment_result = $deployment_stmt->get_result();
        
        if ($deployment_result->num_rows > 0) {
            $deployment_info = $deployment_result->fetch_assoc();
            $supervisor_info = [
                'name' => $deployment_info['supervisor_name'],
                'company' => $deployment_info['company_name'],
                'position' => $deployment_info['supervisor_position'],
                'email' => $deployment_info['supervisor_email'],
                'phone' => $deployment_info['supervisor_phone']
            ];
        }
        $deployment_stmt->close();
    }

    // Fetch all evaluations for this student
    if ($deployment_info) {
        $eval_stmt = $conn->prepare("
            SELECT 
                se.*,
                cs.full_name as supervisor_name,
                cs.company_name,
                DATE_FORMAT(se.created_at, '%W, %M %d, %Y') as formatted_date,
                DATE_FORMAT(se.created_at, '%h:%i %p') as formatted_time
            FROM student_evaluations se
            JOIN company_supervisors cs ON se.supervisor_id = cs.supervisor_id
            WHERE se.student_id = ?
            ORDER BY se.created_at DESC
        ");
        
        if ($eval_stmt) {
            $eval_stmt->bind_param("i", $user_id);
            $eval_stmt->execute();
            $eval_result = $eval_stmt->get_result();
            $evaluations = $eval_result->fetch_all(MYSQLI_ASSOC);
            $eval_stmt->close();
            
            // Calculate statistics
            if (!empty($evaluations)) {
                $evaluation_stats['total_evaluations'] = count($evaluations);
                $evaluation_stats['latest_overall'] = $evaluations[0]['equivalent_rating'];
                
                // Calculate category averages
                $total_scores = array_column($evaluations, 'total_score');
                $equivalent_ratings = array_column($evaluations, 'equivalent_rating');
                
                $evaluation_stats['avg_overall'] = round(array_sum($equivalent_ratings) / count($equivalent_ratings), 1);
                
                // Calculate category averages based on the actual database structure
                foreach ($evaluations as $eval) {
                    // Teamwork (criteria 1-5)
                    $teamwork_score = ($eval['teamwork_1'] + $eval['teamwork_2'] + $eval['teamwork_3'] + 
                                     $eval['teamwork_4'] + $eval['teamwork_5']) / 5;
                    $evaluation_stats['teamwork_scores'][] = $teamwork_score;
                    
                    // Communication (criteria 6-9)
                    $communication_score = ($eval['communication_6'] + $eval['communication_7'] + 
                                          $eval['communication_8'] + $eval['communication_9']) / 4;
                    $evaluation_stats['communication_scores'][] = $communication_score;
                    
                    // Productivity (criteria 13-17)
                    $productivity_score = ($eval['productivity_13'] + $eval['productivity_14'] + 
                                         $eval['productivity_15'] + $eval['productivity_16'] + 
                                         $eval['productivity_17']) / 5;
                    $evaluation_stats['productivity_scores'][] = $productivity_score;
                    
                    // Initiative (criteria 18-23)
                    $initiative_score = ($eval['initiative_18'] + $eval['initiative_19'] + 
                                       $eval['initiative_20'] + $eval['initiative_21'] + 
                                       $eval['initiative_22'] + $eval['initiative_23']) / 6;
                    $evaluation_stats['initiative_scores'][] = $initiative_score;
                    
                    // Judgement (criteria 24-26)
                    $judgement_score = ($eval['judgement_24'] + $eval['judgement_25'] + 
                                      $eval['judgement_26']) / 3;
                    $evaluation_stats['judgement_scores'][] = $judgement_score;
                    
                    // Dependability (criteria 27-31)
                    $dependability_score = ($eval['dependability_27'] + $eval['dependability_28'] + 
                                          $eval['dependability_29'] + $eval['dependability_30'] + 
                                          $eval['dependability_31']) / 5;
                    $evaluation_stats['dependability_scores'][] = $dependability_score;
                    
                    // Attitude (criteria 32-36)
                    $attitude_score = ($eval['attitude_32'] + $eval['attitude_33'] + 
                                     $eval['attitude_34'] + $eval['attitude_35'] + 
                                     $eval['attitude_36']) / 5;
                    $evaluation_stats['attitude_scores'][] = $attitude_score;
                    
                    // Professionalism (criteria 37-40)
                    $professionalism_score = ($eval['professionalism_37'] + $eval['professionalism_38'] + 
                                            $eval['professionalism_39'] + $eval['professionalism_40']) / 4;
                    $evaluation_stats['professionalism_scores'][] = $professionalism_score;
                }
                
                // Calculate averages for each category
                if (!empty($evaluation_stats['teamwork_scores'])) {
                    $evaluation_stats['avg_teamwork'] = round(array_sum($evaluation_stats['teamwork_scores']) / count($evaluation_stats['teamwork_scores']), 1);
                }
                if (!empty($evaluation_stats['communication_scores'])) {
                    $evaluation_stats['avg_communication'] = round(array_sum($evaluation_stats['communication_scores']) / count($evaluation_stats['communication_scores']), 1);
                }
                if (!empty($evaluation_stats['productivity_scores'])) {
                    $evaluation_stats['avg_productivity'] = round(array_sum($evaluation_stats['productivity_scores']) / count($evaluation_stats['productivity_scores']), 1);
                }
                if (!empty($evaluation_stats['initiative_scores'])) {
                    $evaluation_stats['avg_initiative'] = round(array_sum($evaluation_stats['initiative_scores']) / count($evaluation_stats['initiative_scores']), 1);
                }
                if (!empty($evaluation_stats['judgement_scores'])) {
                    $evaluation_stats['avg_judgement'] = round(array_sum($evaluation_stats['judgement_scores']) / count($evaluation_stats['judgement_scores']), 1);
                }
                if (!empty($evaluation_stats['dependability_scores'])) {
                    $evaluation_stats['avg_dependability'] = round(array_sum($evaluation_stats['dependability_scores']) / count($evaluation_stats['dependability_scores']), 1);
                }
                if (!empty($evaluation_stats['attitude_scores'])) {
                    $evaluation_stats['avg_attitude'] = round(array_sum($evaluation_stats['attitude_scores']) / count($evaluation_stats['attitude_scores']), 1);
                }
                if (!empty($evaluation_stats['professionalism_scores'])) {
                    $evaluation_stats['avg_professionalism'] = round(array_sum($evaluation_stats['professionalism_scores']) / count($evaluation_stats['professionalism_scores']), 1);
                }
                
                // Calculate trend (comparing last 2 evaluations)
                if (count($evaluations) >= 2) {
                    $latest_rating = $evaluations[0]['equivalent_rating'];
                    $previous_rating = $evaluations[1]['equivalent_rating'];
                    
                    if ($latest_rating > $previous_rating + 0.2) {
                        $evaluation_stats['trend'] = 'improving';
                    } elseif ($latest_rating < $previous_rating - 0.2) {
                        $evaluation_stats['trend'] = 'declining';
                    }
                }
            }
        }
    }

} catch (Exception $e) {
    error_log("Error in studentEvaluation.php: " . $e->getMessage());
    $error_message = "An error occurred while loading evaluations.";
}

// Helper function to get rating text and color based on equivalent rating
function getRatingInfo($rating) {
    if ($rating >= 4.5) {
        return ['text' => 'Excellent', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'];
    } elseif ($rating >= 3.5) {
        return ['text' => 'Good', 'color' => 'text-green-600', 'bg' => 'bg-green-100'];
    } elseif ($rating >= 2.5) {
        return ['text' => 'Average', 'color' => 'text-gray-600', 'bg' => 'bg-gray-100'];
    } elseif ($rating >= 1.5) {
        return ['text' => 'Below Average', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100'];
    } else {
        return ['text' => 'Poor', 'color' => 'text-red-600', 'bg' => 'bg-red-100'];
    }
}

// Helper function to calculate category score
function calculateCategoryScore($evaluation, $criteria) {
    $total = 0;
    $count = count($criteria);
    foreach ($criteria as $criterion) {
        $total += $evaluation[$criterion] ?? 0;
    }
    return $count > 0 ? round($total / $count, 1) : 0;
}

// Define criteria groups
$criteria_groups = [
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnTheJob Tracker - My Evaluations</title>
    <link rel="icon" type="image/png" href="reqsample/bulsu12.png">
    <link rel="shortcut icon" type="image/png" href="reqsample/bulsu12.png">
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

        .trend-arrow {
            transition: all 0.3s ease;
        }

        .chart-container {
            position: relative;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .evaluation-card {
            transition: all 0.3s ease;
        }

        .evaluation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .rating-circle {
            transition: all 0.3s ease;
        }

        .rating-circle:hover {
            transform: scale(1.05);
        }

        .gradient-bg-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .gradient-bg-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .gradient-bg-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .gradient-bg-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #555;
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
            <a href="studentEvaluation.php" class="nav-item flex items-center px-3 py-2 text-sm font-medium text-white bg-bulsu-gold bg-opacity-20 border border-bulsu-gold border-opacity-30 rounded-md">
                <i class="fas fa-star mr-3  text-bulsu-gold"></i>
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">My Evaluations</h1>
                    <p class="text-sm sm:text-base text-gray-500 hidden sm:block">View performance evaluations from your supervisor</p>
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
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($student['student_id'] ?? ''); ?> • <?php echo htmlspecialchars($student['program'] ?? ''); ?></p>
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
            <!-- Welcome Section -->
            <div class="mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Welcome, <?php echo htmlspecialchars($first_name); ?>!</h1>
            </div>

            <?php if (!$deployment_info): ?>
                <!-- No Deployment Alert -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-600 mt-1 mr-3"></i>
                        <div>
                            <h3 class="font-medium text-blue-800">No Active Deployment</h3>
                            <p class="text-sm text-blue-700 mt-1">
                                You are not currently deployed to any company. Evaluations will appear here once you are assigned to a supervisor.
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Supervisor Information -->
            <div class="mb-6 sm:mb-8 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon text-white rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <i class="fas fa-user-tie text-2xl mr-3"></i>
                        <h3 class="text-xl font-bold">Your Supervisor</h3>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="flex items-center">
                            <i class="fas fa-user mr-2"></i>
                            <span class="text-sm"><?php echo htmlspecialchars($supervisor_info['name']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-building mr-2"></i>
                            <span class="text-sm"><?php echo htmlspecialchars($supervisor_info['company']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-briefcase mr-2"></i>
                            <span class="text-sm"><?php echo htmlspecialchars($supervisor_info['position']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-calendar mr-2"></i>
                            <span class="text-sm">
                                <?php echo date('M j, Y', strtotime($deployment_info['start_date'])); ?> - 
                                <?php echo date('M j, Y', strtotime($deployment_info['end_date'])); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($evaluations)): ?>
                    <!-- Statistics Grid - Modified for single evaluation -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                        <?php if (count($evaluations) === 1): ?>
                            <!-- Single Evaluation Layout -->
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-primary w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-star text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $evaluation_stats['avg_overall']; ?>/5</div>
                                <div class="text-sm text-gray-600">
                                    Your Overall Rating
                                    <?php 
                                    $rating_info = getRatingInfo($evaluation_stats['avg_overall']);
                                    echo '<div class="text-xs mt-1 px-2 py-1 rounded-full inline-block ' . $rating_info['bg'] . ' ' . $rating_info['color'] . '">' . $rating_info['text'] . '</div>';
                                    ?>
                                </div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-success w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-calendar text-white text-xl"></i>
                                </div>
                                <div class="text-lg sm:text-xl font-bold text-green-600 mb-1">
                                    <?php echo date('M j, Y', strtotime($evaluations[0]['created_at'])); ?>
                                </div>
                                <div class="text-sm text-gray-600">Evaluation Date</div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-warning w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-award text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-orange-600 mb-1"><?php echo $evaluation_stats['avg_teamwork']; ?>/5</div>
                                <div class="text-sm text-gray-600">Teamwork Score</div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-info w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-comments text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $evaluation_stats['avg_communication']; ?>/5</div>
                                <div class="text-sm text-gray-600">Communication Score</div>
                            </div>
                        <?php else: ?>
                            <!-- Multiple Evaluations Layout -->
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-primary w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-clipboard-list text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1"><?php echo $evaluation_stats['total_evaluations']; ?></div>
                                <div class="text-sm text-gray-600">Total Evaluations</div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-success w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-star text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-green-600 mb-1"><?php echo $evaluation_stats['avg_overall']; ?>/5</div>
                                <div class="text-sm text-gray-600">
                                    Average Overall Rating
                                    <div class="flex items-center mt-1 text-xs">
                                        <?php
                                        $trend_color = $evaluation_stats['trend'] === 'improving' ? 'text-green-600' : 
                                                      ($evaluation_stats['trend'] === 'declining' ? 'text-red-600' : 'text-gray-600');
                                        $trend_icon = $evaluation_stats['trend'] === 'improving' ? 'fa-arrow-up' : 
                                                     ($evaluation_stats['trend'] === 'declining' ? 'fa-arrow-down' : 'fa-minus');
                                        ?>
                                        <i class="fas <?php echo $trend_icon; ?> <?php echo $trend_color; ?> mr-1"></i>
                                        <span class="<?php echo $trend_color; ?>"><?php echo ucfirst($evaluation_stats['trend']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-warning w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-award text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-orange-600 mb-1"><?php echo $evaluation_stats['avg_teamwork']; ?>/5</div>
                                <div class="text-sm text-gray-600">Average Teamwork</div>
                            </div>
                            
                            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                                <div class="gradient-bg-info w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                    <i class="fas fa-comments text-white text-xl"></i>
                                </div>
                                <div class="text-2xl sm:text-3xl font-bold text-blue-600 mb-1"><?php echo $evaluation_stats['avg_communication']; ?>/5</div>
                                <div class="text-sm text-gray-600">Average Communication</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Additional Statistics Grid - Only show for multiple evaluations -->
                    <?php if (count($evaluations) > 1): ?>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                            <div class="bg-purple-500 w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-rocket text-white text-xl"></i>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-purple-600 mb-1"><?php echo $evaluation_stats['avg_initiative']; ?>/5</div>
                            <div class="text-sm text-gray-600">Average Initiative</div>
                        </div>
                        
                        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                            <div class="bg-indigo-500 w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-chart-line text-white text-xl"></i>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-indigo-600 mb-1"><?php echo $evaluation_stats['avg_productivity']; ?>/5</div>
                            <div class="text-sm text-gray-600">Average Productivity</div>
                        </div>
                        
                        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                            <div class="bg-pink-500 w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-handshake text-white text-xl"></i>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-pink-600 mb-1"><?php echo $evaluation_stats['avg_professionalism']; ?>/5</div>
                            <div class="text-sm text-gray-600">Average Professionalism</div>
                        </div>
                        
                        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-gray-200 evaluation-card">
                            <div class="bg-teal-500 w-12 h-12 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-heart text-white text-xl"></i>
                            </div>
                            <div class="text-2xl sm:text-3xl font-bold text-teal-600 mb-1"><?php echo $evaluation_stats['avg_attitude']; ?>/5</div>
                            <div class="text-sm text-gray-600">Average Attitude</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Performance Chart - Only show for multiple evaluations -->
                    <?php if (count($evaluations) >= 2): ?>
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6 sm:mb-8">
                            <div class="text-center mb-6">
                                <h3 class="text-lg font-bold text-gray-900 mb-2">Performance Trend</h3>
                                <p class="text-gray-600">Your evaluation scores over time</p>
                            </div>
                            <div class="chart-container">
                                <canvas id="performanceChart" class="max-h-96"></canvas>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Evaluations Section -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
<div class="bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon p-4 sm:p-6 text-white rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-star mr-3"></i>
                                <h2 class="text-lg font-bold">
                                    <?php echo count($evaluations) === 1 ? 'Your Evaluation' : 'Evaluation History'; ?>
                                </h2>
                            </div>
                        </div>

                        <div class="divide-y divide-gray-200">
                            <?php foreach ($evaluations as $index => $evaluation): ?>
                                <div class="evaluation-card p-4 sm:p-6">
                                    <!-- Evaluation Header -->
                                    <div class="mb-4 pb-4 border-b border-gray-100">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-3">
                                            <div class="flex items-center text-gray-900 font-medium">
                                                <i class="fas fa-calendar text-blue-600 mr-2"></i>
                                                <?php echo $evaluation['formatted_date']; ?> at <?php echo $evaluation['formatted_time']; ?>
                                            </div>
                                            <?php if (count($evaluations) > 1): ?>
                                            <div class="flex items-center text-sm text-gray-600 mt-2 sm:mt-0">
                                                <i class="fas fa-clock mr-1"></i>Evaluation #<?php echo ($index + 1); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center text-sm text-gray-600 mb-2">
                                            <i class="fas fa-user-tie mr-2"></i>
                                            <span>Evaluated by: <?php echo htmlspecialchars($evaluation['supervisor_name']); ?></span>
                                            <span class="mx-2">•</span>
                                            <span><?php echo htmlspecialchars($evaluation['company_name']); ?></span>
                                        </div>
                                        
                                        <!-- Overall Score Display -->
                                        <div class="flex items-center justify-between bg-gradient-to-r from-blue-50 to-purple-50 p-4 rounded-lg">
                                            <div>
                                                <h4 class="text-lg font-semibold text-gray-900">Overall Rating</h4>
                                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($evaluation['verbal_interpretation']); ?></p>
                                            </div>
                                            <div class="text-center">
                                                <div class="text-3xl font-bold text-blue-600"><?php echo number_format($evaluation['equivalent_rating'], 2); ?></div>
                                                <div class="text-sm text-gray-600">out of 5.00</div>
                                                <div class="text-xs text-gray-500 mt-1"><?php echo $evaluation['total_score']; ?>/200 points</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Category Breakdown -->
                                    <div class="mb-6">
                                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Category Breakdown</h4>
                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <?php foreach ($criteria_groups as $category => $criteria): ?>
                                                <?php $category_score = calculateCategoryScore($evaluation, $criteria); ?>
                                                <?php $rating_info = getRatingInfo($category_score); ?>
                                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                    <div class="flex-1">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo $category; ?></div>
                                                        <div class="text-xs text-gray-600 mt-1"><?php echo $rating_info['text']; ?></div>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <?php echo count($criteria); ?> criteria evaluated
                                                        </div>
                                                    </div>
                                                    <div class="rating-circle w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-sm <?php echo str_replace('text-', 'bg-', $rating_info['color']); ?>">
                                                        <?php echo number_format($category_score, 1); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Training Period Information -->
                                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-r-lg mb-4">
                                        <div class="flex items-start">
                                            <i class="fas fa-calendar-alt text-blue-600 mr-2 mt-1"></i>
                                            <div>
                                                <h4 class="text-sm font-medium text-blue-800 mb-2">Training Period Details</h4>
                                                <div class="text-sm text-blue-700 space-y-1">
                                                    <p><strong>Period:</strong> <?php echo date('M j, Y', strtotime($evaluation['training_period_start'])); ?> to <?php echo date('M j, Y', strtotime($evaluation['training_period_end'])); ?></p>
                                                    <p><strong>Total Hours Rendered:</strong> <?php echo $evaluation['total_hours_rendered']; ?> hours</p>
                                                    <p><strong>Cooperating Agency:</strong> <?php echo htmlspecialchars($evaluation['cooperating_agency']); ?></p>
                                                    <?php if (!empty($evaluation['agency_address'])): ?>
                                                        <p><strong>Agency Address:</strong> <?php echo htmlspecialchars($evaluation['agency_address']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Comments Section -->
                                    <?php if (!empty($evaluation['remarks_comments_suggestions'])): ?>
                                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-r-lg">
                                            <div class="flex items-start">
                                                <i class="fas fa-comment-alt text-yellow-600 mr-2 mt-1"></i>
                                                <div>
                                                    <h4 class="text-sm font-medium text-yellow-800 mb-2">Supervisor Comments & Suggestions</h4>
                                                    <p class="text-sm text-yellow-700"><?php echo nl2br(htmlspecialchars($evaluation['remarks_comments_suggestions'])); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- No Evaluations Message -->
                    <div class="text-center py-12">
                        <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-star text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Evaluations Yet</h3>
                        <p class="text-gray-600 mb-4">Your supervisor hasn't submitted any evaluations for you yet.</p>
                        <div class="text-sm text-gray-500">
                            <p>Evaluations typically occur:</p>
                            <ul class="mt-2 space-y-1">
                                <li>• Weekly or bi-weekly during your internship</li>
                                <li>• At the end of major projects or milestones</li>
                                <li>• As part of your final assessment</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Mobile sidebar functionality
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const closeSidebar = document.getElementById('closeSidebar');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        }

        function closeSidebarFunc() {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        }

        mobileMenuBtn.addEventListener('click', openSidebar);
        closeSidebar.addEventListener('click', closeSidebarFunc);
        sidebarOverlay.addEventListener('click', closeSidebarFunc);

        // Profile dropdown functionality
        const profileBtn = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.add('hidden');
            }
        });

        // Performance Chart - Only for multiple evaluations
        <?php if (!empty($evaluations) && count($evaluations) >= 2): ?>
        const ctx = document.getElementById('performanceChart').getContext('2d');
        
        // Prepare data for chart
        const evaluationData = <?php echo json_encode(array_reverse($evaluations)); ?>;
        const labels = evaluationData.map((eval, index) => {
            const date = new Date(eval.created_at);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        
        // Calculate category averages for each evaluation
        const overallData = evaluationData.map(eval => parseFloat(eval.equivalent_rating));
        const teamworkData = evaluationData.map(eval => {
            return (eval.teamwork_1 + eval.teamwork_2 + eval.teamwork_3 + eval.teamwork_4 + eval.teamwork_5) / 5;
        });
        const communicationData = evaluationData.map(eval => {
            return (eval.communication_6 + eval.communication_7 + eval.communication_8 + eval.communication_9) / 4;
        });
        const productivityData = evaluationData.map(eval => {
            return (eval.productivity_13 + eval.productivity_14 + eval.productivity_15 + eval.productivity_16 + eval.productivity_17) / 5;
        });

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Overall Rating',
                        data: overallData,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: false
                    },
                    {
                        label: 'Teamwork',
                        data: teamworkData,
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: false
                    },
                    {
                        label: 'Communication',
                        data: communicationData,
                        borderColor: 'rgb(245, 158, 11)',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        tension: 0.4,
                        fill: false
                    },
                    {
                        label: 'Productivity',
                        data: productivityData,
                        borderColor: 'rgb(168, 85, 247)',
                        backgroundColor: 'rgba(168, 85, 247, 0.1)',
                        tension: 0.4,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Evaluation Date'
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Rating (1-5)'
                        },
                        min: 0,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
        <?php endif; ?>

        // Add smooth scrolling to evaluation cards
        document.addEventListener('DOMContentLoaded', function() {
            const evaluationCards = document.querySelectorAll('.evaluation-card');
            
            evaluationCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.1)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '';
                });
            });
        });

        // Auto-hide notifications after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);

        // Add loading state for better UX
        window.addEventListener('beforeunload', function() {
            document.body.style.opacity = '0.5';
            document.body.style.pointerEvents = 'none';
        });
    </script>
</body>
</html>