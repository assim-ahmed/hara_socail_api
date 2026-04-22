<?php
// ملف: backend/api/auth.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();

// ============================================
// تسجيل مستخدم جديد (REGISTER)
// ============================================
if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'register') {
    
    // التحقق من البيانات المطلوبة
    if (empty($requestData['username']) || empty($requestData['email']) || empty($requestData['password'])) {
        sendResponse(false, "جميع الحقول مطلوبة: اسم المستخدم، البريد الإلكتروني، كلمة المرور");
    }
    
    // التحقق من صحة البريد الإلكتروني
    if (!filter_var($requestData['email'], FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, "البريد الإلكتروني غير صحيح");
    }
    
    // التحقق من طول اسم المستخدم
    if (strlen($requestData['username']) < 3 || strlen($requestData['username']) > 50) {
        sendResponse(false, "اسم المستخدم يجب أن يكون بين 3 و 50 حرفاً");
    }
    
    // التحقق من طول كلمة المرور
    if (strlen($requestData['password']) < 6) {
        sendResponse(false, "كلمة المرور يجب أن تكون 6 أحرف على الأقل");
    }
    
    // تشفير كلمة المرور
    $hashedPassword = password_hash($requestData['password'], PASSWORD_DEFAULT);
    
    // التحقق من عدم وجود اسم مستخدم أو بريد مكرر
    $checkQuery = "SELECT id FROM users WHERE username = :username OR email = :email";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":username", $requestData['username']);
    $checkStmt->bindParam(":email", $requestData['email']);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        sendResponse(false, "اسم المستخدم أو البريد الإلكتروني موجود بالفعل");
    }
    
    // إدراج المستخدم الجديد
    $query = "INSERT INTO users (username, email, password, full_name, bio) 
              VALUES (:username, :email, :password, :full_name, :bio)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":username", $requestData['username']);
    $stmt->bindParam(":email", $requestData['email']);
    $stmt->bindParam(":password", $hashedPassword);
    $stmt->bindParam(":full_name", $requestData['full_name']);
    $stmt->bindParam(":bio", $requestData['bio']);
    
    if ($stmt->execute()) {
        $user_id = $db->lastInsertId();
        
        $user_data = [
            'id' => $user_id,
            'username' => $requestData['username'],
            'email' => $requestData['email'],
            'full_name' => $requestData['full_name'] ?? ''
        ];
        
        $token = JWTHelper::generateToken($user_data);
        
        sendResponse(true, "تم التسجيل بنجاح", [
            "user" => $user_data,
            "token" => $token
        ]);
    } else {
        sendResponse(false, "حدث خطأ أثناء التسجيل");
    }
}

// ============================================
// تسجيل الدخول (LOGIN)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login') {
    
    if (empty($requestData['email_or_username']) || empty($requestData['password'])) {
        sendResponse(false, "البريد الإلكتروني/اسم المستخدم وكلمة المرور مطلوبة");
    }
    
    $login = $requestData['email_or_username'];
    
    $query = "SELECT * FROM users WHERE email = :login OR username = :login";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":login", $login);
    $stmt->execute();
    
    $user = $stmt->fetch();
    
    if ($user && password_verify($requestData['password'], $user['password'])) {
        
        $user_data = [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'bio' => $user['bio'],
            'profile_pic' => $user['profile_pic']
        ];
        
        $token = JWTHelper::generateToken($user_data);
        
        sendResponse(true, "تم تسجيل الدخول بنجاح", [
            "user" => $user_data,
            "token" => $token
        ]);
    } else {
        sendResponse(false, "بيانات الدخول غير صحيحة");
    }
}

// ============================================
// الحصول على بيانات المستخدم الحالي (GET ME)
// ============================================
else if ($requestMethod === 'GET' && isset($_GET['action']) && $_GET['action'] === 'me') {
    $user = authenticate($db);
    sendResponse(true, "تم جلب البيانات", ["user" => $user]);
}

// ============================================
// تجديد التوكن (REFRESH TOKEN)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'refresh') {
    
    if (empty($requestData['token'])) {
        sendResponse(false, "التوكن مطلوب");
    }
    
    $new_token = JWTHelper::refreshToken($requestData['token']);
    
    if ($new_token) {
        sendResponse(true, "تم تجديد التوكن", ["token" => $new_token]);
    } else {
        sendResponse(false, "التوكن غير صالح أو منتهي");
    }
}

// ============================================
// تسجيل الخروج (LOGOUT)
// ============================================
else if ($requestMethod === 'POST' && isset($_GET['action']) && $_GET['action'] === 'logout') {
    // مع JWT، يتم تسجيل الخروج من جهة العميل فقط
    sendResponse(true, "تم تسجيل الخروج");
}

else {
    sendResponse(false, "طريقة غير مدعومة", null, 405);
}
?>