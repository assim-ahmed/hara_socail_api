<?php
// ملف: backend/helpers/jwt_helper.php

class JWTHelper {
    // المفتاح السري - غير هذا في الإنتاج
    private static $secret_key = 'HARA_SOCIAL_SECRET_KEY_2024_@#$%_VERY_SECURE';
    private static $algorithm = 'HS256';
    private static $expiry = 86400; // يوم واحد (60*60*24)
    
    /**
     * إنشاء توكن جديد
     */
    public static function generateToken($user_data) {
        $issued_at = time();
        $expiration = $issued_at + self::$expiry;
        
        $payload = [
            'iss' => 'hara_social',
            'iat' => $issued_at,
            'exp' => $expiration,
            'user_id' => $user_data['id'],
            'username' => $user_data['username'],
            'full_name' => $user_data['full_name'] ?? '',
            'email' => $user_data['email']
        ];
        
        // تشفير بسيط (بدون مكتبة خارجية - للإنتاج استخدم firebase/php-jwt)
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload_encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload_encoded", self::$secret_key, true);
        $signature_encoded = base64_encode($signature);
        
        return "$header.$payload_encoded.$signature_encoded";
    }
    
    /**
     * التحقق من صحة التوكن
     */
    public static function verifyToken($jwt) {
        $parts = explode('.', $jwt);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        list($header, $payload_encoded, $signature_encoded) = $parts;
        
        // التحقق من التوقيع
        $expected_signature = hash_hmac('sha256', "$header.$payload_encoded", self::$secret_key, true);
        $expected_signature_encoded = base64_encode($expected_signature);
        
        if ($signature_encoded !== $expected_signature_encoded) {
            return null;
        }
        
        // فك تشفير ال payload
        $payload = json_decode(base64_decode($payload_encoded), true);
        
        // التحقق من صلاحية الوقت
        if ($payload['exp'] < time()) {
            return null; // منتهي الصلاحية
        }
        
        return (object)$payload;
    }
    
    /**
     * استخراج user_id من التوكن
     */
    public static function getUserIdFromToken($jwt) {
        $decoded = self::verifyToken($jwt);
        return $decoded ? $decoded->user_id : null;
    }
    
    /**
     * تجديد التوكن
     */
    public static function refreshToken($old_token) {
        $decoded = self::verifyToken($old_token);
        if (!$decoded) {
            return null;
        }
        
        $user_data = [
            'id' => $decoded->user_id,
            'username' => $decoded->username,
            'full_name' => $decoded->full_name ?? '',
            'email' => $decoded->email
        ];
        
        return self::generateToken($user_data);
    }
}
?>