<?php
// session_manager.php - Utility functions for managing multiple user sessions

class SessionManager {
    
    // Check if specific user type is logged in
    public static function isStudentLoggedIn() {
        return isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true;
    }
    
    public static function isSupervisorLoggedIn() {
        return isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
    }
    
    public static function isAdviserLoggedIn() {
        return isset($_SESSION['adviser_logged_in']) && $_SESSION['adviser_logged_in'] === true;
    }
    
    // Get user data for specific user type
    public static function getStudentData() {
        if (!self::isStudentLoggedIn()) return null;
        
        return [
            'user_id' => $_SESSION['student_user_id'] ?? null,
            'student_id' => $_SESSION['student_student_id'] ?? null,
            'email' => $_SESSION['student_email'] ?? null,
            'first_name' => $_SESSION['student_first_name'] ?? null,
            'last_name' => $_SESSION['student_last_name'] ?? null,
            'profile_picture' => $_SESSION['student_profile_picture'] ?? null,
            'verified' => $_SESSION['student_verified'] ?? null,
        ];
    }
    
    public static function getSupervisorData() {
        if (!self::isSupervisorLoggedIn()) return null;
        
        return [
            'supervisor_id' => $_SESSION['supervisor_supervisor_id'] ?? null,
            'email' => $_SESSION['supervisor_email'] ?? null,
            'full_name' => $_SESSION['supervisor_full_name'] ?? null,
            'username' => $_SESSION['supervisor_username'] ?? null,
            'position' => $_SESSION['supervisor_position'] ?? null,
            'company_name' => $_SESSION['supervisor_company_name'] ?? null,
            'profile_picture' => $_SESSION['supervisor_profile_picture'] ?? null,
        ];
    }
    
    public static function getAdviserData() {
        if (!self::isAdviserLoggedIn()) return null;
        
        return [
            'adviser_id' => $_SESSION['adviser_adviser_id'] ?? null,
            'email' => $_SESSION['adviser_email'] ?? null,
            'name' => $_SESSION['adviser_name'] ?? null,
        ];
    }
    
    // Set user session data
    public static function setStudentSession($userData) {
        $_SESSION['student_user_id'] = $userData['id'];
        $_SESSION['student_student_id'] = $userData['student_id'];
        $_SESSION['student_email'] = $userData['email'];
        $_SESSION['student_first_name'] = $userData['first_name'];
        $_SESSION['student_last_name'] = $userData['last_name'];
        $_SESSION['student_profile_picture'] = $userData['profile_picture'];
        $_SESSION['student_verified'] = $userData['verified'];
        $_SESSION['student_logged_in'] = true;
        $_SESSION['current_user_type'] = 'student';
    }
    
    public static function setSupervisorSession($supervisorData) {
        $_SESSION['supervisor_supervisor_id'] = $supervisorData['supervisor_id'];
        $_SESSION['supervisor_email'] = $supervisorData['email'];
        $_SESSION['supervisor_full_name'] = $supervisorData['full_name'];
        $_SESSION['supervisor_username'] = $supervisorData['username'];
        $_SESSION['supervisor_position'] = $supervisorData['position'];
        $_SESSION['supervisor_company_name'] = $supervisorData['company_name'];
        $_SESSION['supervisor_profile_picture'] = $supervisorData['profile_picture'];
        $_SESSION['supervisor_logged_in'] = true;
        $_SESSION['current_user_type'] = 'supervisor';
    }
    
    public static function setAdviserSession($adviserData) {
        $_SESSION['adviser_adviser_id'] = $adviserData['id'];
        $_SESSION['adviser_email'] = $adviserData['email'];
        $_SESSION['adviser_name'] = $adviserData['name'];
        $_SESSION['adviser_logged_in'] = true;
        $_SESSION['current_user_type'] = 'adviser';
    }
    
    // Clear specific user session
    public static function clearStudentSession() {
        unset($_SESSION['student_user_id']);
        unset($_SESSION['student_student_id']);
        unset($_SESSION['student_email']);
        unset($_SESSION['student_first_name']);
        unset($_SESSION['student_last_name']);
        unset($_SESSION['student_profile_picture']);
        unset($_SESSION['student_verified']);
        unset($_SESSION['student_logged_in']);
    }
    
    public static function clearSupervisorSession() {
        unset($_SESSION['supervisor_supervisor_id']);
        unset($_SESSION['supervisor_email']);
        unset($_SESSION['supervisor_full_name']);
        unset($_SESSION['supervisor_username']);
        unset($_SESSION['supervisor_position']);
        unset($_SESSION['supervisor_company_name']);
        unset($_SESSION['supervisor_profile_picture']);
        unset($_SESSION['supervisor_logged_in']);
    }
    
    public static function clearAdviserSession() {
        unset($_SESSION['adviser_adviser_id']);
        unset($_SESSION['adviser_email']);
        unset($_SESSION['adviser_name']);
        unset($_SESSION['adviser_logged_in']);
    }
    
    // Check if any user is logged in
    public static function hasActiveUsers() {
        return self::isStudentLoggedIn() || self::isSupervisorLoggedIn() || self::isAdviserLoggedIn();
    }
    
    // Redirect to appropriate dashboard
    public static function redirectToDashboard($userType) {
        switch($userType) {
            case 'student':
                header("Location: studentdashboard.php");
                break;
            case 'supervisor':
                header("Location: Companydashboard.php");
                break;
            case 'adviser':
                header("Location: AdviserDashboard.php");
                break;
            default:
                header("Location: login.php");
        }
        exit;
    }
    
    // Get logout URL for specific user type
    public static function getLogoutUrl($userType) {
        return "logout.php?type=" . $userType;
    }
}
?>