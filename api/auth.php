<?php
// ملف: backend/api/auth.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';  // ← هذا السطر مفقود
require_once __DIR__ . '/../helpers/jwt_helper.php';

$database = new Database();
$db = $database->getConnection();
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestData = getRequestData();

// ============================================
// تسجيل مستخدم جديد (REGISTER)
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

    // معالجة رفع الصورة (إن وجدت)
    $filename = null;
    
    // التحقق من وجود ملف مرفوع
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        
        // تحديد مسار حفظ الصور
        $uploadDir = __DIR__ . '/../uploads/profiles/';
        
        // إنشاء المجلد إذا لم يكن موجوداً
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $file = $_FILES['profile_image'];
        
        // التحقق من نوع الملف
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($fileType, $allowedTypes)) {
            sendResponse(false, 'نوع الملف غير مسموح به. الأنواع المسموحة: JPEG, PNG, GIF, WEBP');
        }
        
        // التحقق من الحجم (5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            sendResponse(false, 'حجم الصورة كبير جداً. الحد الأقصى 5 ميجابايت');
        }
        
        // إنشاء اسم فريد
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        // نقل الملف
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            sendResponse(false, 'فشل في رفع الصورة');
        }
    }
    
    // إعداد قيم الحقول
    $full_name = isset($requestData['full_name']) ? $requestData['full_name'] : null;
    // ملاحظة: تم إزالة bio لأنه غير موجود في الفورم
    
    // إدراج المستخدم الجديد - ✅ استخدام bindValue بدلاً من bindParam
    $query = "INSERT INTO users (username, email, password, full_name, profile_pic) 
              VALUES (:username, :email, :password, :full_name, :profile_pic)";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(":username", $requestData['username']);
    $stmt->bindValue(":email", $requestData['email']);
    $stmt->bindValue(":password", $hashedPassword);
    $stmt->bindValue(":full_name", $full_name);
    $stmt->bindValue(":profile_pic", $filename);
    
    if ($stmt->execute()) {
        $user_id = $db->lastInsertId();
        
        $user_data = [
            'id' => $user_id,
            'username' => $requestData['username'],
            'email' => $requestData['email'],
            'full_name' => $full_name ?? '',
            'profile_pic' => $filename ? '/uploads/profiles/' . $filename : null
        ];
        
        // إرجاع التوكن - تأكد من وجود JWTHelper
        if (class_exists('JWTHelper')) {
            $token = JWTHelper::generateToken($user_data);
        } else {
            // إذا لم يكن JWTHelper موجود، قم بإنشاء توكن بسيط
            $token = base64_encode(json_encode($user_data)) . '.' . time();
        }
        
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
// تحديث الملف الشخصي (UPDATE PROFILE)
// ============================================
else if ($requestMethod === 'PUT' && isset($_GET['action']) && $_GET['action'] === 'update') {
    $user = authenticate($db);
    
    $full_name = $requestData['full_name'] ?? $user['full_name'];
    $bio = $requestData['bio'] ?? $user['bio'];
    
    $query = "UPDATE users SET full_name = :full_name, bio = :bio WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":full_name", $full_name);
    $stmt->bindParam(":bio", $bio);
    $stmt->bindParam(":id", $user['id']);
    
    if ($stmt->execute()) {
        sendResponse(true, "تم تحديث الملف الشخصي");
    } else {
        sendResponse(false, "فشل تحديث الملف الشخصي");
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