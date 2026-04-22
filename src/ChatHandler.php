<?php
namespace HaraSocial;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PDO;
use PDOException;  // ← أضف هذا السطر

class ChatHandler implements MessageComponentInterface
{
    protected $clients;
    protected $users;
    protected $db;
    
    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        
        // اتصال بقاعدة البيانات
        try {
            $this->db = new PDO(
                "mysql:host=localhost;dbname=hara_social_db;charset=utf8mb4",
                "root",
                ""
            );
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "✅ Database connected\n";
        } catch (PDOException $e) {  // ← دلوقتي هتشتغل
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
            echo "✅ User {$data['user_id']} authenticated\n";
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
    
    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        
        $user_id = array_search($conn, $this->users, true);
        if ($user_id !== false) {
            unset($this->users[$user_id]);
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