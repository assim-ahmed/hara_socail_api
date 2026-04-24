<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();

// التحقق من صحة التوكن (اختياري للبروفايل العام)
$currentUser = null;
try {
    $currentUser = authenticate($db);
} catch (Exception $e) {
    // المستخدم غير مسجل، لكن يمكن عرض البروفايل العام
}

// ============================================
// جلب ملف شخصي (GET profile)
// ============================================
if ($requestMethod === 'GET' && isset($_GET['profile'])) {
    $identifier = $_GET['profile'];
    
    // البحث عن المستخدم بـ id أو username
    $query = "SELECT id, username, full_name, email, bio, profile_pic, created_at 
              FROM users 
              WHERE id = :id OR username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":id", $identifier);
    $stmt->bindParam(":username", $identifier);
    $stmt->execute();
    
    $user = $stmt->fetch();
    
    if (!$user) {
        sendResponse(false, "المستخدم غير موجود", null, 404);
    }
    
    // إخفاء البريد الإلكتروني إذا كان المشاهد ليس صاحب الحساب
    if (!$currentUser || $currentUser['id'] != $user['id']) {
        unset($user['email']);
    }
    
    sendResponse(true, "تم جلب الملف الشخصي", ["user" => $user]);
}

// ============================================
// جلب إحصائيات المستخدم (GET stats)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['stats'])) {
    $userId = $_GET['stats'];
    
    // عدد المنشورات
    $postsQuery = "SELECT COUNT(*) as total FROM posts WHERE user_id = :user_id";
    $postsStmt = $db->prepare($postsQuery);
    $postsStmt->bindParam(":user_id", $userId);
    $postsStmt->execute();
    $postsCount = $postsStmt->fetch()['total'];
    
    // عدد الأصدقاء
    $friendsQuery = "SELECT COUNT(*) as total FROM friends 
                     WHERE (user_id = :user_id OR friend_id = :user_id) AND status = 'accepted'";
    $friendsStmt = $db->prepare($friendsQuery);
    $friendsStmt->bindParam(":user_id", $userId);
    $friendsStmt->execute();
    $friendsCount = $friendsStmt->fetch()['total'];
    
    // عدد الإعجابات المستلمة
    $likesQuery = "SELECT COUNT(*) as total FROM post_likes pl 
                   JOIN posts p ON pl.post_id = p.id 
                   WHERE p.user_id = :user_id";
    $likesStmt = $db->prepare($likesQuery);
    $likesStmt->bindParam(":user_id", $userId);
    $likesStmt->execute();
    $likesCount = $likesStmt->fetch()['total'];
    
    sendResponse(true, "تم جلب الإحصائيات", [
        "posts_count" => (int)$postsCount,
        "friends_count" => (int)$friendsCount,
        "likes_received" => (int)$likesCount
    ]);
}

else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>