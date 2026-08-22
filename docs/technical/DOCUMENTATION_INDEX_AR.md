# فهرس التوثيق التقني — Project Desk

## 1. طريقة الاستخدام

هذه الحزمة توثق التنفيذ الذي تمت مراجعته من شيفرة Project Desk، وآخر مزامنة
لتوطين الواجهة بتاريخ 2026-08-14. لا تمثل وحدها رقم إصدار أو شهادة قبول. ابدأ
بـ[نظرة النظام](SYSTEM_OVERVIEW_AR.md)، ثم اختر الدليل حسب دورك. عند اختلاف
وثيقة مع التنفيذ تكون الأولوية: migrations وroutes/Requests/Policies/Services ثم
الاختبارات القابلة للتشغيل، ثم هذه الوثائق، ثم المستندات التاريخية.

رموز الحالة المستخدمة:

| الحالة | المعنى |
| --- | --- |
| منفذ `implemented` | يوجد مسار شيفرة حقيقي واختبارات/عقد مناسب بحسب الجرد؛ يلزم مع ذلك CI حديث للإصدار |
| جزئي `partial` | جزء عامل، لكن التكامل أو التغطية أو السلوك الموحد غير مكتمل |
| مخطط `planned` | نية/توصية فقط، ليست وعداً ولا يجوز عرضها كوظيفة حالية |
| غير مدعوم | خارج حدود الإصدار الحالي أو يحتاج إعادة تصميم |

## 2. الملفات العشرة

| الملف | الجمهور | المحتوى |
| --- | --- | --- |
| [SYSTEM_OVERVIEW_AR.md](SYSTEM_OVERVIEW_AR.md) | الجميع/المنتج | هدف النظام، الوحدات، الأدوار، الرحلات والحدود |
| [ARCHITECTURE_AR.md](ARCHITECTURE_AR.md) | المعماريون والمطورون | modular monolith، الطبقات، الطلب، الوقت، الحالة والتكاملات |
| [DATA_MODEL_AR.md](DATA_MODEL_AR.md) | المطورون/DBA | الجداول والنماذج والعلاقات والقيود والأرشفة والتزامن |
| [API_AND_ROUTES_AR.md](API_AND_ROUTES_AR.md) | Frontend/Backend/QA | routes الواجهة وHTTP، payloads، validation، الأخطاء والاستجابات |
| [SECURITY_AND_PERMISSIONS_AR.md](SECURITY_AND_PERMISSIONS_AR.md) | الأمن/المنتج/المطورون | المصادقة، matrix الصلاحيات، الملفات، الأسرار والتدقيق والمخاطر |
| [OPERATIONS_AND_RECOVERY_AR.md](OPERATIONS_AND_RECOVERY_AR.md) | التشغيل/الدعم | scheduler، النسخ `.pdesk` وlegacy، الاستعادة، الملفات، المراقبة وrunbooks |
| [TESTING_AND_QA_AR.md](TESTING_AND_QA_AR.md) | QA/المطورون/الإصدار | الجرد، الأوامر، CI، browser، gates، الفجوات والتشخيص |
| [DEVELOPER_GUIDE_AR.md](DEVELOPER_GUIDE_AR.md) | المطورون | الإعداد، بنية المستودع، إضافة ميزة، معاملات/وقت/ملفات/React |
| [DEPLOYMENT_AR.md](DEPLOYMENT_AR.md) | DevOps/التشغيل | build، env، Nginx، filesystem، النشر والترقية والرجوع وGo-Live |
| [DOCUMENTATION_INDEX_AR.md](DOCUMENTATION_INDEX_AR.md) | الجميع | الفهرس، أثر المتطلبات، الملكية وقواعد تحديث التوثيق |

## 3. مسارات قراءة مقترحة

| المهمة | اقرأ بالترتيب |
| --- | --- |
| فهم المنتج وحدوده | System Overview ثم Security ثم API |
| بدء تطوير | Developer Guide ثم Architecture ثم Data Model ثم Testing |
| بناء واجهة/تكامل داخلي | API and Routes ثم Security ثم Data Model |
| تدقيق أمني | Security ثم API ثم Operations ثم Deployment |
| نشر أول مرة | Deployment ثم Operations ثم Testing ثم Security |
| حادث أو استعادة | Operations and Recovery ثم Deployment ثم Data Model |
| قرار إضافة محاسبة | System Overview وحدود Sales ثم SRS؛ الوظيفة الحالية templates فقط |
| ترحيل لمساعد AI/فريق جديد | الملفات العشرة مع migrations/routes/config/tests وملف البيئة المثال دون أسرار |

## 4. خريطة التنفيذ والوثيقة الحاكمة

