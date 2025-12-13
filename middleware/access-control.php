<?php
// Course access control middleware
// Checks if user has valid access to a course based on payment/subscription

class AccessControl {
    
    /**
     * Check if user has access to a specific course
     * Returns access info or throws exception if no access
     */
    public static function checkCourseAccess($db, $userId, $courseId) {
        // Admin always has access
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
            return [
                'has_access' => true,
                'is_admin' => true,
                'chapter_limit' => null,
                'end_date' => null
            ];
        }
        
        // Check if course is public (no payment required)
        $stmt = $db->prepare("
            SELECT price_monthly 
            FROM sprakapp_courses 
            WHERE id = ? AND is_published = 1
        ");
        $stmt->execute([$courseId]);
        $course = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$course) {
            throw new Exception('Course not found or not published');
        }
        
        // If course is free (price_monthly = 0 or NULL), allow access
        if (!$course->price_monthly || $course->price_monthly == 0) {
            return [
                'has_access' => true,
                'is_free' => true,
                'chapter_limit' => null,
                'end_date' => null
            ];
        }
        
        // Check user's access in database
        $stmt = $db->prepare("
            SELECT 
                id,
                start_date,
                end_date,
                chapter_limit,
                subscription_status,
                stripe_subscription_id
            FROM sprakapp_user_course_access 
            WHERE user_id = ? AND course_id = ?
        ");
        $stmt->execute([$userId, $courseId]);
        $access = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$access) {
            throw new Exception('No access to this course. Please purchase access.');
        }
        
        // Check if access has expired
        if ($access->end_date && strtotime($access->end_date) < time()) {
            throw new Exception('Course access has expired');
        }
        
        // Check if subscription is cancelled or expired
        if ($access->stripe_subscription_id && 
            in_array($access->subscription_status, ['cancelled', 'expired'])) {
            // Allow access until end_date even if cancelled
            if ($access->end_date && strtotime($access->end_date) < time()) {
                throw new Exception('Subscription has ended');
            }
        }
        
        return [
            'has_access' => true,
            'chapter_limit' => $access->chapter_limit,
            'end_date' => $access->end_date,
            'subscription_status' => $access->subscription_status ?? 'none'
        ];
    }
    
    /**
     * Check if user has access to a specific chapter
     * Takes into account chapter_limit from course access
     */
    public static function checkChapterAccess($db, $userId, $chapterId) {
        // Get chapter info
        $stmt = $db->prepare("
            SELECT course_id, order_number 
            FROM sprakapp_chapters 
            WHERE id = ?
        ");
        $stmt->execute([$chapterId]);
        $chapter = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$chapter) {
            throw new Exception('Chapter not found');
        }
        
        // Check course access
        $courseAccess = self::checkCourseAccess($db, $userId, $chapter->course_id);
        
        // If chapter_limit is set, check if this chapter is within limit
        if ($courseAccess['chapter_limit'] !== null) {
            if ($chapter->order_number > $courseAccess['chapter_limit']) {
                throw new Exception('Chapter not included in your access. Upgrade to access more chapters.');
            }
        }
        
        return $courseAccess;
    }
    
    /**
     * Filter chapters based on user's access level
     * Returns only chapters user has access to
     */
    public static function filterChaptersByAccess($db, $userId, $chapters, $courseId) {
        try {
            $courseAccess = self::checkCourseAccess($db, $userId, $courseId);
            
            // If no chapter limit, return all chapters
            if ($courseAccess['chapter_limit'] === null) {
                return $chapters;
            }
            
            // Filter chapters based on limit
            return array_filter($chapters, function($chapter) use ($courseAccess) {
                return $chapter['order_number'] <= $courseAccess['chapter_limit'];
            });
            
        } catch (Exception $e) {
            // If no access at all, return empty array
            return [];
        }
    }
}
