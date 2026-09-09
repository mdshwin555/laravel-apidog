# Laravel Apidog

يولّد **OpenAPI 3.1** و**Postman collection** من راوتات مشروع Laravel — بلا أي إعداد.

كل شيء يُشتق من الكود نفسه: الراوتات، الـFormRequests، الـmiddleware. فلا يمكن أن يتقادم عن المشروع.

---

## الاستخدام

```bash
composer require hawasly/laravel-apidog --dev
php artisan api:spec
```

**انتهى.** لا ملف إعداد، ولا `vendor:publish`، ولا تعديل على شيء.

```
Output folder
  /var/www/project/docs/api

Files
  OpenAPI 3.1           project.openapi.json
  Postman collection    project.postman_collection.json
  Postman environment   project.postman_environment.json
  JSON Schema           project.schemas.json

Import
  Apidog   New project → Import → OpenAPI → pick the .openapi.json file
  Postman  import the collection and the environment together
```

---

## التقسيم: الجمهور أولاً

المجلدات مقسّمة حسب **من يستخدم النقطة**، لأنه السؤال الذي يطرحه المطوّر فعلاً — مطوّر التطبيق يريد نقاط الطالب ولا يريد نقاط الإدارة أبداً.

```
Guest      تسجيل الدخول · التسجيل · النقاط العامة
Admin      لوحة التحكم، مقسّمة بموضوعها
Student    نقاط التطبيق، مقسّمة بموضوعها
```

وداخل كل جمهور، مجلد لكل موضوع:

```
Admin / Academic Years
Admin / Categories
Admin / Content / Attachments
Student / Live Sessions / Attendance
```

**الأفعال ليست مجلدات:** `index` و`store` و`update` تقع كلها في مجلد موضوعها الواحد، لا في ثلاثة.

التقسيم يُقرأ من اسم الراوت ثم من المسار، ويُضبط في `config/api-spec.php` إن اختلفت تسمياتكم.

---

## أمثلة لكل حالة

لكل نقطة **مثال مكتمل عن كل ردّ يمكن أن تعيده** — لا النجاح وحده. مواصفة تحمل 200 فقط لا تعلّم شيئاً عن الفشل، والعميل المكتوب عليها لن يعالج 422 حتى يقابلها في الإنتاج.

| الرمز | متى يُضاف | مثال |
|---|---|---|
| 200 / 201 | دائماً | سجل كامل، أو صفحة نتائج مع `meta` |
| 422 | حين للنقطة body | بأسماء الحقول الحقيقية ورسائلها |
| 401 | حين تحتاج توكناً | |
| 403 | حين لها صلاحية | باسم الصلاحية |
| 404 | حين لها معامل مسار | |
| 429 | حين لها حدّ معدّل | بالحدّ نفسه |
| 500 | دائماً | بالشكل الذي لا يسرّب تفصيلاً داخلياً |
| 400 | حين تُبنى الاستعلامات بـ spatie/laravel-query-builder | بترتيب أو فلتر غير مسموح |

كل مثال مبنيّ من حقول النقطة نفسها، فيبدو كهذا المورد لا كقالب عام.

---

## ما يُشتق تلقائياً

| | المصدر |
|---|---|
| الطريقة والمسار والاسم | `Route::getRoutes()` |
| الجمهور والمجلد | اسم الراوت والـmiddleware |
| هل تحتاج توكناً | `auth:` |
| الصلاحية | `permission:manage_students` |
| حدّ المعدّل | `throttle:10,1` |
| حقول الـbody | `FormRequest::rules()` **و** `$request->validate([...])` |
| مطلوب / اختياري | `required` · `required_if` · `required_with` |
| الأنواع | `integer` · `numeric` · `boolean` · `date` · `email` · `file` |
| **المفاتيح الأجنبية** | `exists:` و`*_id` ← `integer` لا `string` |
| القيم المسموحة | `in:a,b` **و** `Rule::in([...])` |
| الحدود | `max:200` ← `maxLength` · `min:1` ← `minimum` |
| رفع الملفات | `file` ← `multipart/form-data` |