| المجال | الوضع المختصر | المرجع الأول |
| --- | --- | --- |
| مشاريع ومهام ممتدة بين بداية ونهاية | منفذ؛ جدول أسبوعي ببداية الأحد افتراضياً | API، Data Model، Architecture |
| عملاء وجهات اتصال وفريق | منفذ مع أدوار عامة وعضوية مشروع | API، Security |
| كراسة متطلبات وإصدارات | منفذ | Data Model، API، Operations للملفات |
| اجتماعات ومحاضر وجدولة | منفذ، وتنبيهات scheduler | API، Operations |
| مخاطر وقضايا ووثائق | منفذ | API، Data Model |
| ملفات خاصة وفحص malware | منفذ؛ production fail-closed مطلوب | Security، Operations |
| استيراد/تصدير CSV/XLSX | منفذ لموارد محددة ومعاملة ذرية | API، Operations |
| بحث داخلي | منفذ ومقيد بالصلاحيات | Architecture، Security، Developer Guide |
| قوالب فواتير وPDF | منفذ، غير محاسبي | System Overview، API، Developer Guide |
| proposal/receipt/letterhead legacy | مخزن تاريخياً لكنه مخفي/غير مسار منتج | Data Model، API |
| نسخة `.pdesk` كاملة مشفرة | منفذ لـSQLite/الملفات | Operations، Security |
| legacy DB backup | جزئي، database-only | Operations |
| إشعارات المهام والاجتماعات | منفذ عبر scheduler | Operations، Developer Guide |
| واجهة عربية/إنجليزية وRTL/LTR | منفذ؛ العربية افتراضية، والاختيار محفوظ في Cookie مشفرة، ولا يشمل ترجمة بيانات المستخدم أو PDF | System Overview، Architecture، SRS |
| queue/background Jobs مجال | غير منفذ؛ scaffold فقط | Architecture، Developer Guide |
| إعداد التقويم والمنطقة في كل النظام | جزئي؛ بعض المستهلكين يعتمدون config ثابتاً | Architecture، Testing |
| تعدد المضيفين/HA | غير مدعوم في v1 | Deployment، Architecture |

## 5. قرارات وحدود لا يجوز فقدها

1. **قوالب الفواتير ليست نظاماً محاسبياً.** لا توجد دفعات أو أرصدة أو دفتر أستاذ أو قيود أو ضرائب/تحصيل محاسبي.
2. v1 يستخدم SQLite وprivate local storage وأقفالاً محلية؛ النشر الأفقي غير آمن دون إعادة تصميم.
3. الواجهة عربية RTL افتراضياً وإنجليزية LTR اختيارياً؛ تحفظ اللغة سنة في Cookie
   مشفرة، وتستخدم الأسطح المربوطة تنسيق `ar-LY` أو `en-GB`. لا يترجم ذلك بيانات
   المستخدم أو مخرجات PDF العربية الحالية.
4. datetimes تخزن/تقارن UTC ويعرض الوقت التجاري افتراضياً `Africa/Tripoli`. الأسبوع يبدأ الأحد والعطلة الجمعة/السبت في builder الحالي.
5. إعداد calendar/timezone موجود لكنه لا يغذي كل الخدمات بالتساوي؛ الحالة `partial`.
6. لا توجد Jobs مخصصة أو outbox؛ العمليات الحالية متزامنة أو أوامر scheduler، وActivityLogger متزامن غالباً.
7. global `viewer` مع project pivot `manager` حالة غير موحدة: بعض تحديثات المشروع قد تسمح بها العضوية، بينما رفع الملفات يمنع viewer صراحة. هذه فجوة قرار/اختبار، لا قاعدة مستقرة.
8. التنزيل لا يسمح إلا لملف clean وبصلاحية صحيحة؛ production يجب أن يفشل مغلقاً إن لم يوجد ماسح.
9. restore خطير ومدير فقط مع recent password وعبارة ثابتة وchecksum وnonce وقفل وصيانة وrollback.

## 6. مصادر الشيفرة التي راجعتها الحزمة

| المصدر | ما تم استخراجه |
| --- | --- |
| `composer.json`, `package.json`, lockfiles | المنصة والإصدارات والأوامر |
| `bootstrap/app.php`, providers، `config/*`, `.env.example` | middleware، `/up`، adapters والحدود بلا قيم سرية |
| `database/migrations`, models/relations | المخطط والقيود والأرشفة/lock versions |
| `routes/*.php`, Controllers, Form Requests | routes وpayloads والاستجابات |
| Policies وServices | authorization والمعاملات والحسابات والنسخ/الملفات |
| Commands و`routes/console.php` | scheduler والعمليات الدورية |
| `resources/js/pages/components/hooks/layouts` و`resources/js/i18n` | صفحات Inertia والحالة، كتالوجا العربية/الإنجليزية، RTL/LTR، تنسيق اللغة وunsaved guard |
| Tests وCI | تغطية قابلة للتنفيذ وبوابات الجودة والفجوات |

