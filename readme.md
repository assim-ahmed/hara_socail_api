# 🚀 HARA SOCIAL - Backend API

الشبكة الاجتماعية HARA SOCIAL - الجزء الخلفي (Backend) المصمم بـ PHP

---

## 📋 نظرة عامة

HARA SOCIAL هو منصة تواصل اجتماعي تتيح للمستخدمين:
- إنشاء حساب شخصي
- نشر المقالات والمنشورات
- إضافة أصدقاء
- الدردشة الفورية (Real-time chat)
- التفاعل مع المنشورات (إعجابات)

---

## 🛠️ التقنيات المستخدمة

| التقنية | الإصدار | الاستخدام |
|----------|---------|-------------|
| **PHP** | 8.2+ | لغة البرمجة الأساسية |
| **MySQL** | 8.0+ | قاعدة البيانات |
| **Ratchet** | ^0.4 | WebSocket Server |
| **JWT** | Custom | المصادقة والتوكن |
| **Composer** | 2.x | إدارة الحزم |

---

## 📁 هيكل المشروع
hara_social_api/
│
├── api/ # ملفات API الرئيسية
│ ├── auth.php # المصادقة (تسجيل - دخول)
│ ├── posts.php # إدارة المنشورات
│ ├── friends.php # إدارة الأصدقاء
│ └── messages.php # إدارة الرسائل
│
├── config/ # إعدادات المشروع
│ └── database.php # اتصال قاعدة البيانات
│
├── helpers/ # ملفات مساعدة
│ └── jwt_helper.php # إنشاء والتحقق من JWT
│
├── middleware/ # الطبقات الوسطى
│ ├── cors.php # إعدادات CORS
│ └── auth.php # التحقق من التوكن
│
├── src/ # WebSocket Server
│ ├── index.php # مدخل خادم WebSocket
│ └── ChatHandler.php # معالج الرسائل
│
├── uploads/ # ملفات رفع الصور
│ └── profiles/ # صور الملفات الشخصية
│
├── vendor/ # حزم Composer
├── composer.json # تبعيات المشروع
└── .htaccess # إعدادات Apache



---

## 🗄️ قاعدة البيانات

### اسم قاعدة البيانات
hara_social_db



### الجداول

| الجدول | الوصف |
|--------|---------|
| `users` | بيانات المستخدمين |
| `posts` | المنشورات والمقالات |
| `friends` | علاقات الصداقة |
| `post_likes` | الإعجابات على المنشورات |
| `conversations` | محادثات المستخدمين |
| `conversation_participants` | المشاركون في المحادثات |
| `messages` | الرسائل النصية |

### تشغيل قاعدة البيانات
```sql
-- استيراد ملف SQL
mysql -u root -p < database/schema.sql

|--------|---------|

🔐 نظام المصادقة (JWT)
كيف يعمل JWT؟
تسجيل الدخول → إنشاء Token

التحقق → فك تشفير Token والتحقق من التوقيع

الصلاحية → تنتهي بعد 24 ساعة

هيكل الـ Token
text
HEADER.PAYLOAD.SIGNATURE
رأس الـ Authorization
text
Authorization: Bearer <your_jwt_token>

--------------------

📡 REST API
القاعدة الأساسية

http://localhost:2000/api/

1. المصادقة (Auth)
الطريقة	المسار	الوظيفة	يحتاج Token
POST	auth.php?action=register	تسجيل مستخدم جديد	❌
POST	auth.php?action=login	تسجيل الدخول	❌
GET	auth.php?action=me	بيانات المستخدم الحالي	✅
POST	auth.php?action=refresh	تجديد التوكن	✅

2. المنشورات (Posts)
الطريقة	المسار	الوظيفة	يحتاج Token
POST	posts.php	إنشاء منشور	✅
GET	posts.php?feed=true	جلب منشورات الأصدقاء	✅
GET	posts.php?user_id=1	منشورات مستخدم محدد	✅
DELETE	posts.php?id=1	حذف منشور	✅
POST	posts.php?like=true	إعجاب/إلغاء إعجاب	✅

3. الأصدقاء (Friends)
الطريقة	المسار	الوظيفة	يحتاج Token
GET	friends.php	جلب قائمة الأصدقاء	✅
GET	friends.php?requests=true	طلبات الصداقة الواردة	✅
POST	friends.php?action=send	إرسال طلب صداقة	✅
POST	friends.php?action=accept	قبول طلب صداقة	✅
POST	friends.php?action=reject	رفض طلب صداقة	✅
DELETE	friends.php?friend_id=1	إزالة صديق	✅

4. المحادثات (Messages)
الطريقة	المسار	الوظيفة	يحتاج Token
GET	messages.php?list=true	جلب قائمة المحادثات	✅
GET	messages.php?conversation_id=1	جلب رسائل محادثة	✅
POST	messages.php	إرسال رسالة	✅
POST	messages.php?action=conversation	إنشاء محادثة جديدة	✅

🔌 WebSocket Server (الدردشة الفورية)
التشغيل
bash
php src/index.php
ws://localhost:8080

أحداث WebSocket
الحدث	الوصف
auth	توثيق المستخدم
message	إرسال رسالة جديدة
typing	حالة الكتابة
new_message	استقبال رسالة
user_typing	إشعار الكتابة
age: 'مرحباً!'
---------------------------

🚀 تثبيت وتشغيل المشروع
المتطلبات الأساسية
PHP 8.2 أو أعلى
MySQL 8.0 أو أعلى

Composer
خطوات التثبيت
نسخ المشروع

git clone https://github.com/assim-ahmed/hara_socail_api.git
cd hara_social_api
تثبيت التبعيات

composer install
