<?php
// ملف: backend/middleware/auth.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';

function authenticate($db) {
    // الحصول على التوكن من headers
    $headers = getallheaders();
    $token = null;
    
    // Authorization: Bearer <token>
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    }
    
    // X-API-Token: <token>
    if (!$token && isset($headers['X-API-Token'])) {
        $token = $headers['X-API-Token'];
    }
    
    if (!$token) {
        sendResponse(false, "غير مصرح. يرجى تسجيل الدخول", null, 401);
    }
    
    // التحقق من صحة التوكن
    $decoded = JWTHelper::verifyToken($token);
    
    if (!$decoded) {
        sendResponse(false, "التوكن غير صالح أو منتهي الصلاحية", null, 401);
    }
    
    // جلب بيانات المستخدم من قاعدة البيانات
    $query = "SELECT id, username, full_name, email, bio, profile_pic 
              FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $decoded->user_id);
    $stmt->execute();
    
    $user = $stmt->fetch();
    
    if (!$user) {
        sendResponse(false, "المستخدم غير موجود", null, 401);
    }
    
    return $user;
}

// دالة للتحقق بدون إرجاع خطأ (اختياري)
function isAuthenticated($db) {
    $headers = getallheaders();
    $token = null;
    
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    }
    
    if (!$token) {
        return false;
    }
    
    $decoded = JWTHelper::verifyToken($token);
    if (!$decoded) {
        return false;
    }
    
    $query = "SELECT id FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $decoded->user_id);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}
?>