---

## الأمثلة مشتقّة لا مكتوبة

قيمة كل حقل تُبنى من قواعده هو: `min:8` تُنتج نصاً بثمانية أحرف، `in:...` تُنتج أول قيمة مسموحة، `url` تُنتج رابطاً، `confirmed` تُنتج كلمة مرور.

**لا جدول بأسماء حقول جاهزة** — جدول كهذا يناسب المشروع الذي كُتب له وحده، وهذا يعمل على أي مشروع.

للتجاوز عند الحاجة:

```php
'examples' => ['phone' => '0912345678'],
```

---

## Postman: يُشغَّل لا يُستورد فقط

الكولكشن يحمل ما لا تستطيع OpenAPI التعبير عنه:

**١. التوكن يُحفظ تلقائياً.** سكربت تسجيل الدخول **يبحث عن التوكن في الرد** — `access_token`، `token`، `jwt`، `bearer`، مهما كان تداخله. يعمل مع JWT وSanctum وPassport بلا إعداد.

**٢. لا لصق للتوكن.** الـbearer معرَّف مرة على الكولكشن وكل طلب يرثه. والنقاط العامة `noauth` صراحة.

**٣. المعرّفات تُلتقط.** كل طلب إنشاء يحفظ الـ`id` الراجع، فـ`GET /categories/{{category_id}}` بعده يعمل وحده. **الكولكشن يُشغَّل من أوله لآخره بضغطة واحدة.**

**٤. أمثلة الردود محفوظة** — كل الحالات، جاهزة للقراءة بلا إرسال.

---

## مثال من مشروع حقيقي

```
endpoints                  198
requiring a token          187
with a request body         84
with file uploads           17

Guest    11 مجلد
Admin    60 مجلد
Student  19 مجلد
```

---

## ملف السكيما

يُكتب مع كل توليد، بلا أمر إضافي: `project.schemas.json` بصيغة JSON Schema
2020-12، يحمل شكل الغلاف والخطأ والتحقق، ومخططاً لكل نقطة تستقبل body.

مشتق من مستند الـOpenAPI نفسه لا مبنيّ من جديد، فلا يمكن أن يصف النقطة نفسها
بشكلين. يُقرأ مباشرةً من مولّدات العملاء وأدوات التحقق دون الحاجة إلى المواصفة
كاملة.

---

## الشروحات (اختياري)

```bash
php artisan api:spec --describe
```

يكتب `docs/api-descriptions.php` بمفاتيح فارغة:

```php
'POST /admin/students' => [
    'name' => '',
    'summary' => '',
    'notes' => '',
],
```

املأه مرة واحدة، وكل توليد بعدها يدمجه. **الملف يبقى، والمواصفة تُعاد توليدها.**

---

## الأوامر

```bash
php artisan api:spec                      # الصيغتان
php artisan api:spec --format=openapi     # Apidog وحده
php artisan api:spec --format=postman
php artisan api:spec --prefix=api/admin
php artisan api:spec --output=storage/app/api
php artisan api:spec --describe
```

---

## الإعداد (نادراً ما يلزم)

```bash
php artisan vendor:publish --tag=api-spec-config
```

```php
'roles' => [
    'Guest'   => ['*/login', '*/register', '*/public/*'],
    'Admin'   => ['*/admin/*', 'admin.*'],
    'Student' => ['*/user/*', 'user.*'],
],
'output' => 'docs/api',
'prefix' => 'api',
```

---

## قواعد

- **لا تعدّل المخرَج يدوياً** — يضيع في التوليد التالي. التعديل يذهب إلى ملف الشروحات أو الإعداد.
- **صدّر بيئة Postman بلا قيم** عند مشاركتها. التوكن سرّ.

## المتطلبات

PHP 8.1+ · Laravel 10 / 11 / 12 / 13

## الترخيص

MIT
