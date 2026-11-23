# معمارية LaraPython

تعتمد المنظومة على مبدأ فصل بيئات التشغيل (Decoupled Runtimes) بين مفسر PHP ومفسر Python، مع توفير ثلاث قنوات تكامل موحدة:

```
[ Laravel Application ]
   │
   ├─► Process Channel: PythonRunner ──► [ Python 3 CLI / venv ]
   │      └── Data Bridge (JSON Base64) ──► larabridge.py
   │
   ├─► Async Channel: ExecutePythonScriptJob ──► [ Laravel Queue ]
   │      └── Events: Started, Completed, Failed
   │
   └─► Microservices Channel: PythonServiceClient ──► [ FastAPI / Uvicorn (HTTP:8001) ]
```

## المكونات المعمارية

1. **PythonPathResolver**: اكتشاف المسار التنفيذي لبايثون مع إعطاء الأولوية للبيئة الافتراضية `.venv` على مستوى المشروع.
2. **PythonScriptSecurity**: فحص أسماء وملفات السكربتات ومنع محاولات الـ Path Traversal وضمان امتداد `.py` فقط.
3. **Data Bridge**: تبادل البيانات المهيكلة بين PHP وبايثون عبر Base64 JSON وتفادي مشاكل الـ Escaping وتشفير الـ UTF-8 للعربية.
4. **PythonServiceManager**: التحكم في تشغيل وإيقاف ومراقبة خدمات بايثون المستمرة في الخلفية.
5. **Boost MCP Integration**: تكامل مع خادم MCP في Laravel Boost لتمكين المساعدين الأذكياء من فحص البيئة وتتبع الأخطاء تلقائياً.

