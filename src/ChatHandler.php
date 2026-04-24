<?php
namespace HaraSocial;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PDO;
use PDOException;

class ChatHandler implements MessageComponentInterface
{
    protected $clients;
    protected $users;
    protected $db;
    
    // ✅ إضافة متغيرات جديدة فقط
    protected $userDetails;
    protected $rooms;
    
    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->userDetails = [];  // ✅ جديد
        $this->rooms = [];         // ✅ جديد
        
        // اتصال بقاعدة البيانات
        try {
            $this->db = new PDO(
                "mysql:host=localhost;dbname=hara_social_db;charset=utf8mb4",
                "root",
                ""
            );
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "✅ Database connected\n";
        } catch (PDOException $e) {
            echo "❌ Database error: " . $e->getMessage() . "\n";
        }
        
        echo "✅ ChatHandler initialized\n";
    }
    
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "🔌 New connection! ({$conn->resourceId})\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data) {
            return;
        }
        
        // تسجيل الدخول (ربط user_id بالاتصال)
        if (isset($data['type']) && $data['type'] === 'auth') {
            $this->users[$data['user_id']] = $from;
            
            // ✅ جديد: جلب بيانات المستخدم
            $this->userDetails[$data['user_id']] = $this->getUserDetails($data['user_id']);
            
            echo "✅ User {$data['user_id']} authenticated\n";
            
            // ✅ جديد: إرسال قائمة المتصلين للمستخدم الجديد
            $this->sendOnlineUsersToClient($from);
            
            // ✅ جديد: بث المتصلين للجميع
            $this->broadcastOnlineUsers();
            return;
        }
        
        // إرسال رسالة
        if (isset($data['type']) && $data['type'] === 'message') {
            $this->handleMessage($from, $data);
        }
        
        // حالة الكتابة (typing)
        if (isset($data['type']) && $data['type'] === 'typing') {
            $this->handleTyping($from, $data);
        }
        
        // ✅ جديد: معالجة حالة المستخدم (متصل/غير متصل)
        if (isset($data['type']) && $data['type'] === 'status') {
            $this->handleStatus($from, $data);
        }
        
        // ✅ جديد: الانضمام لغرفة
        if (isset($data['type']) && $data['type'] === 'join') {
            $this->handleJoinRoom($from, $data);
        }
        
        // ✅ جديد: مغادرة غرفة
        if (isset($data['type']) && $data['type'] === 'leave') {
            $this->handleLeaveRoom($from, $data);
        }
    }
    
    private function handleMessage($from, $data)
    {
        $conversation_id = $data['conversation_id'];
        $sender_id = $data['sender_id'];
        $receiver_id = $data['receiver_id'];
        $message = $data['message'];
        
        // حفظ في قاعدة البيانات
        $query = "INSERT INTO messages (conversation_id, sender_id, message) 
                  VALUES (:conv_id, :sender_id, :message)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':conv_id' => $conversation_id,
            ':sender_id' => $sender_id,
            ':message' => $message
        ]);
        
        $message_id = $this->db->lastInsertId();
        
        $response = [
            'type' => 'new_message',
            'id' => $message_id,
            'conversation_id' => $conversation_id,
            'sender_id' => $sender_id,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // إرسال للمرسل
        $from->send(json_encode($response));
        
        // إرسال للمستقبل إذا كان متصلاً
        if (isset($this->users[$receiver_id])) {
            $this->users[$receiver_id]->send(json_encode($response));
        }
    }
    
    private function handleTyping($from, $data)
    {
        $receiver_id = $data['receiver_id'];
        $is_typing = $data['is_typing'];
        
        if (isset($this->users[$receiver_id])) {
            $this->users[$receiver_id]->send(json_encode([
                'type' => 'user_typing',
                'user_id' => $data['sender_id'],
                'is_typing' => $is_typing
            ]));
        }
    }
    
    // ✅ جديد: معالجة حالة المستخدم
    private function handleStatus($from, $data)
    {
        $userId = array_search($from, $this->users, true);
        if ($userId !== false) {
            $isOnline = $data['is_online'] ?? true;
            
            if ($isOnline) {
                $this->users[$userId] = $from;
                $this->userDetails[$userId] = $this->getUserDetails($userId);
            } else {
                unset($this->users[$userId]);
                unset($this->userDetails[$userId]);
            }
            
            $this->broadcastOnlineUsers();
        }
    }
    
    // ✅ جديد: الانضمام لغرفة
    private function handleJoinRoom($from, $data)
    {
        $conversationId = $data['conversation_id'];
        $userId = array_search($from, $this->users, true);
        
        if ($userId) {
            if (!isset($this->rooms[$conversationId])) {
                $this->rooms[$conversationId] = [];
            }
            $this->rooms[$conversationId][$userId] = $from;
            echo "👤 User {$userId} joined room {$conversationId}\n";
        }
    }
    
    // ✅ جديد: مغادرة غرفة
    private function handleLeaveRoom($from, $data)
    {
        $conversationId = $data['conversation_id'];
        $userId = array_search($from, $this->users, true);
        
        if ($userId && isset($this->rooms[$conversationId])) {
            unset($this->rooms[$conversationId][$userId]);
            echo "👤 User {$userId} left room {$conversationId}\n";
        }
    }
    
    // ✅ جديد: جلب بيانات المستخدم
    private function getUserDetails($userId)
    {
        $query = "SELECT id, username, full_name, profile_pic FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $userId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // ✅ جديد: إرسال قائمة المتصلين لمستخدم معين
    private function sendOnlineUsersToClient($connection)
    {
        $onlineUsersData = [];
        
        foreach ($this->users as $userId => $conn) {
            if ($conn !== $connection && isset($this->userDetails[$userId])) {
                $onlineUsersData[] = $this->userDetails[$userId];
            }
        }
        
        $connection->send(json_encode([
            'type' => 'online_users',
            'users' => $onlineUsersData
        ]));
    }
    
    // ✅ جديد: بث قائمة المتصلين للجميع
    private function broadcastOnlineUsers()
    {
        $onlineUsersData = [];
        
        foreach ($this->users as $userId => $conn) {
            if (isset($this->userDetails[$userId])) {
                $onlineUsersData[] = $this->userDetails[$userId];
            }
        }
        
        $message = json_encode([
            'type' => 'online_users',
            'users' => $onlineUsersData
        ]);
        
        foreach ($this->users as $conn) {
            $conn->send($message);
        }
    }
    
    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        
        $user_id = array_search($conn, $this->users, true);
        if ($user_id !== false) {
            unset($this->users[$user_id]);
            unset($this->userDetails[$user_id]);  // ✅ جديد
            $this->broadcastOnlineUsers();         // ✅ جديد: تحديث القائمة
            echo "👋 User {$user_id} disconnected\n";
        }
        
        echo "🔌 Connection {$conn->resourceId} closed\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }
}
?>