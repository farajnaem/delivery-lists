# تطبيق أندرويد — تسليم المخزن

تطبيق offline-first لمزامنة تسليم الطرود مع السيرفر.

## المتطلبات

- Android Studio Ladybug أو أحدث / JDK 17
- Android SDK 34

## عنوان السيرفر

مضبوط حاليًا على الإنتاج في `app/build.gradle.kts`:

`https://delivery.rec-soc.org`

للتجربة المحلية على المحاكي يمكن مؤقتًا تغيير debug إلى `http://10.0.2.2:8090`.

## بناء Debug (تجربة سريعة)

```bat
gradlew.bat assembleDebug
```

الملف: `app\build\outputs\apk\debug\app-debug.apk`

## بناء Release موقّع (للتجربة الميدانية)

أسهل طريقة:

```bat
بناء-release.bat
```

السكربت:
1. ينشئ `delivery-release.keystore` مرة واحدة إن لم يوجد
2. يكتب `keystore.properties` (خارج Git)
3. يشغّل `assembleRelease`

أو يدويًا:

1. انسخ `keystore.properties.example` → `keystore.properties` واملأ القيم
2. أنشئ المفتاح:

```bat
keytool -genkeypair -v -keystore delivery-release.keystore -alias delivery -keyalg RSA -keysize 2048 -validity 10000
```

3. ابنِ:

```bat
gradlew.bat assembleRelease
```

الملف الناتج (عند وجود التوقيع):

`app\build\outputs\apk\release\app-release.apk`

> احفظ نسخة احتياطية من الـ keystore وكلمة المرور. فقدانها يمنع تحديث التطبيق بنفس التوقيع.

## متى نستخدم Release؟

| الهدف | الأنسب |
|--------|--------|
| تجربة سريعة على جهازك | Debug |
| تجربة ميدانية مع أمين مخزن | **Release موقّع الآن** |
| منتج نهائي (Tabs / Hilt / اختبارات / ProGuard) | بعد مراحل إعادة التصميم 1–3 |

## التثبيت على الهاتف

1. فعّل «مصادر غير معروفة»
2. انسخ APK وثبّته
3. سجّل الدخول بحساب **أمين مخزن**
4. حمّل العملية قبل التسليم
5. التسليم يعمل بدون إنترنت؛ المزامنة عند الاتصال

## API الموبايل

| المسار | الوظيفة |
|--------|---------|
| `POST /api/mobile/login` | تسجيل الدخول |
| `GET /api/mobile/campaigns` | قائمة العمليات |
| `GET /api/mobile/campaigns/{id}/snapshot` | تحميل كامل |
| `POST /api/mobile/sync` | رفع التسليمات + جلب التحديثات |

## مبدأ المزامنة

- الهاتف يرفع فقط تسليمات الـ outbox
- السيرفر يرسل السجلات المتغيرة منذ آخر مزامنة
- عند التعارض: **السيرفر يغلب**
