<?php
// ملف: backend/api/posts.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();
$user = authenticate($db);

// ============================================
// إنشاء منشور جديد (CREATE POST)
// ============================================
if ($requestMethod === 'POST' && !isset($_GET['like'])) {
    
    if (empty($requestData['content'])) {
        sendResponse(false, "محتوى المنشور مطلوب");
    }
    
    $content = trim($requestData['content']);
    
    if (strlen($content) > 5000) {
        sendResponse(false, "المنشور طويل جداً. الحد الأقصى 5000 حرف");
    }
    
    $query = "INSERT INTO posts (user_id, content) VALUES (:user_id, :content)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->bindParam(":content", $content);
    
    if ($stmt->execute()) {
        $post_id = $db->lastInsertId();
        
        // جلب المنشور الذي تم إنشاؤه
        $fetchQuery = "SELECT p.*, u.username, u.full_name, u.profile_pic 
                       FROM posts p 
                       JOIN users u ON p.user_id = u.id 
                       WHERE p.id = :id";
        $fetchStmt = $db->prepare($fetchQuery);
        $fetchStmt->bindParam(":id", $post_id);
        $fetchStmt->execute();
        $post = $fetchStmt->fetch();
        
        sendResponse(true, "تم نشر المنشور", ["post" => $post]);
    } else {
        sendResponse(false, "فشل نشر المنشور");
    }
}


// ============================================
// جلب منشورات الجميع (FEED - بدون شرط الأصدقاء)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['feed'])) {
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    
    // استعلام جديد: يجلب جميع المنشورات من جميع المستخدمين
    $query = "SELECT 
                p.*,
                u.username,
                u.full_name,
                u.profile_pic,
                COUNT(DISTINCT pl.id) as likes_count,
                CASE WHEN my_likes.user_id IS NOT NULL THEN 1 ELSE 0 END as user_liked
              FROM posts p
              JOIN users u ON p.user_id = u.id
              LEFT JOIN post_likes pl ON p.id = pl.post_id
              LEFT JOIN post_likes my_likes ON p.id = my_likes.post_id AND my_likes.user_id = :user_id
              GROUP BY p.id
              ORDER BY p.created_at DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $posts = $stmt->fetchAll();
    
    // استعلام لحساب إجمالي عدد المنشورات (لـ pagination)
    $countQuery = "SELECT COUNT(*) as total FROM posts";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute();
    $total = $countStmt->fetch()['total'];
    
    sendResponse(true, "تم جلب المنشورات", [
        "posts" => $posts,
        "pagination" => [
            "current_page" => $page,
            "per_page" => $limit,
            "total" => (int)$total,
            "total_pages" => ceil($total / $limit)
        ]
    ]);
}

// ============================================
// جلب منشورات مستخدم معين (GET USER POSTS)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['user_id'])) {
    
    $user_id = $_GET['user_id'];
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $query = "SELECT 
                p.*,
                u.username,
                u.full_name,
                u.profile_pic,
                COUNT(DISTINCT pl.id) as likes_count,
                CASE WHEN my_likes.user_id IS NOT NULL THEN 1 ELSE 0 END as user_liked
              FROM posts p
              JOIN users u ON p.user_id = u.id
              LEFT JOIN post_likes pl ON p.id = pl.post_id
              LEFT JOIN post_likes my_likes ON p.id = my_likes.post_id AND my_likes.user_id = :current_user
              WHERE p.user_id = :user_id
              GROUP BY p.id
              ORDER BY p.created_at DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user_id);
    $stmt->bindParam(":current_user", $user['id']);
    $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $posts = $stmt->fetchAll();
    
    sendResponse(true, "تم جلب منشورات المستخدم", ["posts" => $posts]);
}

// ============================================
// حذف منشور (DELETE POST)
// ============================================
else if ($requestMethod === 'DELETE' && isset($_GET['id'])) {
    
    $post_id = $_GET['id'];
    
    $checkQuery = "SELECT id FROM posts WHERE id = :id AND user_id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":id", $post_id);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        sendResponse(false, "المنشور غير موجود أو لا تملك صلاحية حذفه");
    }
    
    $query = "DELETE FROM posts WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $post_id);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم حذف المنشور");
    } else {
        sendResponse(false, "فشل حذف المنشور");
    }
}

// ============================================
// إضافة أو إزالة إعجاب (LIKE / UNLIKE)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['like'])) {
    
    if (empty($requestData['post_id'])) {
        sendResponse(false, "معرف المنشور مطلوب");
    }
    
    $post_id = $requestData['post_id'];
    
    $checkQuery = "SELECT id FROM post_likes WHERE post_id = :post_id AND user_id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":post_id", $post_id);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        // إزالة الإعجاب
        $deleteQuery = "DELETE FROM post_likes WHERE post_id = :post_id AND user_id = :user_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(":post_id", $post_id);
        $deleteStmt->bindParam(":user_id", $user['id']);
        $deleteStmt->execute();
        
        sendResponse(true, "تم إزالة الإعجاب", ["action" => "unliked"]);
    } else {
        // إضافة إعجاب
        $insertQuery = "INSERT INTO post_likes (post_id, user_id) VALUES (:post_id, :user_id)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(":post_id", $post_id);
        $insertStmt->bindParam(":user_id", $user['id']);
        $insertStmt->execute();
        
        sendResponse(true, "تم الإعجاب", ["action" => "liked"]);
    }
}

else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>