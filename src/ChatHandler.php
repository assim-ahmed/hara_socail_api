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
    protected $userDetails;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->userDetails = [];

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
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);

        if (!$data) {
            return;
        }

        if (isset($data['type']) && $data['type'] === 'auth') {
            $this->users[$data['user_id']] = $from;
            $this->userDetails[$data['user_id']] = $this->getUserDetails($data['user_id']);

            echo "✅ User {$data['user_id']} authenticated\n";

            $this->sendOnlineFriendsToClient($from, $data['user_id']);

            $this->broadcastOnlineFriends($data['user_id']);
            return;
        }

        if (isset($data['type']) && $data['type'] === 'message') {
            $this->handleMessage($from, $data);
        }

        if (isset($data['type']) && $data['type'] === 'typing') {
            $this->handleTyping($from, $data);
        }

        if (isset($data['type']) && $data['type'] === 'status') {
            $this->handleStatus($from, $data);
        }
    }



    private function handleMessage($from, $data)
    {
        $sender_id = $data['sender_id'];
        $receiver_id = $data['receiver_id'];
        $message = $data['message'];

        // ✅ البحث عن المحادثة الموجودة بين المستخدمين
        $findConversation = "SELECT id FROM conversations 
                         WHERE (user1_id = :user1 AND user2_id = :user2) 
                         OR (user1_id = :user2 AND user2_id = :user1)";
        $findStmt = $this->db->prepare($findConversation);
        $findStmt->execute([
            ':user1' => $sender_id,
            ':user2' => $receiver_id
        ]);

        $conversation = $findStmt->fetch();

        if ($conversation) {
            // المحادثة موجودة
            $conversation_id = $conversation['id'];
            echo "✅ Using existing conversation ID: {$conversation_id}\n";
        } else {
            // إنشاء محادثة جديدة
            $insertConv = "INSERT INTO conversations (user1_id, user2_id, created_at) 
                       VALUES (:user1, :user2, NOW())";
            $insertStmt = $this->db->prepare($insertConv);
            $insertStmt->execute([
                ':user1' => $sender_id,
                ':user2' => $receiver_id
            ]);
            $conversation_id = $this->db->lastInsertId();
            echo "✅ Created new conversation ID: {$conversation_id} between {$sender_id} and {$receiver_id}\n";
        }

        // حفظ الرسالة
        $query = "INSERT INTO messages (conversation_id, sender_id, message, created_at) 
              VALUES (:conv_id, :sender_id, :message, NOW())";
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
            'receiver_id' => $receiver_id,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // إرسال للمرسل
        $from->send(json_encode($response));

        // إرسال للمستقبل إذا كان متصلاً
        if (isset($this->users[$receiver_id])) {
            $this->users[$receiver_id]->send(json_encode($response));
            echo "✅ Message sent to receiver {$receiver_id}\n";
        } else {
            echo "⚠️ Receiver {$receiver_id} is offline\n";
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

            $this->broadcastOnlineFriends($userId);
        }
    }

    private function getUserDetails($userId)
    {
        $query = "SELECT id, username, full_name, profile_pic FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getFriendsList($user_id)
    {
        $query = "SELECT 
                    CASE 
                        WHEN user_id = :user_id THEN friend_id
                        WHEN friend_id = :user_id THEN user_id
                    END as friend_id
                  FROM friends 
                  WHERE (user_id = :user_id OR friend_id = :user_id) 
                  AND status = 'accepted'";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $user_id]);

        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $result;
    }

    private function sendOnlineFriendsToClient($connection, $user_id)
    {
        $friends = $this->getFriendsList($user_id);

        if (empty($friends)) {
            $connection->send(json_encode([
                'type' => 'online_users',
                'users' => []
            ]));
            return;
        }

        $onlineUsers = array_keys($this->users);
        $onlineFriends = array_intersect($friends, $onlineUsers);

        $onlineFriendsData = [];
        foreach ($onlineFriends as $friend_id) {
            if (isset($this->userDetails[$friend_id])) {
                $onlineFriendsData[] = $this->userDetails[$friend_id];
            }
        }

        $connection->send(json_encode([
            'type' => 'online_users',
            'users' => $onlineFriendsData
        ]));
    }

    private function broadcastOnlineFriends($changed_user_id)
    {
        $affected_friends = $this->getFriendsList($changed_user_id);

        foreach ($affected_friends as $friend_id) {
            if (isset($this->users[$friend_id])) {
                $this->sendOnlineFriendsToClient($this->users[$friend_id], $friend_id);
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);

        $user_id = array_search($conn, $this->users, true);
        if ($user_id !== false) {
            unset($this->users[$user_id]);
            unset($this->userDetails[$user_id]);
            $this->broadcastOnlineFriends($user_id);
        }

        echo "🔌 Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }
}
