# النشر على Coolify — كشوفات التسليم

دليل رفع المشروع على GitHub ثم نشره على [Coolify](https://coolify.io) ليعمل أمين المخزن من الجوال عبر رابط HTTPS.

---

## 1. رفع المشروع على GitHub

من PowerShell داخل مجلد المشروع:

```powershell
cd "Desktop\كشوفات التسليم"
git init
git add .
git commit -m "Initial commit — كشوفات التسليم"
gh repo create delivery-lists --private --source=. --push
```

> إذا لم يكن `gh` مثبتاً: أنشئ مستودعاً يدوياً على GitHub ثم:
> `git remote add origin https://github.com/YOUR_USER/delivery-lists.git`
> `git push -u origin main`

**لا ترفع** ملف `.env` — موجود في `.gitignore`.

---

## 2. إنشاء MySQL في Coolify (موصى به)

1. Coolify → **+ New** → **Database** → **MySQL 8**
2. احفظ اسم الخدمة (مثل `mysql-abc123`)

> للإنتاج استخدم MySQL — البيانات تبقى عند إعادة النشر. SQLite يحتاج volume إضافي.

---

## 3. إنشاء التطبيق

1. **+ New** → **Application**
2. **Source:** GitHub → اختر المستودع `delivery-lists`
3. **Build Pack:** `Dockerfile`
4. **Port Exposes:** `3000`
5. **Health Check Path:** `/health.php`
6. **Start Period:** `90` ثانية

---

## 4. ربط MySQL ومتغيرات البيئة

1. من التطبيق → **Connect Database** → اختر MySQL
2. Coolify يضيف `DATABASE_URL` تلقائياً
3. أضف يدوياً:

```env
APP_NAME=كشوفات التسليم
APP_URL=https://delivery.yourdomain.com
APP_DEBUG=false
APP_TIMEZONE=Asia/Riyadh
DB_DRIVER=mysql
```

| المتغير | أهمية |
|---------|--------|
| `APP_URL` | الرابط النهائي مع `https://` — **مهم** لروابط PWA والتصدير |
| `APP_DEBUG` | `false` في الإنتاج |
| `DATABASE_URL` | يُنسخ تلقائياً عند ربط MySQL |

---

## 5. النطاق (Domain)

1. التطبيق → **Domains** → أضف نطاقاً (مثل `delivery.rec-soc.org`)
2. Coolify يفعّل SSL (Let's Encrypt) تلقائياً
3. حدّث `APP_URL` بالنطاق الجديد ثم **Redeploy**

---

## 6. أول نشر

1. اضغط **Deploy**
2. بعد النجاح افتح: `https://delivery.yourdomain.com/setup`
3. أنشئ **مدير النظام**
4. من **المستخدمون** → أضف **أمين مخزن** (`warehouse_keeper`)
5. أعطِ أمين المخزن الرابط: `https://delivery.yourdomain.com/warehouse`

---

## 7. روابط مهمة بعد النشر

| المستخدم | الرابط |
|----------|--------|
| أمين المخزن (جوال) | `/warehouse` |
| المنسّق / المدير | `/` |
| متابعة المخزن | `/campaigns/stock?id=1` |

**إضافة للشاشة الرئيسية (PWA):** من Chrome/Safari على الجوال → «إضافة إلى الشاشة الرئيسية».

---

## 8. نقل بيانات اللوكال (اختياري)

إذا لديك بيانات على SQLite محلياً وتريدها على السيرفر:

1. صدّر من اللوكال عبر التطبيق (Excel)
2. أو انسخ `database/delivery.sqlite` — **لا يُنصَح** مع MySQL
3. الأسهل: أنشئ العملية من جديد على السيرفر وارفع Excel

---

## 9. استكشاف الأخطاء

| المشكلة | الحل |
|---------|------|
| 502 / Health check failed | انتظر 90 ثانية أو راجع Logs |
| خطأ قاعدة بيانات | تحقق من `DATABASE_URL` وربط MySQL |
| روابط خاطئة / CSS لا يعمل | تأكد `APP_URL=https://...` بدون `/` في النهاية |
| `/setup` لا يفتح | المستخدمون موجودون — استخدم `/login` |

---

## 10. إعادة النشر

عند أي تحديث على GitHub:

```powershell
git add .
git commit -m "وصف التحديث"
git push
```

ثم في Coolify → **Redeploy** (أو فعّل Auto Deploy).
