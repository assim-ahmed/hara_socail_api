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
if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'get_messages') {
    
    if (empty($requestData['user1']) || empty($requestData['user2']) ) {
        sendResponse(false, "معرف المستخدم مطلوب");
    }

    $user1_id = $requestData['user1'];
    $user2_id = $requestData['user2'];
      try {
        // البحث عن conversation_id
        $findConv = "SELECT id FROM conversations 
                     WHERE (user1_id = :user1 AND user2_id = :user2) 
                     OR (user1_id = :user2 AND user2_id = :user1)";
        $findStmt = $db->prepare($findConv);
        $findStmt->execute([
            ':user1' => $user1_id,
            ':user2' => $user2_id
        ]);
        
        $conversation = $findStmt->fetch();
        
        if (!$conversation) {
            echo "⚠️ No conversation found between {$user1_id} and {$user2_id}\n";
            sendResponse(true, "لا يوجد محادثة",["messages" => []]);
        }

        $conversation_id = $conversation['id'];
          $query = "SELECT id, conversation_id, sender_id, message, created_at 
                  FROM messages 
                  WHERE conversation_id = :conv_id 
                  ORDER BY created_at ASC";
        $stmt = $db->prepare($query);
        $stmt->execute([':conv_id' => $conversation_id]);
        
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // إضافة receiver_id لكل رسالة
        foreach ($messages as &$msg) {
            $msg['receiver_id'] = ($msg['sender_id'] == $user1_id) ? $user2_id : $user1_id;
        }
    
        sendResponse(true, "تم جلب المحادثة", ["messages" => $messages]);


    }catch(Exception $error)
    {
        sendResponse(false, "حدث خطأ اثناء التحميل");
    }
    
}

else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'get_all_conversations') {
    
    if (empty($requestData['user_id'])) {
        sendResponse(false, "معرف المستخدم مطلوب");
        exit;
    }

    $user_id = $requestData['user_id'];
    
    try {
        // جلب جميع المحادثات التي فيها المستخدم
        $query = "SELECT 
                    c.id as conversation_id,
                    c.user1_id,
                    c.user2_id,
                    c.created_at as conversation_created_at,
                    c.updated_at,
                    (SELECT message FROM messages 
                     WHERE conversation_id = c.id 
                     ORDER BY created_at DESC 
                     LIMIT 1) as last_message,
                    (SELECT created_at FROM messages 
                     WHERE conversation_id = c.id 
                     ORDER BY created_at DESC 
                     LIMIT 1) as last_message_time,
                    (SELECT COUNT(*) FROM messages 
                     WHERE conversation_id = c.id 
                     AND sender_id != :user_id 
                     AND is_read = 0) as unread_count
                  FROM conversations c
                  WHERE c.user1_id = :user_id OR c.user2_id = :user_id
                  ORDER BY updated_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute([':user_id' => $user_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // جلب بيانات الطرف الآخر لكل محادثة
        foreach ($conversations as &$conv) {
            $other_user_id = ($conv['user1_id'] == $user_id) ? $conv['user2_id'] : $conv['user1_id'];
            
            // جلب بيانات المستخدم الآخر
            $userQuery = "SELECT id, username, full_name, profile_pic, bio 
                         FROM users 
                         WHERE id = :user_id";
            $userStmt = $db->prepare($userQuery);
            $userStmt->execute([':user_id' => $other_user_id]);
            $otherUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            $conv['other_user'] = $otherUser;
            $conv['other_user_id'] = $other_user_id;
        }
        
        sendResponse(true, "تم جلب المحادثات بنجاح", ["conversations" => $conversations]);
        
    } catch(Exception $error) {
        sendResponse(false, "حدث خطأ اثناء تحميل المحادثات: " . $error->getMessage());
    }
}


else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>