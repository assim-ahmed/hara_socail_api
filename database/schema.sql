-- ============================================
-- مشروع الشبكة الاجتماعية (مشابه لفيسبوك)
-- تاريخ الإنشاء: 2024
-- ============================================

-- 1. إنشاء قاعدة البيانات
-- ============================================
CREATE DATABASE IF NOT EXISTS social_network;
USE social_network;

-- ============================================
-- 2. جدول المستخدمين (users)
-- ============================================
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    bio TEXT,
    profile_pic VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- 3. جدول المنشورات (posts)
-- ============================================
CREATE TABLE posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- 4. جدول الأصدقاء (friends)
-- ============================================
CREATE TABLE friends (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_friendship (user_id, friend_id)
);

-- ============================================
-- 5. جدول الإعجابات (post_likes)
-- ============================================
CREATE TABLE post_likes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (post_id, user_id)
);

-- ============================================
-- 6. جدول المحادثات (conversations)
-- ============================================
CREATE TABLE conversations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- 7. جدول المشاركين في المحادثة (conversation_participants)
-- ============================================
CREATE TABLE conversation_participants (
    conversation_id INT,
    user_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- 8. جدول الرسائل (messages)
-- ============================================
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- 9. إضافة بعض الفهارس لتحسين الأداء
-- ============================================
CREATE INDEX idx_posts_user_id ON posts(user_id);
CREATE INDEX idx_posts_created_at ON posts(created_at);
CREATE INDEX idx_friends_user_id ON friends(user_id);
CREATE INDEX idx_friends_friend_id ON friends(friend_id);
CREATE INDEX idx_friends_status ON friends(status);
CREATE INDEX idx_messages_conversation_id ON messages(conversation_id);
CREATE INDEX idx_messages_created_at ON messages(created_at);
CREATE INDEX idx_post_likes_post_id ON post_likes(post_id);
CREATE INDEX idx_post_likes_user_id ON post_likes(user_id);

-- ============================================
-- 10. إدخال بيانات تجريبية (اختياري)
-- ============================================

-- إضافة مستخدمين تجريبيين
INSERT INTO users (username, email, password, full_name, bio) VALUES
('ahmed_ali', 'ahmed@example.com', '$2y$10$YourHashedPasswordHere', 'أحمد علي', 'مطور ويب محترف'),
('sara_mohamed', 'sara@example.com', '$2y$10$YourHashedPasswordHere', 'سارة محمد', 'مصممة جرافيك'),
('khaled_ahmed', 'khaled@example.com', '$2y$10$YourHashedPasswordHere', 'خالد أحمد', 'مسوق رقمي');

-- ملاحظة: كلمة المرور التجريبية "123456" مشفرة
-- يمكنك استخدام: password_hash("123456", PASSWORD_DEFAULT) لتوليد التشفير

-- إضافة منشورات تجريبية
INSERT INTO posts (user_id, content) VALUES
(1, 'مرحباً بالجميع! هذا أول منشور لي في المنصة 🚀'),
(2, 'نصائح لتصميم واجهات مستخدم رائعة: 1. البساطة 2. الاتساق 3. التغذية الراجعة'),
(1, 'PHP + React معاً يصنعان تطبيقات رائعة!'),
(3, '5 استراتيجيات لزيادة التفاعل على وسائل التواصل الاجتماعي');

-- إضافة علاقات صداقة تجريبية
INSERT INTO friends (user_id, friend_id, status) VALUES
(1, 2, 'accepted'),  -- أحمد وسارة أصدقاء
(1, 3, 'pending'),   -- أحمد أرسل طلب لخالد (معلق)
(2, 3, 'accepted');  -- سارة وخالد أصدقاء

-- إضافة إعجابات تجريبية
INSERT INTO post_likes (post_id, user_id) VALUES
(1, 2),  -- سارة أعجبت بمنشور أحمد الأول
(1, 3),  -- خالد أعجب بمنشور أحمد الأول
(2, 1);  -- أحمد أعجب بمنشور سارة

-- إضافة محادثة تجريبية بين أحمد وسارة
INSERT INTO conversations () VALUES ();
SET @conv_id = LAST_INSERT_ID();

INSERT INTO conversation_participants (conversation_id, user_id) VALUES
(@conv_id, 1),  -- أحمد
(@conv_id, 2);  -- سارة

INSERT INTO messages (conversation_id, sender_id, message) VALUES
(@conv_id, 1, 'مرحباً سارة! كيف حالك؟'),
(@conv_id, 2, 'أهلاً أحمد! أنا بخير الحمدلله، كيف أنت؟'),
(@conv_id, 1, 'بخير الحمدلله، شكراً للسؤال');

-- ============================================
-- 11. استعلامات مفيدة (مخزنة كـ views)
-- ============================================

-- عرض المنشورات مع عدد الإعجابات
CREATE VIEW posts_with_likes AS
SELECT 
    p.*,
    u.username,
    u.full_name,
    u.profile_pic,
    COUNT(DISTINCT pl.id) as likes_count
FROM posts p
JOIN users u ON p.user_id = u.id
LEFT JOIN post_likes pl ON p.id = pl.post_id
GROUP BY p.id
ORDER BY p.created_at DESC;

-- عرض الأصدقاء المقبولين لمستخدم معين
CREATE VIEW accepted_friends AS
SELECT 
    u.id,
    u.username,
    u.full_name,
    u.email,
    u.profile_pic
FROM users u
WHERE u.id IN (
    SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted'
    UNION
    SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted'
);

-- عرض آخر رسالة في كل محادثة
CREATE VIEW last_message_per_conversation AS
SELECT 
    m.conversation_id,
    m.message,
    m.created_at,
    u.username as sender_username,
    u.full_name as sender_fullname
FROM messages m
JOIN users u ON m.sender_id = u.id
WHERE m.created_at = (
    SELECT MAX(created_at) 
    FROM messages m2 
    WHERE m2.conversation_id = m.conversation_id
);

-- ============================================
-- النهاية
-- ============================================