الجرد في لحظة المراجعة: 25 model، 31 controller، 17 policy، 31 service، دون `app/Jobs`. أرقام الجرد أداة لاكتشاف الانجراف وليست contract دائم.

## 7. وثائق مرتبطة خارج الحزمة

توجد وثائق أخرى في `docs/` ويجب التعامل معها كمراجع داعمة والتحقق منها أمام الشيفرة:

- [دليل المستخدم العربي](../USER_MANUAL_AR.md)
- [حوكمة التوثيق](../DOCUMENTATION_GOVERNANCE_AR.md)
- [نطاق المنتج](../PRODUCT_SCOPE.md)
- [البيئة](../ENVIRONMENT.md)
- [المرفقات والاحتفاظ](../ATTACHMENTS_AND_RETENTION.md)
- [النسخ والاستعادة](../BACKUP_AND_RECOVERY.md)
- [جاهزية الإصدار](../RELEASE_READINESS.md)
- [تتبع المتطلبات](../REQUIREMENTS_TRACEABILITY.md)
- [خارطة الطريق](../ROADMAP.md)
- [Workflow Status API](../workflow-status-api.md)

تحذير: وثيقة architecture تاريخية قد تذكر `SaveTaskAction` أو outbox؛ التنفيذ الفعلي يستخدم `TaskService` ولا يملك outbox. لا تنقل الادعاء التاريخي إلى SRS أو تشغيل حالي.

## 8. قواعد تحديث الوثائق

حدث الوثائق في نفس pull request عند أي مما يلي:

| التغيير | الملفات الدنيا |
| --- | --- |
| route أو payload/error | API، Testing، وSRS/traceability |
| model/migration/relation | Data Model، Architecture، backup compatibility |
| Policy/role/membership | Security، API، اختبارات matrix ودليل المستخدم |
| ملف/MIME/scanner/reference | Security، Operations، Data Model، Testing |
| backup/restore/encryption | Operations، Security، Deployment واختبار drill |
| scheduler/Job/queue | Architecture، Operations، Deployment، Testing |
| timezone/week/calendar | Architecture، Developer، Testing ودليل المستخدم |
| لغة واجهة/كتالوج/اتجاه أو مبدل لغة | System Overview، Architecture، SRS/matrix، browser tests ودليل المستخدم |
| صفحة/رحلة UI | System Overview، API، browser tests ودليل المستخدم |
| نطاق invoice/sales | System Overview وAPI وSRS؛ حافظ على وسم غير محاسبي إلا بقرار منتج |
| متطلبات runtime/env | Developer، Deployment، Environment وCI |

كل تحديث يجب أن يسجل: السبب، الحالة قبل/بعد، migration/compatibility، الأثر الأمني/التشغيلي، الاختبارات، وقرار rollback. لا تضع secret example فعلياً.

## 9. فحص اتساق سريع

قبل نشر الحزمة أو تسليمها لمساعد AI آخر:

- [ ] الملفات العشرة موجودة وروابطها تعمل.
- [ ] route list وmigrations وPolicies تطابق جداول API/data/security.
- [ ] لا ادعاء عن outbox/Jobs/HA/CSP عامة غير موجودة.
- [ ] invoice موصوف كقالب غير محاسبي في كل موضع.
- [ ] `.pdesk` وlegacy database-only والفحص fail-closed موثقة بلا مفاتيح.
- [ ] UTC وTripoli والأحد والجمعة/السبت وحدود التكامل الجزئي ظاهرة.
- [ ] العربية الافتراضية والإنجليزية وCookie اللغة واتجاها RTL/LTR موثقة، مع
      استبعاد صريح لترجمة بيانات المستخدم وPDF.
- [ ] أوامر setup/test/deploy لا تحتوي credential ولا أمر مسح إنتاج.
- [ ] نتائج الاختبار مرتبطة بcommit وتشغيل حديث، لا برقم قديم فقط.
- [ ] diagrams إن وجدت يسبقها/يتبعها وصف نصي كامل ولا يعتمد الفهم عليها.
- [ ] الترميز UTF-8 والعربية وMarkdown tables/code fences سليمة.

## 10. سجل التغيير

| التاريخ | النطاق | الملاحظة |
| --- | --- | --- |
| 2026-08-14 | توطين الواجهة | مزامنة العربية الافتراضية والإنجليزية وCookie اللغة وRTL/LTR والتنسيق المحلي، مع توثيق حدود بيانات المستخدم وPDF |
| 2026-08-12 | إنشاء الحزمة التقنية العربية | توثيق code-first للتنفيذ الحالي مع تمييز المنفذ/الجزئي/المخطط والحدود |
