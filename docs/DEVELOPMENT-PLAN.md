# خطة عمل — كشوفات التسليم

> محدّثة: يوليو 2026  
> المرجع التفاعلي: Canvas `delivery-system-architecture`

---

## الأولويات الحالية (حسب الطلب)

| # | المسار | الحالة |
|---|--------|--------|
| 1 | تنظيف الأزرار المكررة + مظهر ويب أوضح | تم جزء كبير |
| 2 | Release APK موقّع للتجربة الميدانية | جاهز عبر `بناء-release.bat` |
| 3 | إعادة تصميم Android (Tabs / Hilt / ViewModels) | لاحقًا بعد التجربة |

---

## الهدف بعيد المدى

تحويل المشروع من Prototype إلى Production Ready، مع Android أولًا ثم Web.

---

## النطاق

| داخل النطاق | خارج النطاق (حالياً) |
|-------------|----------------------|
| تنظيف UX الويب (زر واحد لكل وظيفة) | Workplace |
| Release موقّع للتجربة | إعادة كتابة Backend PHP |
| Android: UX + Architecture لاحقًا | iOS |
| Web: تقسيم الصفحات الثقيلة | C4/BPMN كامل |

---

## الجدول الزمني المعدّل

| المرحلة | المدة | المحتوى |
|---------|-------|---------|
| **الآن — UX ويب + Release** | أيام | إزالة التكرار، ألوان أوضح، APK موقّع |
| **1 — أساس Android** | أسبوعان | Design System، Hilt، Navigation، ViewModels |
| **2 — UX التسليم** | أسبوعان | Tabs: نظرة عامة / تسليم / متأخرون / سجل |
| **3 — الجودة** | 10 أيام | باركود، اختبارات، ProGuard، اختبار ميداني |
| **4 — Web** | أسبوعان | تقسيم `campaigns/view` و `stock` |

---

## Android: Debug → Release

- **اليوم:** بناء `app-release.apk` موقّع عبر `android-app/بناء-release.bat`
- **الاستخدام:** تجربة ميدانية محدودة
- **ليس بعد:** منتج نهائي — بعد المراحل 1–3

تحسينات قبل الـ Release:
- `allowBackup=false`
- `usesCleartextTraffic=false`
- سكربت توقيع + مثال `keystore.properties`

---

## Web: قاعدة الزر الواحد

- التنقّل (متابعة المخزن / التسليم / تعديل) → في **context-nav فقط**
- بطاقة الصفحة → أعمال فقط (توليد / رفع / تنزيل / تقرير)
- لا تكرار «تسليم المخزن» بين التوب بار وشريط الصفحة
- لا زر تسليم مكرر في لوحة المخزن

---

## Sprint Android (بعد التجربة)

```
□ Design tokens في Theme.kt
□ إعداد Hilt
□ NavGraph أساسي
□ تقسيم Dashboard إلى Tabs
```

---

## المراجع

- Canvas: `delivery-system-architecture.canvas.tsx`
- `android-app/README.md`
