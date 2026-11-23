# LaraPython Integration Engine

حزمة تكامل متقدمة بين بيئتي Laravel (PHP 8.4) و Python 3.

## المميزات الرئيسية

- **الوصول السريع عبر الـ Facade**: تنفيذ سكربتات بايثون أو أكواد مباشرة بسطر واحد عبر `Python::run()` و `Python::inline()`.
- **جسر البيانات ثنائي الاتجاه**: تمرير مصفوفات PHP وتحويلها تلقائياً إلى JSON واستقبال مخرجات بايثون عبر `larabridge.py`.
- **معالجة المهام في الخلفية**: دعم طوابير لارافيل عبر `Python::queue()` مع إطلاق أحداث `PythonTaskStarted` و `PythonTaskCompleted` و `PythonTaskFailed`.
- **إدارة الخدمات المصغرة**: تشغيل وإدارة خدمات FastAPI أو Flask عبر `Python::service('ai')` مع فحص الجاهزية.
- **أدوات Artisan**: فحص البيئة وتثبيت الاعتماديات وتوليد السكربتات عبر `python:health` و `python:install` و `make:python-script`.
- **التكامل مع Laravel Boost**: توفير أداة MCP لفحص البيئة واعتراض الأخطاء وقواعد مسار الكود.

## التثبيت

```bash
composer require entity/larapython
```

نشر الإعدادات والأصول:

```bash
php artisan vendor:publish --tag=larapython
```

## أمثلة الاستخدام

### 1. تشغيل مباشر

```php
use LaraPython\Facades\Python;

$result = Python::run('analyze.py', [
    'text' => 'محتوى للتحليل',
]);

$sentiment = $result->json('sentiment');
```

### 2. إرسال إلى الطوابير

```php
use LaraPython\Facades\Python;

Python::queue('analyze.py', ['priority' => 'high'])
    ->onQueue('ai-tasks')
    ->delay(now()->addSeconds(30));
```

### 3. استدعاء خدمة مصغرة

```php
use LaraPython\Facades\Python;

$response = Python::service('ai')->post('/analyze', [
    'text' => 'نص للفحص',
]);
```

