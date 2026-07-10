# تطبيق أندرويد — تسليم المخزن

تطبيق offline-first لمزامنة تسليم الطرود مع السيرفر.

## المتطلبات

- Android Studio Ladybug أو أحدث
- JDK 17
- Android SDK 34

## إعداد عنوان السيرفر

عدّل `SERVER_URL` في [`app/build.gradle.kts`](app/build.gradle.kts):

```kotlin
// debug — محاكي أندرويد يصل لـ localhost على الجهاز المضيف
buildConfigField("String", "SERVER_URL", "\"http://10.0.2.2:8090\"")

// release — رابط الإنتاج على Coolify
buildConfigField("String", "SERVER_URL", "\"https://delivery.rec-soc.org\"")
```

على **هاتف حقيقي** للتجربة المحلية استخدم IP الشبكة المحلية:
`https://delivery.rec-soc.org`

## بناء APK

```bash
cd android-app
./gradlew assembleRelease
```

الملف الناتج:
`app/build/outputs/apk/release/app-release-unsigned.apk`

## التوقيع (release)

```bash
keytool -genkey -v -keystore delivery-release.keystore -alias delivery -keyalg RSA -keysize 2048 -validity 10000
```

أضف في `app/build.gradle.kts` (خارج Git):

```kotlin
signingConfigs {
    create("release") {
        storeFile = file("../delivery-release.keystore")
        storePassword = "..."
        keyAlias = "delivery"
        keyPassword = "..."
    }
}
buildTypes { release { signingConfig = signingConfigs.getByName("release") } }
```

## التثبيت على الهاتف

1. فعّل «مصادر غير معروفة» في إعدادات أندرويد
2. انسخ APK للهاتف وثبّته
3. سجّل الدخول بحساب **أمين مخزن**
4. اختر العملية واضغط **تحميل/تحديث** (إلزامي قبل التسليم)
5. افتح التسليم — يعمل بدون إنترنت
6. عند الاتصال: مزامنة تلقائية كل 15 دقيقة + زر «مزامنة الآن»

## API الموبايل

| المسار | الوظيفة |
|--------|---------|
| `POST /api/mobile/login` | تسجيل الدخول |
| `GET /api/mobile/campaigns` | قائمة العمليات |
| `GET /api/mobile/campaigns/{id}/snapshot` | تحميل كامل |
| `POST /api/mobile/sync` | رفع التسليمات + جلب التحديثات |

## مبدأ المزامنة

- الهاتف يرفع **فقط** التسليمات من outbox المحلي
- السيرفر يرسل **فقط** السجلات المتغيرة منذ `last_sync_token`
- عند التعارض: **السيرفر يغلب** — لا يُفقد ما سُجّل على السيرفر

## Gradle Wrapper

إذا لم يكن `gradlew` موجوداً، من Android Studio: **File → New → Import Project** واختر مجلد `android-app`.
