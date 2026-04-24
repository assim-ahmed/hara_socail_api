<?php
// ملف: backend/api/friends.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();
$user = authenticate($db);
$action = $_GET['action'] ?? '';

// ============================================
// إرسال طلب صداقة (SEND FRIEND REQUEST)
// ============================================
if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'send') {
    
    if (empty($requestData['friend_id'])) {
        sendResponse(false, "معرف الصديق مطلوب");
    }
    
    $friend_id = $requestData['friend_id'];
    
    if ($friend_id == $user['id']) {
        sendResponse(false, "لا يمكن إضافة نفسك كصديق");
    }
    
    // التحقق من وجود المستخدم
    $checkUserQuery = "SELECT id FROM users WHERE id = :id";
    $checkUserStmt = $db->prepare($checkUserQuery);
    $checkUserStmt->bindParam(":id", $friend_id);
    $checkUserStmt->execute();
    
    if ($checkUserStmt->rowCount() === 0) {
        sendResponse(false, "المستخدم غير موجود");
    }
    
    // التحقق من وجود طلب سابق
    $checkQuery = "SELECT id, status FROM friends 
                   WHERE (user_id = :user_id AND friend_id = :friend_id) 
                   OR (user_id = :friend_id AND friend_id = :user_id)";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->bindParam(":friend_id", $friend_id);
    $checkStmt->execute();
    
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        if ($existing['status'] === 'pending') {
            sendResponse(false, "طلب صداقة معلق بالفعل");
        } elseif ($existing['status'] === 'accepted') {
            sendResponse(false, "أنتم أصدقاء بالفعل");
        }
    }
    
    // إرسال طلب جديد
    $query = "INSERT INTO friends (user_id, friend_id, status) VALUES (:user_id, :friend_id, 'pending')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->bindParam(":friend_id", $friend_id);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم إرسال طلب الصداقة");
    } else {
        sendResponse(false, "فشل إرسال الطلب");
    }
}

// جلب اقتراحات الأصدقاء

if ($requestMethod === 'GET' && $action === 'suggestions') {
    // جلب مستخدمين ليسوا أصدقاء ولم يرسلوا طلبات
    $query = "SELECT u.id, u.username, u.full_name, u.profile_pic,
              (SELECT COUNT(*) FROM friends f2 
               WHERE (f2.user_id = u.id OR f2.friend_id = u.id) 
               AND f2.status = 'accepted'
               AND (f2.user_id IN (SELECT friend_id FROM friends WHERE user_id = :user_id AND status = 'accepted')
                    OR f2.friend_id IN (SELECT friend_id FROM friends WHERE user_id = :user_id AND status = 'accepted'))
              ) as mutual_friends
              FROM users u
              WHERE u.id != :user_id
              AND u.id NOT IN (
                  -- مستبعد: الأصدقاء الحاليين
                  SELECT CASE WHEN user_id = :user_id THEN friend_id ELSE user_id END 
                  FROM friends 
                  WHERE (user_id = :user_id OR friend_id = :user_id) 
                  AND status = 'accepted'
                  UNION
                  -- مستبعد: طلبات معلقة
                  SELECT CASE WHEN user_id = :user_id THEN friend_id ELSE user_id END 
                  FROM friends 
                  WHERE (user_id = :user_id OR friend_id = :user_id) 
                  AND status = 'pending'
              )
              ORDER BY mutual_friends DESC, RAND()
              LIMIT 10";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->execute();
    
    $suggestions = $stmt->fetchAll();
    sendResponse(true, "تم جلب الاقتراحات", ["suggestions" => $suggestions]);
}

// ============================================
// قبول طلب صداقة (ACCEPT FRIEND REQUEST)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'accept') {
    
    if (empty($requestData['request_id'])) {
        sendResponse(false, "معرف الطلب مطلوب");
    }
    
    $request_id = $requestData['request_id'];
    
    $checkQuery = "SELECT id FROM friends WHERE id = :id AND friend_id = :user_id AND status = 'pending'";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":id", $request_id);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        sendResponse(false, "طلب غير موجود أو لا يمكن قبوله");
    }
    
    $query = "UPDATE friends SET status = 'accepted' WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $request_id);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم قبول طلب الصداقة");
    } else {
        sendResponse(false, "فشل قبول الطلب");
    }
}

// ============================================
// رفض طلب صداقة (REJECT FRIEND REQUEST)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'reject') {
    
    if (empty($requestData['request_id'])) {
        sendResponse(false, "معرف الطلب مطلوب");
    }
    
    $request_id = $requestData['request_id'];
    
    $query = "DELETE FROM friends WHERE id = :id AND friend_id = :user_id AND status = 'pending'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $request_id);
    $stmt->bindParam(":user_id", $user['id']);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم رفض طلب الصداقة");
    } else {
        sendResponse(false, "فشل رفض الطلب");
    }
}

// ============================================
// جلب طلبات الصداقة الواردة (GET REQUESTS)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['requests'])) {
    
    $query = "SELECT f.id as request_id, f.created_at, u.*
              FROM friends f
              JOIN users u ON f.user_id = u.id
              WHERE f.friend_id = :user_id AND f.status = 'pending'
              ORDER BY f.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->execute();
    
    $requests = $stmt->fetchAll();
    
    sendResponse(true, "تم جلب الطلبات", ["requests" => $requests]);
}

// ============================================
// جلب قائمة الأصدقاء (GET FRIENDS LIST)
// ============================================
else if ($requestMethod === 'GET' && !isset($_GET['requests'])) {
    
    $query = "SELECT DISTINCT u.id, u.username, u.full_name, u.email, u.profile_pic, u.bio
              FROM users u
              WHERE u.id IN (
                  SELECT friend_id FROM friends WHERE user_id = :user_id AND status = 'accepted'
                  UNION
                  SELECT user_id FROM friends WHERE friend_id = :user_id AND status = 'accepted'
              )
              ORDER BY u.full_name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->execute();
    
    $friends = $stmt->fetchAll();
    
    sendResponse(true, "تم جلب الأصدقاء", ["friends" => $friends]);
}

// ============================================
// إزالة صديق (REMOVE FRIEND)
// ============================================
else if ($requestMethod === 'DELETE' && isset($_GET['friend_id'])) {
    
    $friend_id = $_GET['friend_id'];
    
    $query = "DELETE FROM friends 
              WHERE (user_id = :user_id AND friend_id = :friend_id) 
              OR (user_id = :friend_id AND friend_id = :user_id)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->bindParam(":friend_id", $friend_id);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم إزالة الصديق");
    } else {
        sendResponse(false, "فشل إزالة الصديق");
    }
}

else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>