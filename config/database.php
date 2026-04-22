<?php
// ملف: backend/config/database.php

require_once __DIR__ . '/../middleware/cors.php';

class Database {
    private $host = "localhost";
    private $db_name = "hara_social_db";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            echo json_encode([
                "success" => false, 
                "message" => "خطأ في الاتصال بقاعدة البيانات",
                "error" => $e->getMessage()
            ]);
            exit();
        }
        
        return $this->conn;
    }
}

// دالة مساعدة للردود JSON
function sendResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit();
}

// دالة لجلب البيانات المرسلة من React
function getRequestData() {
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $_POST;
    }
    return $data;
}
?>