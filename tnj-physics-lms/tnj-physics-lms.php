<?php
/**
 * Plugin Name: TNJ Physics LMS
 * Plugin URI: https://your-site.com
 * Description: Custom Learning Management System for TNJ Physics with REST API endpoints
 * Version: 1.0.0
 * Author: Deneth Rajapaksha
 * Author URI: https://your-site.com
 * Text Domain: tnj-physics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Class
 */
class TNJ_Physics_LMS {
    
    public function __construct() {
        // Enable CORS - must be early
        add_action('init', array($this, 'enable_cors'), 1);
        
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_api_routes'));
        
        // Add custom meta boxes for courses
        add_action('add_meta_boxes', array($this, 'add_course_meta_boxes'));
        add_action('save_post_lp_course', array($this, 'save_course_meta'));
        
        // Add custom meta boxes for lessons
        add_action('add_meta_boxes', array($this, 'add_lesson_meta_boxes'));
        add_action('save_post_lp_lesson', array($this, 'save_lesson_meta'));
    }
    
    /**
     * Enable CORS for frontend
     */
    public function enable_cors() {
        // Send CORS headers on every request
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce");
            header("Access-Control-Allow-Credentials: true");
        }
        
        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }
    
    /**
     * Register Custom REST API Routes
     */
    public function register_api_routes() {
        
        // Login endpoint
        register_rest_route('tnj/v1', '/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_login'),
            'permission_callback' => '__return_true'
        ));
        
        // Get user's accessible courses
        register_rest_route('tnj/v1', '/user-courses', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_user_courses'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Get course lessons
        register_rest_route('tnj/v1', '/course/(?P<id>\d+)/lessons', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_course_lessons'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Update lesson progress
        register_rest_route('tnj/v1', '/lesson/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_complete_lesson'),
            'permission_callback' => array($this, 'check_auth')
        ));
        
        // Get lesson details
        register_rest_route('tnj/v1', '/lesson/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_lesson'),
            'permission_callback' => array($this, 'check_auth')
        ));
    }
    
    /**
     * API: Login
     */
    public function api_login($request) {
        $username = sanitize_text_field($request->get_param('username'));
        $password = $request->get_param('password');
        
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return new WP_Error('login_failed', 'Invalid username or password', array('status' => 401));
        }
        
        // Generate token
        $token = base64_encode($user->ID . ':' . time() . ':' . wp_generate_password(20, false));
        update_user_meta($user->ID, 'tnj_token', $token);
        update_user_meta($user->ID, 'tnj_token_time', time());
        
        return array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'username' => $user->user_login
            )
        );
    }
    
    /**
     * Check Authentication
     */
    public function check_auth($request) {
        $auth_header = $request->get_header('authorization');
        
        // Allow requests without auth header to proceed (will return error in callback)
        if (!$auth_header) {
            return true; // Let the callback handle the error
        }
        
        return true; // Always return true, validate in callback
    }
    
    /**
     * Get authenticated user from request
     */
    private function get_user_from_request($request) {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header) {
            return null;
        }
        
        $token = str_replace('Bearer ', '', $auth_header);
        
        $users = get_users(array(
            'meta_key' => 'tnj_token',
            'meta_value' => $token,
            'number' => 1
        ));
        
        if (empty($users)) {
            return null;
        }
        
        // Optional: Check token age (expire after 30 days)
        $token_time = get_user_meta($users[0]->ID, 'tnj_token_time', true);
        if ($token_time && (time() - $token_time) > (30 * 24 * 60 * 60)) {
            return null;
        }
        
        return $users[0];
    }
    
    /**
     * API: Get User's Accessible Courses
     */
    public function api_get_user_courses($request) {
        $user = $this->get_user_from_request($request);
        
        if (!$user) {
            return new WP_Error('unauthorized', 'Invalid token', array('status' => 401));
        }
        
        // Get user's groups (using Groups plugin)
        $user_groups = array();
        if (function_exists('groups_get_user_groups')) {
            $user_groups = groups_get_user_groups($user->ID);
        }
        
        // Get all LearnPress courses
        $courses = get_posts(array(
            'post_type' => 'lp_course',
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
        
        $user_courses = array();
        
        foreach ($courses as $course) {
            $course_id = $course->ID;
            
            // Get course number
            $course_number = get_post_meta($course_id, 'tnj_course_number', true);
            if (!$course_number) {
                // Try to extract from title
                preg_match('/^(\d+)\./', $course->post_title, $matches);
                $course_number = isset($matches[1]) ? intval($matches[1]) : 0;
            }
            
            // Check if user has access
            $has_access = $this->user_has_course_access($user->ID, $course_id, $user_groups);
            
            // Get lesson count
            $lesson_count = $this->get_course_lesson_count($course_id);
            
            // Get progress
            $progress = 0;
            if (function_exists('learn_press_get_user_course_progress')) {
                $progress = learn_press_get_user_course_progress($user->ID, $course_id);
            }
            
            // Get featured image
            $thumbnail_id = get_post_thumbnail_id($course_id);
            $image_url = '';
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            }
            
            $user_courses[] = array(
                'course_id' => intval($course_number),
                'wp_course_id' => $course_id,
                'title' => $course->post_title,
                'has_access' => $has_access,
                'lesson_count' => $lesson_count,
                'progress' => round($progress),
                'image_url' => $image_url
            );
        }
        
        // Sort by course_id
        usort($user_courses, function($a, $b) {
            return $a['course_id'] - $b['course_id'];
        });
        
        return $user_courses;
    }
    
    /**
     * Check if user has access to course
     */
    private function user_has_course_access($user_id, $course_id, $user_groups) {
        // Admin always has access
        if (user_can($user_id, 'administrator')) {
            return true;
        }
        
        // Check course groups
        $course_groups = get_post_meta($course_id, 'tnj_course_groups', true);
        
        if (empty($course_groups)) {
            // If no groups assigned, all authenticated users have access
            return true;
        }
        
        // Check if user is in any of the course groups
        foreach ($user_groups as $group_id) {
            if (in_array($group_id, $course_groups)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get course lesson count
     */
    private function get_course_lesson_count($course_id) {
        $count = 0;
        
        // Debug logging
        error_log("TNJ DEBUG - Counting lessons for course ID: " . $course_id);
        
        // Try LearnPress function first
        if (function_exists('learn_press_get_course_curriculum')) {
            $curriculum = learn_press_get_course_curriculum($course_id);
            error_log("TNJ DEBUG - Curriculum from LP function: " . print_r($curriculum, true));
            
            if (is_array($curriculum)) {
                foreach ($curriculum as $section) {
                    if (isset($section['items']) && is_array($section['items'])) {
                        foreach ($section['items'] as $item) {
                            error_log("TNJ DEBUG - Item: " . print_r($item, true));
                            if (isset($item['type']) && $item['type'] === 'lp_lesson') {
                                $count++;
                            }
                        }
                    }
                }
            }
        }
        
        error_log("TNJ DEBUG - Count from LP function: " . $count);
        
        // Fallback: Get course data object
        if ($count === 0 && class_exists('LP_Course')) {
            $course = learn_press_get_course($course_id);
            if ($course) {
                $items = $course->get_items();
                error_log("TNJ DEBUG - Items from course object: " . print_r($items, true));
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (get_post_type($item) === 'lp_lesson') {
                            $count++;
                        }
                    }
                }
            }
        }
        
        error_log("TNJ DEBUG - Count after course object: " . $count);
        
        // Another fallback: Get curriculum meta
        if ($count === 0) {
            $curriculum_meta = get_post_meta($course_id, '_lp_curriculum', true);
            error_log("TNJ DEBUG - Curriculum meta: " . print_r($curriculum_meta, true));
            
            if (is_array($curriculum_meta)) {
                foreach ($curriculum_meta as $item_id) {
                    $post_type = get_post_type($item_id);
                    error_log("TNJ DEBUG - Item ID: $item_id, Type: $post_type");
                    if ($post_type === 'lp_lesson') {
                        $count++;
                    }
                }
            }
        }
        
        error_log("TNJ DEBUG - Count after meta: " . $count);
        
        // Query lessons directly
        if ($count === 0) {
            $lessons = get_posts(array(
                'post_type' => 'lp_lesson',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => '_lp_course',
                        'value' => $course_id,
                        'compare' => '='
                    )
                )
            ));
            error_log("TNJ DEBUG - Lessons from query: " . count($lessons));
            $count = count($lessons);
        }
        
        error_log("TNJ DEBUG - Final count: " . $count);
        
        return $count;
    }
    
    /**
     * API: Get Course Lessons
     */
    public function api_get_course_lessons($request) {
        $user = $this->get_user_from_request($request);
        $course_num = intval($request->get_param('id'));
        
        if (!$user) {
            return new WP_Error('unauthorized', 'Invalid token', array('status' => 401));
        }
        
        // Find course by course number
        $courses = get_posts(array(
            'post_type' => 'lp_course',
            'posts_per_page' => 1,
            'meta_key' => 'tnj_course_number',
            'meta_value' => $course_num,
            'post_status' => 'publish'
        ));
        
        // If not found by meta, try title matching
        if (empty($courses)) {
            $all_courses = get_posts(array(
                'post_type' => 'lp_course',
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ));
            
            foreach ($all_courses as $course) {
                preg_match('/^(\d+)\./', $course->post_title, $matches);
                if (isset($matches[1]) && intval($matches[1]) === $course_num) {
                    $courses = array($course);
                    break;
                }
            }
        }
        
        if (empty($courses)) {
            return new WP_Error('not_found', 'Course not found', array('status' => 404));
        }
        
        $course = $courses[0];
        $course_id = $course->ID;
        
        error_log("TNJ DEBUG - Getting lessons for course ID: " . $course_id);
        
        // Check access
        $user_groups = function_exists('groups_get_user_groups') ? groups_get_user_groups($user->ID) : array();
        if (!$this->user_has_course_access($user->ID, $course_id, $user_groups)) {
            return new WP_Error('forbidden', 'You do not have access to this course', array('status' => 403));
        }
        
        // Get sections using LearnPress course object
        $sections = array();
        
        error_log("TNJ DEBUG - Starting to get curriculum for course ID: " . $course_id);
        
        if (class_exists('LP_Course')) {
            $lp_course = learn_press_get_course($course_id);
            error_log("TNJ DEBUG - LP_Course object: " . ($lp_course ? 'exists' : 'null'));
            
            if ($lp_course) {
                // Try get_sections method first (more reliable for sections)
                if (method_exists($lp_course, 'get_sections')) {
                    try {
                        $section_objects = $lp_course->get_sections();
                        error_log("TNJ DEBUG - get_sections() returned: " . print_r($section_objects, true));
                        
                        if (is_array($section_objects) && !empty($section_objects)) {
                            foreach ($section_objects as $section_obj) {
                                error_log("TNJ DEBUG - Section class: " . get_class($section_obj));
                                
                                if (is_object($section_obj)) {
                                    $section_id = method_exists($section_obj, 'get_id') ? $section_obj->get_id() : 0;
                                    $section_title = method_exists($section_obj, 'get_title') ? $section_obj->get_title() : 'Untitled';
                                    $section_items = method_exists($section_obj, 'get_items') ? $section_obj->get_items() : array();
                                    
                                    error_log("TNJ DEBUG - Section ID: $section_id, Title: $section_title");
                                    error_log("TNJ DEBUG - Section items: " . print_r($section_items, true));
                                    
                                    $lessons_array = array();
                                    
                                    if (is_array($section_items)) {
                                        foreach ($section_items as $item_id) {
                                            // item_id might be object or int
                                            if (is_object($item_id) && method_exists($item_id, 'get_id')) {
                                                $item_id = $item_id->get_id();
                                            }
                                            
                                            $item_type = get_post_type($item_id);
                                            error_log("TNJ DEBUG - Item ID: $item_id, Type: $item_type");
                                            
                                            if ($item_type === 'lp_lesson') {
                                                $lesson = get_post($item_id);
                                                if (!$lesson) continue;
                                                
                                                $youtube_url = get_post_meta($item_id, 'tnj_youtube_url', true);
                                                $duration = get_post_meta($item_id, 'tnj_duration', true);
                                                
                                                $completed = false;
                                                if (function_exists('learn_press_user_completed_lesson')) {
                                                    $completed = learn_press_user_completed_lesson($item_id, $course_id, $user->ID);
                                                }
                                                
                                                $lessons_array[] = array(
                                                    'id' => strval($item_id),
                                                    'title' => $lesson->post_title,
                                                    'youtube_url' => $youtube_url,
                                                    'duration' => $duration ?: '00:00',
                                                    'completed' => $completed,
                                                    'description' => $lesson->post_content
                                                );
                                                
                                                error_log("TNJ DEBUG - Added lesson to section: " . $lesson->post_title);
                                            }
                                        }
                                    }
                                    
                                    if (!empty($lessons_array)) {
                                        $sections[] = array(
                                            'id' => $section_id,
                                            'title' => $section_title,
                                            'lessons' => $lessons_array
                                        );
                                        error_log("TNJ DEBUG - Added section '$section_title' with " . count($lessons_array) . " lessons");
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("TNJ DEBUG - Error in get_sections: " . $e->getMessage());
                    }
                }
                
                // If get_sections didn't work, try get_curriculum
                if (empty($sections) && method_exists($lp_course, 'get_curriculum')) {
                    error_log("TNJ DEBUG - Trying get_curriculum as fallback");
                    try {
                        $curriculum = $lp_course->get_curriculum();
                        error_log("TNJ DEBUG - Curriculum type: " . gettype($curriculum));
                        
                        if (is_array($curriculum) || is_object($curriculum)) {
                            foreach ($curriculum as $section_obj) {
                                if (is_object($section_obj) && method_exists($section_obj, 'get_title')) {
                                    $section_id = method_exists($section_obj, 'get_id') ? $section_obj->get_id() : 0;
                                    $section_title = $section_obj->get_title();
                                    $section_items = method_exists($section_obj, 'get_items') ? $section_obj->get_items() : array();
                                    
                                    error_log("TNJ DEBUG - Curriculum section: " . $section_title);
                                    
                                    $lessons_array = array();
                                    
                                    if (is_array($section_items)) {
                                        foreach ($section_items as $item_id) {
                                            if (is_object($item_id) && method_exists($item_id, 'get_id')) {
                                                $item_id = $item_id->get_id();
                                            }
                                            
                                            $item_type = get_post_type($item_id);
                                            
                                            if ($item_type === 'lp_lesson') {
                                                $lesson = get_post($item_id);
                                                if (!$lesson) continue;
                                                
                                                $youtube_url = get_post_meta($item_id, 'tnj_youtube_url', true);
                                                $duration = get_post_meta($item_id, 'tnj_duration', true);
                                                
                                                $completed = false;
                                                if (function_exists('learn_press_user_completed_lesson')) {
                                                    $completed = learn_press_user_completed_lesson($item_id, $course_id, $user->ID);
                                                }
                                                
                                                $lessons_array[] = array(
                                                    'id' => strval($item_id),
                                                    'title' => $lesson->post_title,
                                                    'youtube_url' => $youtube_url,
                                                    'duration' => $duration ?: '00:00',
                                                    'completed' => $completed,
                                                    'description' => $lesson->post_content
                                                );
                                            }
                                        }
                                    }
                                    
                                    if (!empty($lessons_array)) {
                                        $sections[] = array(
                                            'id' => $section_id,
                                            'title' => $section_title,
                                            'lessons' => $lessons_array
                                        );
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("TNJ DEBUG - Error in get_curriculum: " . $e->getMessage());
                    }
                }
            }
        }
        
        error_log("TNJ DEBUG - Sections found after LearnPress methods: " . count($sections));
        
        // Fallback if get_curriculum doesn't work
        if (empty($sections)) {
            error_log("TNJ DEBUG - Curriculum method failed, trying fallback");
            
            // Get all lessons and put in default section
            $all_lesson_ids = array();
            
            if (class_exists('LP_Course')) {
                $lp_course = learn_press_get_course($course_id);
                if ($lp_course) {
                    $items = $lp_course->get_items();
                    error_log("TNJ DEBUG - Items from get_items(): " . print_r($items, true));
                    if (is_array($items)) {
                        $all_lesson_ids = array_filter($items, function($item_id) {
                            return get_post_type($item_id) === 'lp_lesson';
                        });
                    }
                }
            }
            
            $lessons_array = array();
            foreach ($all_lesson_ids as $lesson_id) {
                $lesson = get_post($lesson_id);
                if (!$lesson) continue;
                
                $youtube_url = get_post_meta($lesson_id, 'tnj_youtube_url', true);
                $duration = get_post_meta($lesson_id, 'tnj_duration', true);
                
                $completed = false;
                if (function_exists('learn_press_user_completed_lesson')) {
                    $completed = learn_press_user_completed_lesson($lesson_id, $course_id, $user->ID);
                }
                
                $lessons_array[] = array(
                    'id' => $lesson_id,
                    'title' => $lesson->post_title,
                    'youtube_url' => $youtube_url,
                    'duration' => $duration ?: '00:00',
                    'completed' => $completed,
                    'description' => $lesson->post_content
                );
            }
            
            if (!empty($lessons_array)) {
                $sections[] = array(
                    'id' => 0,
                    'title' => 'Course Lessons',
                    'lessons' => $lessons_array
                );
            }
        }
        
        error_log("TNJ DEBUG - Total sections: " . count($sections));
        
        return array(
            'course_id' => $course_num,
            'wp_course_id' => $course_id,
            'title' => $course->post_title,
            'description' => $course->post_content,
            'sections' => $sections
        );
    }
    
    /**
     * API: Get Single Lesson
     */
    public function api_get_lesson($request) {
        $user = $this->get_user_from_request($request);
        $lesson_id = intval($request->get_param('id'));
        
        if (!$user) {
            return new WP_Error('unauthorized', 'Invalid token', array('status' => 401));
        }
        
        $lesson = get_post($lesson_id);
        if (!$lesson || $lesson->post_type !== 'lp_lesson') {
            return new WP_Error('not_found', 'Lesson not found', array('status' => 404));
        }
        
        // Get course for this lesson
        $course_id = get_post_meta($lesson_id, '_lp_course', true);
        
        // Check access
        $user_groups = function_exists('groups_get_user_groups') ? groups_get_user_groups($user->ID) : array();
        if (!$this->user_has_course_access($user->ID, $course_id, $user_groups)) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }
        
        return array(
            'id' => $lesson_id,
            'title' => $lesson->post_title,
            'content' => $lesson->post_content,
            'youtube_url' => get_post_meta($lesson_id, 'tnj_youtube_url', true),
            'duration' => get_post_meta($lesson_id, 'tnj_duration', true)
        );
    }
    
    /**
     * API: Mark Lesson Complete
     */
    public function api_complete_lesson($request) {
        $user = $this->get_user_from_request($request);
        $lesson_id = intval($request->get_param('id'));
        
        if (!$user) {
            return new WP_Error('unauthorized', 'Invalid token', array('status' => 401));
        }
        
        $lesson = get_post($lesson_id);
        if (!$lesson || $lesson->post_type !== 'lp_lesson') {
            return new WP_Error('not_found', 'Lesson not found', array('status' => 404));
        }
        
        $course_id = get_post_meta($lesson_id, '_lp_course', true);
        
        // Mark as complete using LearnPress function
        if (function_exists('learn_press_update_user_item_status')) {
            learn_press_update_user_item_status(array(
                'user_id' => $user->ID,
                'item_id' => $lesson_id,
                'course_id' => $course_id,
                'status' => 'completed'
            ));
        }
        
        return array('success' => true, 'message' => 'Lesson marked as complete');
    }
    
    /**
     * Add meta boxes for courses
     */
    public function add_course_meta_boxes() {
        add_meta_box(
            'tnj_course_settings',
            'TNJ Course Settings',
            array($this, 'render_course_meta_box'),
            'lp_course',
            'side',
            'high'
        );
    }
    
    /**
     * Render course meta box
     */
    public function render_course_meta_box($post) {
        wp_nonce_field('tnj_course_meta', 'tnj_course_meta_nonce');
        
        $course_number = get_post_meta($post->ID, 'tnj_course_number', true);
        $course_groups = get_post_meta($post->ID, 'tnj_course_groups', true);
        
        echo '<p><label><strong>Course Number (1-12):</strong></label><br>';
        echo '<input type="number" name="tnj_course_number" value="' . esc_attr($course_number) . '" min="1" max="12" style="width:100%"></p>';
        
        // Get all groups
        if (function_exists('groups_get_groups')) {
            $all_groups = groups_get_groups();
            echo '<p><label><strong>Allowed Groups:</strong></label><br>';
            echo '<small>Leave empty to allow all students</small><br>';
            
            foreach ($all_groups as $group) {
                $checked = is_array($course_groups) && in_array($group->group_id, $course_groups) ? 'checked' : '';
                echo '<label><input type="checkbox" name="tnj_course_groups[]" value="' . $group->group_id . '" ' . $checked . '> ' . esc_html($group->name) . '</label><br>';
            }
            echo '</p>';
        }
    }
    
    /**
     * Save course meta
     */
    public function save_course_meta($post_id) {
        if (!isset($_POST['tnj_course_meta_nonce']) || !wp_verify_nonce($_POST['tnj_course_meta_nonce'], 'tnj_course_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['tnj_course_number'])) {
            update_post_meta($post_id, 'tnj_course_number', intval($_POST['tnj_course_number']));
        }
        
        if (isset($_POST['tnj_course_groups'])) {
            $groups = array_map('intval', $_POST['tnj_course_groups']);
            update_post_meta($post_id, 'tnj_course_groups', $groups);
        } else {
            delete_post_meta($post_id, 'tnj_course_groups');
        }
    }
    
    /**
     * Add meta boxes for lessons
     */
    public function add_lesson_meta_boxes() {
        add_meta_box(
            'tnj_lesson_youtube',
            'YouTube Video',
            array($this, 'render_lesson_meta_box'),
            'lp_lesson',
            'normal',
            'high'
        );
    }
    
    /**
     * Render lesson meta box
     */
    public function render_lesson_meta_box($post) {
        wp_nonce_field('tnj_lesson_meta', 'tnj_lesson_meta_nonce');
        
        $youtube_url = get_post_meta($post->ID, 'tnj_youtube_url', true);
        $duration = get_post_meta($post->ID, 'tnj_duration', true);
        
        echo '<p><label><strong>YouTube Video URL (unlisted):</strong></label><br>';
        echo '<input type="url" name="tnj_youtube_url" value="' . esc_url($youtube_url) . '" placeholder="https://www.youtube.com/watch?v=..." style="width:100%"></p>';
        
        echo '<p><label><strong>Duration (mm:ss):</strong></label><br>';
        echo '<input type="text" name="tnj_duration" value="' . esc_attr($duration) . '" placeholder="15:30" style="width:100px"></p>';
    }
    
    /**
     * Save lesson meta
     */
    public function save_lesson_meta($post_id) {
        if (!isset($_POST['tnj_lesson_meta_nonce']) || !wp_verify_nonce($_POST['tnj_lesson_meta_nonce'], 'tnj_lesson_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['tnj_youtube_url'])) {
            update_post_meta($post_id, 'tnj_youtube_url', esc_url_raw($_POST['tnj_youtube_url']));
        }
        
        if (isset($_POST['tnj_duration'])) {
            update_post_meta($post_id, 'tnj_duration', sanitize_text_field($_POST['tnj_duration']));
        }
    }
}

// Initialize the plugin
new TNJ_Physics_LMS();