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

// Initialize variables
$search = '';
$department_filter = '';
$section_filter = '';
$status_filter = '';
$company_filter = '';
$total_pages = 1; // Initialize to prevent undefined variable error

// Initialize $where_clause early to prevent undefined variable error
$where_clause = "s.verified = 1 AND s.status != 'Blocked' AND sd.status IN ('Active', 'Completed') AND (sd.ojt_status IS NULL OR sd.ojt_status IN ('Active', 'Completed'))";

// Only get filter values if we're not viewing a specific student
$view_student = isset($_GET['view_student']) ? $_GET['view_student'] : null;

if (!$view_student) {
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $department_filter = isset($_GET['department']) ? mysqli_real_escape_string($conn, $_GET['department']) : '';
    $section_filter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';
    $status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : ''; // Now used for risk levels
    $company_filter = isset($_GET['company']) ? mysqli_real_escape_string($conn, $_GET['company']) : '';
}

// Use status_filter as risk_filter for backward compatibility
$risk_filter = $status_filter;

// PAGINATION SETTINGS
$records_per_page = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Updated getStudentDataForPrediction function with proper completion date handling
function getStudentDataForPrediction($student_id, $conn) {
    $predictionData = [];
    
    try {
        // Get basic student and deployment info
        $student_query = "
            SELECT s.*, 
                  sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
                  sd.required_hours, sd.completed_hours, sd.status as deployment_status,
                  sd.company_name, sd.supervisor_name, sd.supervisor_email, sd.ojt_status,
                  cs.work_schedule_start, cs.work_schedule_end, cs.work_days
            FROM students s
            LEFT JOIN student_deployments sd ON s.id = sd.student_id
            LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
            WHERE s.id = ? AND s.verified = 1
        ";
        $stmt = $conn->prepare($student_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $student_basic = $stmt->get_result()->fetch_assoc();
        
        if (!$student_basic) {
            return null;
        }
        
        $predictionData = $student_basic;
        
        // Get attendance statistics with proper completion date handling
        if ($student_basic['deployment_id']) {
            $work_schedule_start = $student_basic['work_schedule_start'];
            $work_schedule_end = $student_basic['work_schedule_end'];
            $work_days_string = $student_basic['work_days'];
            
            // Parse work days
            $work_days_array = array_map('trim', explode(',', strtolower($work_days_string)));
            
            // Calculate attendance statistics properly
            $start_date = new DateTime($student_basic['start_date']);
            $end_date = new DateTime($student_basic['end_date']);
            $today_dt = new DateTime();
            
            // CRITICAL: Determine actual completion date
            $limit_date = null;
            $is_completed_by_hours = false;
            
            // Check if OJT is completed
            if ($student_basic['ojt_status'] === 'Completed' || $student_basic['deployment_status'] === 'Completed') {
                // Check if completed by hours
                if ($student_basic['required_hours'] > 0 && $student_basic['completed_hours'] >= $student_basic['required_hours']) {
                    // Find the exact date when required hours were completed
                    $completion_stmt = $conn->prepare("
                        SELECT date, 
                               @running_total := @running_total + COALESCE(total_hours, 0) as running_total
                        FROM student_attendance sa
                        CROSS JOIN (SELECT @running_total := 0) r
                        WHERE sa.student_id = ? AND sa.deployment_id = ?
                        ORDER BY sa.date ASC
                    ");
                    $completion_stmt->bind_param("ii", $student_id, $student_basic['deployment_id']);
                    $completion_stmt->execute();
                    $completion_result = $completion_stmt->get_result();
                    
                    $completion_date = null;
                    $required_hours = (float)$student_basic['required_hours'];
                    
                    while ($row = $completion_result->fetch_assoc()) {
                        if ((float)$row['running_total'] >= $required_hours) {
                            $completion_date = $row['date'];
                            $is_completed_by_hours = true;
                            break;
                        }
                    }
                    $completion_stmt->close();
                    
                    if ($completion_date) {
                        $limit_date = new DateTime($completion_date);
                    } else {
                        // Fallback: use last attendance date if no completion date found
                        $last_stmt = $conn->prepare("SELECT MAX(date) as last_date FROM student_attendance WHERE student_id = ? AND deployment_id = ?");
                        $last_stmt->bind_param("ii", $student_id, $student_basic['deployment_id']);
                        $last_stmt->execute();
                        $last_result = $last_stmt->get_result();
                        $last_data = $last_result->fetch_assoc();
                        $last_stmt->close();
                        
                        if ($last_data['last_date']) {
                            $limit_date = new DateTime($last_data['last_date']);
                        } else {
                            $limit_date = ($end_date < $today_dt) ? $end_date : $today_dt;
                        }
                    }
                } else {
                    // Completed by end date
                    $limit_date = $end_date;
                }
            } else {
                // Still active - don't go beyond today
                $limit_date = ($end_date < $today_dt) ? $end_date : $today_dt;
            }
            
            $attendance_stats = [
                'total_days' => 0,
                'present_days' => 0,
                'absent_days' => 0,
                'late_days' => 0,
                'avg_daily_hours' => 0
            ];
            
            $total_hours = 0;
            $records_count = 0;
            
            // Get all attendance records
            $attendance_query = "
                SELECT date, time_in, time_out, total_hours, status
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
                ORDER BY date ASC
            ";
            $stmt = $conn->prepare($attendance_query);
            $stmt->bind_param("ii", $student_id, $student_basic['deployment_id']);
            $stmt->execute();
            $attendance_records = $stmt->get_result();
            
            // Index attendance records by date
            $attendance_by_date = [];
            while ($record = $attendance_records->fetch_assoc()) {
                $attendance_by_date[$record['date']] = $record;
                $total_hours += (float)$record['total_hours'];
                $records_count++;
            }
            
            // Calculate work days and attendance up to completion date
            $current_date = clone $start_date;
            while ($current_date <= $limit_date) {
                $day_name = strtolower($current_date->format('l'));
                $date_string = $current_date->format('Y-m-d');
                
                // Check if this day is a working day
                if (in_array($day_name, $work_days_array)) {
                    $attendance_stats['total_days']++;
                    
                    if (isset($attendance_by_date[$date_string])) {
                        $record = $attendance_by_date[$date_string];
                        $attendance_stats['present_days']++;
                        
                        // Check if late
                        if ($record['time_in'] && $work_schedule_start) {
                            $time_in = new DateTime($date_string . ' ' . $record['time_in']);
                            $scheduled_start = new DateTime($date_string . ' ' . $work_schedule_start);
                            $scheduled_start->modify('+15 minutes'); // Grace period
                            
                            if ($time_in > $scheduled_start) {
                                $attendance_stats['late_days']++;
                            }
                        }
                    } else {
                        // Only count as absent if the date is in the past and not after completion
                        if ($current_date <= new DateTime()) {
                            $attendance_stats['absent_days']++;
                        }
                    }
                }
                
                $current_date->modify('+1 day');
            }
            
            $attendance_stats['avg_daily_hours'] = $records_count > 0 ? $total_hours / $records_count : 0;
            $predictionData['attendance_stats'] = $attendance_stats;
            $predictionData['attendance_calculation_end_date'] = $limit_date->format('Y-m-d');
            $predictionData['completed_by_hours'] = $is_completed_by_hours;
        }
        
        // Keep other parts (task stats, evaluation, self-assessment) as they are
        $task_query = "
            SELECT 
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tasks,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_tasks,
                SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue_tasks
            FROM tasks
            WHERE student_id = ?
        ";
        $stmt = $conn->prepare($task_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $task_stats = $stmt->get_result()->fetch_assoc();
        $predictionData['task_stats'] = $task_stats;
        
        $evaluation_query = "
            SELECT evaluation_id, student_id, supervisor_id, deployment_id,
                  equivalent_rating, total_score, verbal_interpretation,
                  remarks_comments_suggestions,
                  evaluation_period_start, evaluation_period_end, created_at, updated_at
            FROM student_evaluations 
            WHERE student_id = ?
            ORDER BY created_at DESC 
            LIMIT 1
        ";
        $stmt = $conn->prepare($evaluation_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $evaluation_result = $stmt->get_result();
        $predictionData['evaluation_data'] = $evaluation_result->num_rows > 0 ? $evaluation_result->fetch_assoc() : null;
        
        $self_assessment_query = "
            SELECT academic_performance, stress_level, workplace_satisfaction, 
                  learning_progress, time_management, skill_development, 
                  mentor_support, work_life_balance, confidence_level,
                  challenges_faced, support_needed, additional_comments, created_at
            FROM student_self_assessments 
            WHERE student_id = ?
            ORDER BY created_at DESC 
            LIMIT 1
        ";
        $stmt = $conn->prepare($self_assessment_query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $self_assessment_result = $stmt->get_result();
        $predictionData['self_assessment'] = $self_assessment_result->num_rows > 0 ? $self_assessment_result->fetch_assoc() : null;
        
        return $predictionData;
        
    } catch (Exception $e) {
        error_log("Error getting student data for prediction: " . $e->getMessage());
        return null;
    }
}

// OJT Pass Prediction Function - CONSISTENT VERSION
function predictOJTSuccess($studentData, $conn) {
    $prediction = [
        'pass_probability' => 0,
        'risk_level' => 'unknown',
        'key_factors' => [],
        'recommendations' => [],
        'score' => 0
    ];
    
    $score = 0;
    $maxScore = 100;
    $factors = [];
    $recommendations = [];
    
    // 1. Attendance Analysis (Weight: 25%)
    $attendanceScore = 0;
    if (!empty($studentData['attendance_stats'])) {
        $attendanceRate = $studentData['attendance_stats']['total_days'] > 0 ? 
            ($studentData['attendance_stats']['present_days'] / $studentData['attendance_stats']['total_days']) * 100 : 0;
        
        if ($attendanceRate >= 95) {
            $attendanceScore = 25;
            $factors[] = "Excellent attendance rate (" . round($attendanceRate, 1) . "%)";
        } elseif ($attendanceRate >= 85) {
            $attendanceScore = 20;
            $factors[] = "Good attendance rate (" . round($attendanceRate, 1) . "%)";
        } elseif ($attendanceRate >= 75) {
            $attendanceScore = 15;
            $factors[] = "Moderate attendance rate (" . round($attendanceRate, 1) . "%)";
            $recommendations[] = "Improve attendance consistency";
        } else {
            $attendanceScore = 5;
            $factors[] = "Poor attendance rate (" . round($attendanceRate, 1) . "%)";
            $recommendations[] = "Critical: Address attendance issues immediately";
        }
        
        // Penalty for excessive late arrivals
        $lateRate = $studentData['attendance_stats']['total_days'] > 0 ? 
            ($studentData['attendance_stats']['late_days'] / $studentData['attendance_stats']['total_days']) * 100 : 0;
        if ($lateRate > 20) {
            $attendanceScore = max(0, $attendanceScore - 5);
            $recommendations[] = "Address punctuality issues";
        }
    } else {
        // Default scoring when no attendance data
        $attendanceScore = 15; // Assume moderate performance
        $factors[] = "Attendance data not available - using default scoring";
    }
    $score += $attendanceScore;
    
    // 2. Task Performance Analysis (Weight: 30%)
    $taskScore = 0;
    if (!empty($studentData['task_stats'])) {
        $completionRate = $studentData['task_stats']['total_tasks'] > 0 ? 
            ($studentData['task_stats']['completed_tasks'] / $studentData['task_stats']['total_tasks']) * 100 : 0;
        
        if ($completionRate >= 90) {
            $taskScore = 30;
            $factors[] = "Excellent task completion rate (" . round($completionRate, 1) . "%)";
        } elseif ($completionRate >= 75) {
            $taskScore = 25;
            $factors[] = "Good task completion rate (" . round($completionRate, 1) . "%)";
        } elseif ($completionRate >= 60) {
            $taskScore = 18;
            $factors[] = "Moderate task completion rate (" . round($completionRate, 1) . "%)";
            $recommendations[] = "Focus on completing assigned tasks";
        } else {
            $taskScore = 8;
            $factors[] = "Poor task completion rate (" . round($completionRate, 1) . "%)";
            $recommendations[] = "Critical: Improve task completion significantly";
        }
        
        // Bonus for proactive task management
        $overdueTasksCount = isset($studentData['task_stats']['overdue_tasks']) ? $studentData['task_stats']['overdue_tasks'] : 0;
        if ($overdueTasksCount == 0 && $studentData['task_stats']['total_tasks'] > 0) {
            $taskScore = min(30, $taskScore + 5);
            $factors[] = "No overdue tasks";
        } elseif ($overdueTasksCount > 3) {
            $taskScore = max(0, $taskScore - 5);
            $recommendations[] = "Address overdue tasks immediately";
        }
    } else {
        // Default scoring when no task data
        $taskScore = 18; // Assume moderate performance
        $factors[] = "Task data not available - using default scoring";
    }
    $score += $taskScore;
    
    // 3. Supervisor Evaluation Analysis (Weight: 25%)
    $evaluationScore = 0;
    if (!empty($studentData['evaluation_data']) && isset($studentData['evaluation_data']['equivalent_rating'])) {
        $rating = $studentData['evaluation_data']['equivalent_rating'];
        
        if ($rating >= 85) {
            $evaluationScore = 25;
            $factors[] = "Excellent supervisor ratings (Rating: {$rating}/100)";
        } elseif ($rating >= 75) {
            $evaluationScore = 20;
            $factors[] = "Good supervisor ratings (Rating: {$rating}/100)";
        } elseif ($rating >= 65) {
            $evaluationScore = 15;
            $factors[] = "Average supervisor ratings (Rating: {$rating}/100)";
            $recommendations[] = "Focus on improving work quality and professionalism";
        } else {
            $evaluationScore = 5;
            $factors[] = "Poor supervisor ratings (Rating: {$rating}/100)";
            $recommendations[] = "Critical: Address performance issues with supervisor";
        }
        
        // Additional checks for specific evaluation criteria
        if (isset($studentData['evaluation_data']['professionalism_avg']) && $studentData['evaluation_data']['professionalism_avg'] <= 2) {
            $recommendations[] = "Focus on professional behavior and ethics";
        }
        if (isset($studentData['evaluation_data']['productivity_avg']) && $studentData['evaluation_data']['productivity_avg'] <= 2) {
            $recommendations[] = "Urgent: Improve work quality and productivity";
        }
    } else {
        // Default scoring when no evaluation data
        $evaluationScore = 15; // Assume moderate performance
        $factors[] = "Supervisor evaluation not available - using default scoring";
    }
    $score += $evaluationScore;
    
    // 4. Progress and Hours Completion (Weight: 15%)
    $progressScore = 0;
    if (!empty($studentData['required_hours']) && $studentData['required_hours'] > 0) {
        $hoursCompletion = ($studentData['completed_hours'] / $studentData['required_hours']) * 100;
        
        // Adjust based on time elapsed
        $startDate = strtotime($studentData['start_date']);
        $endDate = strtotime($studentData['end_date']);
        $today = time();
        
        if ($startDate && $endDate && $today >= $startDate) {
            $totalDuration = $endDate - $startDate;
            $elapsed = max(1, $today - $startDate); // Prevent division by zero
            $expectedProgress = ($elapsed / $totalDuration) * 100;
            
            if ($hoursCompletion >= $expectedProgress + 10) {
                $progressScore = 15;
                $factors[] = "Ahead of schedule on hours completion";
            } elseif ($hoursCompletion >= $expectedProgress - 5) {
                $progressScore = 12;
                $factors[] = "On track with hours completion";
            } elseif ($hoursCompletion >= $expectedProgress - 15) {
                $progressScore = 8;
                $factors[] = "Slightly behind on hours completion";
                $recommendations[] = "Increase daily work hours to catch up";
            } else {
                $progressScore = 3;
                $factors[] = "Significantly behind on hours completion";
                $recommendations[] = "Critical: Urgent action needed on hours completion";
            }
        } else {
            // Default based on hours completion percentage
            if ($hoursCompletion >= 90) {
                $progressScore = 15;
            } elseif ($hoursCompletion >= 70) {
                $progressScore = 12;
            } elseif ($hoursCompletion >= 50) {
                $progressScore = 8;
            } else {
                $progressScore = 3;
            }
        }
    } else {
        // Default scoring when no hours data
        $progressScore = 8; // Assume moderate progress
        $factors[] = "Hours data not available - using default scoring";
    }
    $score += $progressScore;
    
    // 5. Self-Assessment and Stress Factors (Weight: 5%)
    $wellnessScore = 0;
    if (!empty($studentData['self_assessment'])) {
        $stressLevel = $studentData['self_assessment']['stress_level'];
        $satisfaction = $studentData['self_assessment']['workplace_satisfaction'];
        $confidence = $studentData['self_assessment']['confidence_level'];
        
        if ($stressLevel <= 2 && $satisfaction >= 4 && $confidence >= 4) {
            $wellnessScore = 5;
            $factors[] = "Positive mental health indicators";
        } elseif ($stressLevel <= 3 && $satisfaction >= 3 && $confidence >= 3) {
            $wellnessScore = 3;
        } else {
            $wellnessScore = 1;
            if ($stressLevel >= 4) {
                $recommendations[] = "Monitor and address high stress levels";
            }
            if ($satisfaction <= 2) {
                $recommendations[] = "Investigate workplace satisfaction issues";
            }
        }
    } else {
        // Default scoring when no self-assessment data
        $wellnessScore = 3; // Assume moderate wellness
        $factors[] = "Self-assessment data not available - using default scoring";
    }
    $score += $wellnessScore;
    
    // Calculate final probability (ensure it never exceeds 100)
    $probability = min(100, ($score / $maxScore) * 100);
    
    // Determine risk level based on probability
    if ($probability >= 85) {
        $riskLevel = 'very_low';
    } elseif ($probability >= 70) {
        $riskLevel = 'low';
    } elseif ($probability >= 55) {
        $riskLevel = 'medium';
    } elseif ($probability >= 40) {
        $riskLevel = 'high';
    } else {
        $riskLevel = 'very_high';
    }
    
    // Add general recommendations based on risk level
    if ($riskLevel == 'very_high' || $riskLevel == 'high') {
        $recommendations[] = "Schedule immediate intervention meeting";
        $recommendations[] = "Consider additional support resources";
    } elseif ($riskLevel == 'medium') {
        $recommendations[] = "Increase monitoring frequency";
        $recommendations[] = "Provide additional guidance and support";
    }
    
    return [
        'pass_probability' => round($probability, 1),
        'risk_level' => $riskLevel,
        'key_factors' => $factors,
        'recommendations' => array_unique($recommendations),
        'score' => $score,
        'max_score' => $maxScore,
        'component_scores' => [
            'attendance' => $attendanceScore,
            'tasks' => $taskScore,
            'evaluation' => $evaluationScore,
            'progress' => $progressScore,
            'wellness' => $wellnessScore
        ]
    ];
}

// Enhanced data fetching with prediction and filtering
$view_student = isset($_GET['view_student']) ? $_GET['view_student'] : null;

// Only fetch filter dropdown data if not viewing specific student
if (!$view_student) {
    // GET FILTER DROPDOWN DATA
    try {
        // Get unique departments for filter dropdown
        $departments_query = "SELECT DISTINCT s.department FROM students s 
                             INNER JOIN student_deployments sd ON s.id = sd.student_id 
                             WHERE s.department IS NOT NULL AND s.department != '' 
                             ORDER BY s.department";
        $departments_result = mysqli_query($conn, $departments_query);

        // Get unique sections for filter dropdown  
        $sections_query = "SELECT DISTINCT s.section FROM students s 
                          INNER JOIN student_deployments sd ON s.id = sd.student_id 
                          WHERE s.section IS NOT NULL AND s.section != '' 
                          ORDER BY s.section";
        $sections_result = mysqli_query($conn, $sections_query);

        // Get unique companies for filter dropdown
        $companies_query = "SELECT DISTINCT sd.company_name FROM student_deployments sd 
                           WHERE sd.company_name IS NOT NULL AND sd.company_name != '' 
                           ORDER BY sd.company_name";
        $companies_result = mysqli_query($conn, $companies_query);

    } catch (Exception $e) {
        $error_message = "Error fetching filter data: " . $e->getMessage();
    }
}

if ($view_student) {
    // Get comprehensive student data for prediction (detailed view)
    try {
        $studentPredictionData = getStudentDataForPrediction($view_student, $conn);
        
        if ($studentPredictionData) {
            $student_details = $studentPredictionData;
            $attendance_stats = $studentPredictionData['attendance_stats'];
            $task_stats = $studentPredictionData['task_stats'];
            $evaluation_data = $studentPredictionData['evaluation_data'];
            $self_assessment = $studentPredictionData['self_assessment'];
            
            // Generate prediction
            $prediction = predictOJTSuccess($studentPredictionData, $conn);
            
            // Calculate progress percentage
            if ($student_details['required_hours'] > 0) {
                $progress_percentage = ($student_details['completed_hours'] / $student_details['required_hours']) * 100;
                $progress_percentage = min(100, round($progress_percentage, 1));
            } else {
                $progress_percentage = 0;
            }

            // Get recent attendance data
            $attendance_query = "
                SELECT date, time_in, time_out, total_hours, status, notes
                FROM student_attendance 
                WHERE student_id = ? AND deployment_id = ?
                ORDER BY date DESC 
                LIMIT 10
            ";
            $stmt = $conn->prepare($attendance_query);
            $stmt->bind_param("ii", $view_student, $student_details['deployment_id']);
            $stmt->execute();
            $attendance_result = $stmt->get_result();
            $attendance_data = [];
            while ($row = $attendance_result->fetch_assoc()) {
                $attendance_data[] = $row;
            }

            // Get recent tasks
            $task_query = "
                SELECT t.task_id, t.task_title, t.task_description, t.due_date, t.priority, 
                      t.task_category, t.status, t.created_at,
                      ts.status as submission_status, ts.submitted_at, ts.feedback
                FROM tasks t
                LEFT JOIN task_submissions ts ON t.task_id = ts.task_id
                WHERE t.student_id = ?
                ORDER BY t.created_at DESC
                LIMIT 10
            ";
            $stmt = $conn->prepare($task_query);
            $stmt->bind_param("i", $view_student);
            $stmt->execute();
            $task_result = $stmt->get_result();
            $task_data = [];
            while ($row = $task_result->fetch_assoc()) {
                $task_data[] = $row;
            }
        }
    } catch (Exception $e) {
        $error_message = "Error fetching student details: " . $e->getMessage();
    }
} else {
    // MAIN FILTERED QUERY with real data for predictions
    try {
        // BUILD WHERE CONDITIONS FOR FILTERING
        $where_conditions = array();
        
        if (!empty($search)) {
            $where_conditions[] = "(s.first_name LIKE '%$search%' OR s.last_name LIKE '%$search%' OR s.student_id LIKE '%$search%' OR s.email LIKE '%$search%' OR sd.company_name LIKE '%$search%')";
        }

        if (!empty($department_filter)) {
            $where_conditions[] = "s.department = '$department_filter'";
        }

        if (!empty($section_filter)) {
            $where_conditions[] = "s.section = '$section_filter'";
        }

        if (!empty($company_filter)) {
            $where_conditions[] = "sd.company_name = '$company_filter'";
        }

        // Base condition
        $base_condition = "s.verified = 1 AND s.status != 'Blocked' AND sd.status IN ('Active', 'Completed') AND (sd.ojt_status IS NULL OR sd.ojt_status IN ('Active', 'Completed'))";
        
        // Combine conditions
        if (count($where_conditions) > 0) {
            $where_clause = $base_condition . " AND (" . implode(' AND ', $where_conditions) . ")";
        } else {
            $where_clause = $base_condition;
        }

        // Count total records for pagination (before risk filter)
        $count_query = "SELECT COUNT(DISTINCT s.id) as total 
                       FROM students s
                       INNER JOIN student_deployments sd ON s.id = sd.student_id
                       WHERE $where_clause";
        $count_result = mysqli_query($conn, $count_query);
        $total_records = mysqli_fetch_assoc($count_result)['total'];

        // MAIN QUERY with real data for predictions
       $students_query = "
    SELECT DISTINCT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, 
          s.program, s.year_level, s.profile_picture, s.department, s.section,
          s.contact_number, s.email,
          
          sd.deployment_id, sd.position, sd.start_date, sd.end_date, 
          sd.required_hours, sd.completed_hours, sd.status as deployment_status,
          sd.company_name, sd.supervisor_name, sd.ojt_status,
          
          cs.work_schedule_start, cs.work_schedule_end, cs.work_days
          
    FROM students s
    INNER JOIN student_deployments sd ON s.id = sd.student_id
    LEFT JOIN company_supervisors cs ON sd.supervisor_id = cs.supervisor_id
    
    WHERE $where_clause
    ORDER BY s.last_name, s.first_name
";

        $stmt = $conn->prepare($students_query);
        $stmt->execute();
        $students_result = $stmt->get_result();
        $students_data = [];
        $students_with_predictions = [];
        
        while ($row = $students_result->fetch_assoc()) {
            // Get complete data for accurate prediction
            $studentPredictionData = getStudentDataForPrediction($row['id'], $conn);
            
            if ($studentPredictionData) {
                $prediction = predictOJTSuccess($studentPredictionData, $conn);
                $row['prediction'] = $prediction;
                $students_with_predictions[] = $row;
            }
        }
        
        if (isset($student_details) && $student_details['deployment_id']) {
    $work_schedule_start = $student_details['work_schedule_start'];
    $work_schedule_end = $student_details['work_schedule_end'];  
    $work_days_string = $student_details['work_days'];
    
    // Parse work days
    $work_days_array = array_map('trim', explode(',', strtolower($work_days_string)));
    
    // Get attendance data with work day context
    $attendance_query = "
        SELECT date, time_in, time_out, total_hours, status, notes
        FROM student_attendance 
        WHERE student_id = ? AND deployment_id = ?
        ORDER BY date DESC 
        LIMIT 10
    ";
    $stmt = $conn->prepare($attendance_query);
    $stmt->bind_param("ii", $view_student, $student_details['deployment_id']);
    $stmt->execute();
    $attendance_result = $stmt->get_result();
    $attendance_data = [];
    
    while ($row = $attendance_result->fetch_assoc()) {
        // Add work day context and late calculation
        $date = $row['date'];
        $day_name = strtolower(date('l', strtotime($date)));
        $is_work_day = in_array($day_name, $work_days_array);
        
        // Calculate if late
        $is_late = false;
        $minutes_late = 0;
        if ($row['time_in'] && $work_schedule_start && $is_work_day) {
            $time_in = new DateTime($date . ' ' . $row['time_in']);
            $scheduled_start = new DateTime($date . ' ' . $work_schedule_start);
            $scheduled_start->modify('+15 minutes'); // Grace period
            
            if ($time_in > $scheduled_start) {
                $is_late = true;
                $minutes_late = round(($time_in->getTimestamp() - $scheduled_start->getTimestamp()) / 60);
            }
        }
        
        $row['is_work_day'] = $is_work_day;
        $row['is_late'] = $is_late;
        $row['minutes_late'] = $minutes_late;
        $row['day_name'] = ucfirst($day_name);
        
        $attendance_data[] = $row;
    }
}
        // Apply risk level filter if specified
        if (!empty($risk_filter)) {
            $students_with_predictions = array_filter($students_with_predictions, function($student) use ($risk_filter) {
                return $student['prediction']['risk_level'] === $risk_filter;
            });
            
            // Recalculate pagination for risk filter
            $total_records = count($students_with_predictions);
            $total_pages = ceil($total_records / $records_per_page);
            
            // Apply pagination to filtered results
            $students_with_predictions = array_slice($students_with_predictions, $offset, $records_per_page);
        } else {
            // Calculate pagination normally if no risk filter
            $total_pages = ceil($total_records / $records_per_page);
            
            // Apply pagination to all results
            $students_with_predictions = array_slice($students_with_predictions, $offset, $records_per_page);
        }
        
        $students_data = $students_with_predictions;
        
    } catch (Exception $e) {
        $error_message = "Error fetching students data: " . $e->getMessage();
    }
}

function getRiskBadgeClass($riskLevel) {
    switch($riskLevel) {
        case 'very_low': return 'bg-green-100 text-green-800';
        case 'low': return 'bg-green-100 text-green-800';
        case 'medium': return 'bg-yellow-100 text-yellow-800';
        case 'high': return 'bg-red-100 text-red-800';
        case 'very_high': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getRiskLabel($riskLevel) {
    switch($riskLevel) {
        case 'very_low': return 'Very Low Risk (85-100%)';
        case 'low': return 'Low Risk (70-84%)';
        case 'medium': return 'Medium Risk (55-69%)';
        case 'high': return 'High Risk (40-54%)';
        case 'very_high': return 'Very High Risk (0-39%)';
        default: return 'Unknown';
    }
}

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
    <title>OnTheJob Tracker - Student Performance with AI Prediction</title>
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

        .chart-container {
            position: relative;
            height: 400px;
        }

        .prediction-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: white;
        }

        .circle-success {
            background: linear-gradient(135deg, #4CAF50, #45a049);
        }

        .circle-warning {
            background: linear-gradient(135deg, #FF9800, #F57C00);
        }

        .circle-danger {
            background: linear-gradient(135deg, #F44336, #D32F2F);
        }

        .prediction-mini {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .prediction-mini .prediction-circle {
            width: 60px;
            height: 60px;
            font-size: 1rem;
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
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">
                        <?php if (!$view_student): ?>
                              Student Performance with AI Prediction
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
        <!-- Filters Section -->
            <!-- Filters Section - Only show when viewing students list -->
        <?php if (!$view_student): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
    <div class="p-4 sm:p-6">
        <!-- Filter Form -->
        <form method="GET" action="" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Search Input -->
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

                <!-- Department Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Department</label>
                    <select name="department" id="departmentFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Departments</option>
                        <?php 
                        if (isset($departments_result)) {
                            mysqli_data_seek($departments_result, 0); // Reset result pointer
                            while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                        <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department']); ?>
                                </option>
                            <?php endwhile;
                        } ?>
                    </select>
                </div>
                
                <!-- Section Filter -->
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

                <!-- Risk Level Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Risk Level</label>
                    <select name="status" id="statusFilter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Risk Levels</option>
                        <option value="very_low" <?php echo $status_filter === 'very_low' ? 'selected' : ''; ?>>Very Low Risk (85-100%)</option>
                        <option value="low" <?php echo $status_filter === 'low' ? 'selected' : ''; ?>>Low Risk (70-84%)</option>
                        <option value="medium" <?php echo $status_filter === 'medium' ? 'selected' : ''; ?>>Medium Risk (55-69%)</option>
                        <option value="high" <?php echo $status_filter === 'high' ? 'selected' : ''; ?>>High Risk (40-54%)</option>
                        <option value="very_high" <?php echo $status_filter === 'very_high' ? 'selected' : ''; ?>>Very High Risk (0-39%)</option>
                    </select>
                </div>

                <!-- Company Filter -->
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

            <!-- Filter Buttons -->
            <div class="flex justify-between items-center mt-4 pt-4 border-t border-gray-200">
                <div class="flex space-x-3">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-filter mr-2"></i>
                        Apply Filters
                    </button>
                    <button type="button" id="clearFilters" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-times mr-2"></i>
                        Clear All
                    </button>
                </div>
                
                <!-- Results Count -->
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
            <!-- Back button when viewing student details -->
            <div class="mb-6">
                <a href="StudentPerformance.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Students List
                </a>
            </div>
        <?php endif; ?>
        <!-- Main Content Area -->
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
                <!-- Students Overview with Predictions -->
                <div class="bg-white shadow-sm rounded-lg overflow-hidden">
    <!-- Header -->
    <div class="px-4 py-5 sm:px-6 border-b border-gray-200 bg-gradient-to-r from-bulsu-maroon to-bulsu-dark-maroon">
        <h3 class="text-lg leading-6 font-medium text-white flex items-center">
            <i class="fas fa-chart-line text-bulsu-gold mr-2"></i>
            Students Performance Dashboard
        </h3>
        <p class="mt-1 max-w-2xl text-sm text-bulsu-light-gold">
            AI-powered predictions for OJT success with detailed performance metrics
        </p>
    </div>
</div>


                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">AI Prediction</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
<tbody class="bg-white divide-y divide-gray-200">
    <?php if (!empty($students_data)): ?>
        <?php foreach ($students_data as $student): ?>
            <?php
            $prediction = $student['prediction'];
            $quick_probability = $prediction['pass_probability'];
            $quick_risk = $prediction['risk_level'];
            
            // Set prediction class based on risk level
            if ($quick_risk == 'very_low' || $quick_risk == 'low') {
                $prediction_class = 'circle-success';
            } elseif ($quick_risk == 'medium') {
                $prediction_class = 'circle-warning';
            } else {
                $prediction_class = 'circle-danger';
            }
            
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
                    <div class="prediction-mini">
                        <div class="prediction-circle <?php echo $prediction_class; ?>">
                            <?php echo round($quick_probability); ?>%
                        </div>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo getRiskBadgeClass($quick_risk); ?>">
                            <?php echo getRiskLabel($quick_risk); ?>
                        </span>
                        <!-- Debug info (remove in production) -->
                        <div class="text-xs text-gray-400 mt-1">
                            Score: <?php echo $prediction['score']; ?>/<?php echo $prediction['max_score']; ?>
                        </div>
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
                No students found.
            </td>
        </tr>
    <?php endif; ?>
</tbody>
            <?php else: ?>
                <!-- Individual Student Detailed View -->
                <?php if (isset($student_details) && isset($prediction)): ?>
                    <!-- Student Header -->
                   <!-- AI Prediction Summary -->
                    <div class="bg-white shadow-sm rounded-lg p-6 mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            <i class="fas fa-brain text-blue-600 mr-2"></i>
                            AI Prediction Analysis
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Risk Level -->
                            <div class="text-center">
                                <div class="mb-3">
                                    <span class="px-4 py-2 rounded-full text-sm font-semibold <?php echo getRiskBadgeClass($prediction['risk_level']); ?>">
                                        <?php echo getRiskLabel($prediction['risk_level']); ?>
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600">Risk Assessment</p>
                            </div>
                            
                            <!-- Score -->
                            <div class="text-center">
                                <div class="text-2xl font-bold text-gray-900 mb-1">
                                    <?php echo $prediction['score']; ?>/<?php echo $prediction['max_score']; ?>
                                </div>
                                <p class="text-sm text-gray-600">Performance Score</p>
                            </div>
                            
                            <!-- Progress -->
                            <div class="text-center">
                                <div class="text-2xl font-bold text-gray-900 mb-1">
                                    <?php echo $progress_percentage; ?>%
                                </div>
                                <p class="text-sm text-gray-600">Hours Completed</p>
                            </div>
                        </div>

                        <!-- Success Probability Breakdown -->
                        <div class="mt-8 bg-gray-50 rounded-lg p-6">
                            <h4 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-chart-pie text-purple-600 mr-2"></i>
                                Success Probability Computation
                            </h4>
                            
                            <div class="mb-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-lg font-semibold text-gray-900">Final Success Probability</span>
                                    <span class="text-2xl font-bold text-blue-600"><?php echo $prediction['pass_probability']; ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3">
                                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-3 rounded-full transition-all duration-1000" style="width: <?php echo $prediction['pass_probability']; ?>%"></div>
                                </div>
                            </div>

                            <!-- Computation Breakdown -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                                <?php
                                // Calculate individual component scores for display
                                $attendanceScore = 0;
                                $taskScore = 0;
                                $evaluationScore = 0;
                                $progressScore = 0;
                                $wellnessScore = 0;
                                
                                // Attendance Analysis (Weight: 25%)
                                if (!empty($attendance_stats)) {
                                    $attendanceRate = $attendance_stats['total_days'] > 0 ? 
                                        ($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100 : 0;
                                    
                                    if ($attendanceRate >= 95) {
                                        $attendanceScore = 25;
                                    } elseif ($attendanceRate >= 85) {
                                        $attendanceScore = 20;
                                    } elseif ($attendanceRate >= 75) {
                                        $attendanceScore = 15;
                                    } else {
                                        $attendanceScore = 5;
                                    }
                                    
                                    // Penalty for excessive late arrivals
                                    $lateRate = $attendance_stats['total_days'] > 0 ? 
                                        ($attendance_stats['late_days'] / $attendance_stats['total_days']) * 100 : 0;
                                    if ($lateRate > 20) {
                                        $attendanceScore = max(0, $attendanceScore - 5);
                                    }
                                }
                                
                                // Task Performance Analysis (Weight: 30%)
                                if (!empty($task_stats)) {
                                    $completionRate = $task_stats['total_tasks'] > 0 ? 
                                        ($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100 : 0;
                                    
                                    if ($completionRate >= 90) {
                                        $taskScore = 30;
                                    } elseif ($completionRate >= 75) {
                                        $taskScore = 25;
                                    } elseif ($completionRate >= 60) {
                                        $taskScore = 18;
                                    } else {
                                        $taskScore = 8;
                                    }
                                    
                                    // Bonus/Penalty for task management
                                    if ($task_stats['overdue_tasks'] == 0) {
                                        $taskScore = min(30, $taskScore + 5);
                                    } elseif ($task_stats['overdue_tasks'] > 3) {
                                        $taskScore = max(0, $taskScore - 5);
                                    }
                                }
                                
                                // Supervisor Evaluation Analysis (Weight: 25%)
                               if (!empty($evaluation_data)) {
    $avgRating = $evaluation_data['equivalent_rating'] / 20; // Convert 100-point to 5-point scale
    $evaluationScore = 0;
    
    if ($avgRating >= 4.0) {
        $evaluationScore = 25;
    } elseif ($avgRating >= 3.5) {
        $evaluationScore = 20;
    } elseif ($avgRating >= 3.0) {
        $evaluationScore = 15;
    } else {
        $evaluationScore = 5;
    }
    
    $evaluationPercent = ($evaluationScore / 25) * 100;
}
                                
                                // Progress and Hours Completion (Weight: 15%)
                                if (!empty($student_details['required_hours']) && $student_details['required_hours'] > 0) {
                                    $hoursCompletion = ($student_details['completed_hours'] / $student_details['required_hours']) * 100;
                                    
                                    // Adjust based on time elapsed
                                    $startDate = strtotime($student_details['start_date']);
                                    $endDate = strtotime($student_details['end_date']);
                                    $today = time();
                                    
                                    if ($startDate && $endDate && $today >= $startDate) {
                                        $totalDuration = $endDate - $startDate;
                                        $elapsed = $today - $startDate;
                                        $expectedProgress = $elapsed > 0 ? ($elapsed / $totalDuration) * 100 : 0;
                                        
                                        if ($hoursCompletion >= $expectedProgress + 10) {
                                            $progressScore = 15;
                                        } elseif ($hoursCompletion >= $expectedProgress - 5) {
                                            $progressScore = 12;
                                        } elseif ($hoursCompletion >= $expectedProgress - 15) {
                                            $progressScore = 8;
                                        } else {
                                            $progressScore = 3;
                                        }
                                    }
                                }
                                
                                // Self-Assessment and Stress Factors (Weight: 5%)
                                if (!empty($self_assessment)) {
                                    $stressLevel = $self_assessment['stress_level'];
                                    $satisfaction = $self_assessment['workplace_satisfaction'];
                                    $confidence = $self_assessment['confidence_level'];
                                    
                                    if ($stressLevel <= 2 && $satisfaction >= 4 && $confidence >= 4) {
                                        $wellnessScore = 5;
                                    } elseif ($stressLevel <= 3 && $satisfaction >= 3 && $confidence >= 3) {
                                        $wellnessScore = 3;
                                    } else {
                                        $wellnessScore = 1;
                                    }
                                }
                                
                                // Calculate percentages for display
                                $attendancePercent = ($attendanceScore / 25) * 100;
                                $taskPercent = ($taskScore / 30) * 100;
                                $evaluationPercent = ($evaluationScore / 25) * 100;
                                $progressPercent = ($progressScore / 15) * 100;
                                $wellnessPercent = ($wellnessScore / 5) * 100;
                                ?>
                                
                                <!-- Attendance Component -->
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-600 mb-2">Attendance</div>
                                        <div class="text-lg font-bold text-green-600 mb-2"><?php echo round($attendancePercent, 1); ?>%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-green-500 h-2 rounded-full transition-all duration-1000" style="width: <?php echo $attendancePercent; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $attendanceScore; ?>/25 points (25%)
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Task Performance Component -->
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-600 mb-2">Task Performance</div>
                                        <div class="text-lg font-bold text-blue-600 mb-2"><?php echo round($taskPercent, 1); ?>%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-500 h-2 rounded-full transition-all duration-1000" style="width: <?php echo $taskPercent; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $taskScore; ?>/30 points (30%)
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Supervisor Evaluation Component -->
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-600 mb-2">Supervisor Rating</div>
                                        <div class="text-lg font-bold text-purple-600 mb-2"><?php echo round($evaluationPercent, 1); ?>%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-purple-500 h-2 rounded-full transition-all duration-1000" style="width: <?php echo $evaluationPercent; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $evaluationScore; ?>/25 points (25%)
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Progress Component -->
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-600 mb-2">Progress</div>
                                        <div class="text-lg font-bold text-orange-600 mb-2"><?php echo round($progressPercent, 1); ?>%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-orange-500 h-2 rounded-full transition-all duration-1000" style="width: <?php echo $progressPercent; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $progressScore; ?>/15 points (15%)
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Wellness Component -->
                                <div class="bg-white p-4 rounded-lg border">
                                    <div class="text-center">
                                        <div class="text-sm font-medium text-gray-600 mb-2">Wellness</div>
                                        <div class="text-lg font-bold text-teal-600 mb-2"><?php echo round($wellnessPercent, 1); ?>%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-teal-500 h-2 rounded-full transition-all duration-1000" style="width: <?php echo $wellnessPercent; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <?php echo $wellnessScore; ?>/5 points (5%)
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Algorithm Explanation -->
                            <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                                <h5 class="text-sm font-medium text-blue-900 mb-2">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    How the AI Calculates Success Probability
                                </h5>
                                <div class="text-sm text-blue-800 space-y-1">
                                    <p><strong>Attendance (25%):</strong> Based on presence rate, punctuality, and consistency</p>
                                    <p><strong>Task Performance (30%):</strong> Completion rate, quality, and timeliness of assigned tasks</p>
                                    <p><strong>Supervisor Rating (25%):</strong> Average of 9 evaluation criteria from company supervisor</p>
                                    <p><strong>Progress Tracking (15%):</strong> Hours completion relative to timeline expectations</p>
                                    <p><strong>Wellness Factors (5%):</strong> Self-reported stress, satisfaction, and confidence levels</p>
                                </div>
                                <div class="mt-3 p-3 bg-white rounded border-l-4 border-blue-500">
                                    <p class="text-xs text-gray-600">
                                        <strong>Note:</strong> The AI algorithm uses weighted scoring with performance thresholds to ensure 
                                        accurate predictions. Scores are capped at maximum values and risk levels are determined 
                                        based on statistical analysis of successful OJT completions.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Key Factors -->
                        <?php if (!empty($prediction['key_factors'])): ?>
                            <div class="mt-6">
                                <h4 class="text-sm font-medium text-gray-900 mb-3">Key Performance Factors</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($prediction['key_factors'] as $factor): ?>
                                        <div class="flex items-center p-3 bg-green-50 rounded-md">
                                            <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                            <span class="text-sm text-green-800"><?php echo htmlspecialchars($factor); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Recommendations -->
                        <?php if (!empty($prediction['recommendations'])): ?>
                            <div class="mt-6">
                                <h4 class="text-sm font-medium text-gray-900 mb-3">AI Recommendations</h4>
                                <div class="space-y-3">
                                    <?php foreach ($prediction['recommendations'] as $recommendation): ?>
                                        <div class="flex items-start p-3 bg-yellow-50 rounded-md">
                                            <i class="fas fa-lightbulb text-yellow-500 mr-2 mt-0.5"></i>
                                            <span class="text-sm text-yellow-800"><?php echo htmlspecialchars($recommendation); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Performance Metrics Grid -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Attendance Overview -->
                        <div class="bg-white shadow-sm rounded-lg p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-calendar-check text-green-600 mr-2"></i>
                                Attendance Overview
                            </h3>
                            <?php if (!empty($attendance_stats)): ?>
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="text-center p-3 bg-green-50 rounded-md">
                                            <div class="text-2xl font-bold text-green-600">
                                                <?php echo $attendance_stats['present_days']; ?>
                                            </div>
                                            <p class="text-sm text-green-800">Present</p>
                                        </div>
                                        <div class="text-center p-3 bg-red-50 rounded-md">
                                            <div class="text-2xl font-bold text-red-600">
                                                <?php echo $attendance_stats['absent_days']; ?>
                                            </div>
                                            <p class="text-sm text-red-800">Absent</p>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="text-center p-3 bg-yellow-50 rounded-md">
                                            <div class="text-2xl font-bold text-yellow-600">
                                                <?php echo $attendance_stats['late_days']; ?>
                                            </div>
                                            <p class="text-sm text-yellow-800">Late</p>
                                        </div>
                                        <div class="text-center p-3 bg-blue-50 rounded-md">
                                            <div class="text-2xl font-bold text-blue-600">
                                                <?php echo round($attendance_stats['avg_daily_hours'], 1); ?>h
                                            </div>
                                            <p class="text-sm text-blue-800">Avg Daily Hours</p>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    $attendance_rate = $attendance_stats['total_days'] > 0 ? 
                                        ($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100 : 0;
                                    ?>
                                    <div class="mt-4">
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Attendance Rate</span>
                                            <span><?php echo round($attendance_rate, 1); ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $attendance_rate; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500">No attendance data available.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Task Performance -->
                        <div class="bg-white shadow-sm rounded-lg p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-tasks text-blue-600 mr-2"></i>
                                Task Performance
                            </h3>
                            <?php if (!empty($task_stats)): ?>
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="text-center p-3 bg-green-50 rounded-md">
                                            <div class="text-2xl font-bold text-green-600">
                                                <?php echo $task_stats['completed_tasks']; ?>
                                            </div>
                                            <p class="text-sm text-green-800">Completed</p>
                                        </div>
                                        <div class="text-center p-3 bg-yellow-50 rounded-md">
                                            <div class="text-2xl font-bold text-yellow-600">
                                                <?php echo $task_stats['in_progress_tasks']; ?>
                                            </div>
                                            <p class="text-sm text-yellow-800">In Progress</p>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="text-center p-3 bg-gray-50 rounded-md">
                                            <div class="text-2xl font-bold text-gray-600">
                                                <?php echo $task_stats['pending_tasks']; ?>
                                            </div>
                                            <p class="text-sm text-gray-800">Pending</p>
                                        </div>
                                        <div class="text-center p-3 bg-red-50 rounded-md">
                                            <div class="text-2xl font-bold text-red-600">
                                                <?php echo $task_stats['overdue_tasks']; ?>
                                            </div>
                                            <p class="text-sm text-red-800">Overdue</p>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    $completion_rate = $task_stats['total_tasks'] > 0 ? 
                                        ($task_stats['completed_tasks'] / $task_stats['total_tasks']) * 100 : 0;
                                    ?>
                                    <div class="mt-4">
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Completion Rate</span>
                                            <span><?php echo round($completion_rate, 1); ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $completion_rate; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500">No task data available.</p>
                            <?php endif; ?>
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

     <!-- Pagination -->
            <!-- Pagination - Only show when viewing students list -->
            <?php if (!$view_student && isset($total_pages) && $total_pages > 1): ?>
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

    <!-- Mobile Menu Script -->
    

<!-- Replace ALL your script tags with this single, clean version -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===========================================
    // INITIALIZE ALL VARIABLES
    // ===========================================
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const closeSidebarBtn = document.getElementById('closeSidebar');
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    const searchInput = document.getElementById('searchInput');
    const clearFiltersBtn = document.getElementById('clearFilters');
    const filterForm = document.getElementById('filterForm');
    
    // Global variables
    let currentStudentId = null;
    let currentDocumentId = null;
    let currentAction = null;

    // ===========================================
    // MOBILE SIDEBAR FUNCTIONALITY
    // ===========================================
    
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

    // Mobile menu button
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openSidebar();
        });
    }

    // Close button
    if (closeSidebarBtn) {
        closeSidebarBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeSidebar();
        });
    }

    // Overlay click
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function(e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    // Window resize handling
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            if (sidebar && sidebarOverlay) {
                sidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }
    });

    // Escape key handling
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebarOverlay && !sidebarOverlay.classList.contains('hidden')) {
            closeSidebar();
        }
    });

    // ===========================================
    // PROFILE DROPDOWN FUNCTIONALITY
    // ===========================================
    
    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!profileBtn.contains(e.target) && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
            }
        });
    }

    // ===========================================
    // FILTER FUNCTIONALITY
    // ===========================================
    
    function applyFilters() {
        const search = searchInput?.value || '';
        const department = document.getElementById('departmentFilter')?.value || '';
        const section = document.getElementById('sectionFilter')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const company = document.getElementById('companyFilter')?.value || '';
        
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (department) params.append('department', department);
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
    const filters = ['departmentFilter', 'sectionFilter', 'statusFilter', 'companyFilter'];
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

    // ===========================================
    // LOADING STATES - FIXED TO AVOID CONFLICTS
    // ===========================================
    
    // Add loading states only for navigation links, not controls
    document.querySelectorAll('a[href]:not([href^="#"]):not(.no-loading)').forEach(element => {
        // Skip sidebar controls, profile dropdown, and filter controls
        if (element.id === 'profileBtn' || 
            element.id === 'mobileMenuBtn' || 
            element.id === 'closeSidebar' ||
            element.closest('.sidebar') ||
            element.closest('#profileDropdown') ||
            element.classList.contains('no-loading')) {
            return;
        }

        element.addEventListener('click', function(e) {
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
            this.style.pointerEvents = 'none';
            
            // Reset after 3 seconds if page doesn't navigate
            setTimeout(() => {
                this.innerHTML = originalText;
                this.style.pointerEvents = 'auto';
            }, 3000);
        });
    });

    // ===========================================
    // PROGRESS BAR ANIMATIONS
    // ===========================================
    
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

    // ===========================================
    // SMOOTH SCROLLING FOR ANCHOR LINKS
    // ===========================================
    
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
});

// ===========================================
// GLOBAL FUNCTIONS
// ===========================================

function confirmLogout() {
    return confirm('Are you sure you want to logout?');
}

// ===========================================
// AUTO-REFRESH (Optional)
// ===========================================

setInterval(function() {
    if (window.location.search.includes('view_student=') && document.hasFocus()) {
        // Uncomment next line if you want auto-refresh
        // window.location.reload();
    }
}, 300000); // 5 minutes

</script>
</body>
</html>