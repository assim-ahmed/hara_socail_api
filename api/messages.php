<?php
// ملف: backend/api/messages.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();
$user = authenticate($db);

// ============================================
// بدء محادثة جديدة أو جلب محادثة موجودة
// ============================================
if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'conversation') {
    
    if (empty($requestData['other_user_id'])) {
        sendResponse(false, "معرف المستخدم الآخر مطلوب");
    }
    
    $other_user_id = $requestData['other_user_id'];
    
    // البحث عن محادثة موجودة بين المستخدمين
    $findQuery = "SELECT c.id 
                  FROM conversations c
                  JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = :user_id
                  JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = :other_user_id";
    
    $findStmt = $db->prepare($findQuery);
    $findStmt->bindParam(":user_id", $user['id']);
    $findStmt->bindParam(":other_user_id", $other_user_id);
    $findStmt->execute();
    
    $existing = $findStmt->fetch();
    
    if ($existing) {
        sendResponse(true, "محادثة موجودة", ["conversation_id" => $existing['id']]);
    }
    
    // إنشاء محادثة جديدة
    $db->beginTransaction();
    
    try {
        $convQuery = "INSERT INTO conversations () VALUES ()";
        $convStmt = $db->prepare($convQuery);
        $convStmt->execute();
        $conversation_id = $db->lastInsertId();
        
        $participantQuery = "INSERT INTO conversation_participants (conversation_id, user_id) VALUES 
                            (:conv_id, :user_id),
                            (:conv_id, :other_user_id)";
        $participantStmt = $db->prepare($participantQuery);
        $participantStmt->bindParam(":conv_id", $conversation_id);
        $participantStmt->bindParam(":user_id", $user['id']);
        $participantStmt->bindParam(":other_user_id", $other_user_id);
        $participantStmt->execute();
        
        $db->commit();
        
        sendResponse(true, "تم إنشاء محادثة جديدة", ["conversation_id" => $conversation_id]);
        
    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, "فشل إنشاء المحادثة");
    }
}

// ============================================
// جلب قائمة المحادثات (GET CONVERSATIONS LIST)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['list'])) {
    
    $query = "SELECT 
                c.id as conversation_id,
                u.id as other_user_id,
                u.username,
                u.full_name,
                u.profile_pic,
                (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND is_read = 0 AND sender_id != :user_id) as unread_count
              FROM conversations c
              JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = :user_id
              JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id != :user_id
              JOIN users u ON cp2.user_id = u.id
              ORDER BY last_message_time DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":user_id", $user['id']);
    $stmt->execute();
    
    $conversations = $stmt->fetchAll();
    
    sendResponse(true, "تم جلب المحادثات", ["conversations" => $conversations]);
}

// ============================================
// جلب رسائل محادثة معينة (GET MESSAGES)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['conversation_id'])) {
    
    $conversation_id = $_GET['conversation_id'];
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // التحقق من أن المستخدم مشارك في المحادثة
    $checkQuery = "SELECT id FROM conversation_participants 
                   WHERE conversation_id = :conv_id AND user_id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":conv_id", $conversation_id);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        sendResponse(false, "غير مصرح لك برؤية هذه المحادثة", null, 403);
    }
    
    // تحديث حالة القراءة للرسائل
    $updateQuery = "UPDATE messages SET is_read = 1 
                    WHERE conversation_id = :conv_id AND sender_id != :user_id AND is_read = 0";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(":conv_id", $conversation_id);
    $updateStmt->bindParam(":user_id", $user['id']);
    $updateStmt->execute();
    
    // جلب الرسائل
    $query = "SELECT m.*, u.username, u.full_name
              FROM messages m
              JOIN users u ON m.sender_id = u.id
              WHERE m.conversation_id = :conv_id
              ORDER BY m.created_at DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":conv_id", $conversation_id);
    $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $messages = $stmt->fetchAll();
    $messages = array_reverse($messages); // ترتيب تصاعدي للعرض
    
    sendResponse(true, "تم جلب الرسائل", ["messages" => $messages]);
}

// ============================================
// إرسال رسالة جديدة (SEND MESSAGE)
// ============================================
else if ($requestMethod === 'POST' && !isset($_GET['action'])) {
    
    if (empty($requestData['conversation_id']) || empty($requestData['message'])) {
        sendResponse(false, "معرف المحادثة ومحتوى الرسالة مطلوبان");
    }
    
    $conversation_id = $requestData['conversation_id'];
    $message = trim($requestData['message']);
    
    if (strlen($message) > 1000) {
        sendResponse(false, "الرسالة طويلة جداً. الحد الأقصى 1000 حرف");
    }
    
    // التحقق من المشاركة في المحادثة
    $checkQuery = "SELECT id FROM conversation_participants 
                   WHERE conversation_id = :conv_id AND user_id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":conv_id", $conversation_id);
    $checkStmt->bindParam(":user_id", $user['id']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        sendResponse(false, "غير مصرح لك بالكتابة في هذه المحادثة", null, 403);
    }
    
    $query = "INSERT INTO messages (conversation_id, sender_id, message) 
              VALUES (:conv_id, :sender_id, :message)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":conv_id", $conversation_id);
    $stmt->bindParam(":sender_id", $user['id']);
    $stmt->bindParam(":message", $message);
    
    if ($stmt->execute()) {
        $message_id = $db->lastInsertId();
        
        $fetchQuery = "SELECT m.*, u.username, u.full_name 
                       FROM messages m
                       JOIN users u ON m.sender_id = u.id
                       WHERE m.id = :id";
        $fetchStmt = $db->prepare($fetchQuery);
        $fetchStmt->bindParam(":id", $message_id);
        $fetchStmt->execute();
        $newMessage = $fetchStmt->fetch();
        
        sendResponse(true, "تم إرسال الرسالة", ["message" => $newMessage]);
    } else {
        sendResponse(false, "فشل إرسال الرسالة");
    }
}

else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>