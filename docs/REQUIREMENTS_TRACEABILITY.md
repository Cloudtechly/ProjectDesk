# مصفوفة تتبع متطلبات Project Desk

> الحالة: لقطة تحقق تنفيذ الإصدار الأول  
> آخر تحديث: 12 أغسطس 2026  
> النطاق الحاكم: [PRODUCT_SCOPE.md](PRODUCT_SCOPE.md)  
> المعمارية: [ARCHITECTURE.md](ARCHITECTURE.md)  
> بوابات التشغيل: [RELEASE_READINESS.md](RELEASE_READINESS.md)

## 1. الغرض

تربط هذه الوثيقة قرارات المنتج بالـEpics والكيانات ومعايير القبول وشرائح التسليم. وهي المرجع المستخدم لإنشاء backlog ومنع سقوط متطلب أثناء الصقل أو إعادة تنظيم الملاحة.

## 2. دلالات الحالة والأولوية

### حالة التنفيذ

- **منفذ:** التدفق الأساسي محفوظ في قاعدة البيانات، محمي بسياسات الخادم، وله اختبارات آلية مباشرة تغطي معايير القبول البرمجية الرئيسية.
- **منفذ جزئياً:** توجد قدرة تشغيلية حقيقية واختبارات لها، لكن واحداً أو أكثر من معايير القبول البرمجية ما زال بلا تنفيذ داخل المستودع.
- **مؤجل:** قرار معلن خارج لقطة الإصدار الحالية؛ لا يجوز عرضه كقدرة مكتملة أو الاستناد إلى الـWireframe كدليل تنفيذ.

الحالة تصف التنفيذ القابل للتحقق في المستودع بتاريخ هذه الوثيقة. اختيار حالة
«منفذ» لا يعني أن النشر الإنتاجي أو التكامل الحقيقي أو الاختبارات التشغيلية
الخارجية قد أُنجزت؛ تلك شروط مستقلة موثقة في
[RELEASE_READINESS.md](RELEASE_READINESS.md).

### الأولوية

- **حرجة:** أساس أمن/نزاهة أو حاجب لمسارات أخرى.
- **عالية:** قدرة يومية أساسية ضمن v1.
- **متوسطة:** ضمن v1، ويمكن تسليمها بعد تثبيت النواة.

كل صف في هذه الوثيقة داخل نطاق v1؛ اختلاف الأولوية يحدد التسلسل لا الحذف.

## 3. تتبع قرارات المنتج

| القرار | النص المعتمد | ينعكس في | تحقق القبول |
|---|---|---|---|
| PD-001 | التطبيق Web | EP-00، EP-18 | AC-PLAT-01، AC-RESP-01 |
| PD-002 | شركة واحدة في v1 | EP-00، EP-01 | AC-PLAT-02، AC-AUTH-02 |
| PD-003 | استخدام داخلي فقط | EP-01 | AC-AUTH-01، AC-AUTH-03 |
| PD-004 | قوالب الفواتير ضمن النطاق، بلا نظام محاسبي | EP-16 | AC-TEMPLATE-01…AC-TEMPLATE-08 |
| PD-005 | بداية ونهاية إلزاميتان لكل مهمة | EP-07، EP-08 | AC-TASK-04، AC-TASK-05، AC-SCHED-02 |
| PD-006 | المشروع ينشأ بلا مهام | EP-04 | AC-PROJ-02 |
| PD-007 | المسؤول اختياري ووقت الإسناد مستقل | EP-07 | AC-TASK-02، AC-TASK-03، AC-TASK-06 |
| PD-008 | الأرشفة بدلاً من الحذف | EP-02، EP-03، EP-04 | AC-ARCH-01 |
| PD-009 | العربية وRTL وهوية CloudTech | EP-18 | AC-A11Y-01، AC-BRAND-01 |

## 4. مصفوفة الـEpics

| Epic | الوحدة/النتيجة | أهم الكيانات | صفحات/تفاعلات | معايير القبول | الأولوية | حالة التنفيذ | الشريحة |
|---|---|---|---|---|---|---|---|
| EP-00 | منصة Web داخلية لشركة واحدة | company_settings، environment config | shell، navigation، session | AC-PLAT-01…02 | حرجة | منفذ | VS-0 |
| EP-01 | الهوية والمصادقة والصلاحيات | users، roles/permissions | login، security، users admin | AC-AUTH-01…04، AC-PERM-01…02 | حرجة | منفذ | VS-0/1 |
| EP-02 | العملاء وجهات الاتصال | clients، contacts | قائمة، تفاصيل، إضافة/تعديل/أرشفة | AC-CLIENT-01…03 | عالية | منفذ | VS-1 |
| EP-03 | الفريق وعضوية المشاريع | users، project_members | دليل الفريق، بطاقة العضو، إدارة العضوية | AC-TEAM-01…03، AC-ARCH-01 | عالية | منفذ | VS-1 |
| EP-04 | إدارة المشاريع | projects، project_members | قائمة، wizard، مساحة المشروع | AC-PROJ-01…05 | عالية | منفذ | VS-1 |
| EP-05 | المتطلبات | requirements، task_requirement | قائمة/محرر، روابط المهمة | AC-REQ-01…03 | عالية | منفذ | VS-2 |
| EP-06 | كراسة المتطلبات وإصداراتها | requirement_books، requirement_book_versions | بطاقة الإصدار الحالي، رفع إصدار، السجل | AC-BOOK-01…03 | متوسطة | منفذ | VS-3 |
| EP-07 | المهام والإسناد وطرق العمل | tasks، assignment_events | quick add، drawer/editor، list، Kanban | AC-TASK-01…09، AC-WORK-01…03 | عالية | منفذ | VS-1/2 |
| EP-08 | الجدول الأسبوعي والزمن | timeline_entries + قراءة tasks | أسبوع مختار، أشرطة، timeline المشروع | AC-SCHED-01…08، AC-TIME-01 | عالية | منفذ | VS-3 |
| EP-09 | الاجتماعات والمحاضر | meetings، attendees، minutes | جدولة، بطاقة اجتماع، محضر | AC-MEET-01…04 | متوسطة | منفذ | VS-3 |
| EP-10 | المخاطر والمشكلات | risks، issues | CRUD، قوائم، استثناءات dashboard | AC-GOV-01…03 | عالية | منفذ | VS-4 |
| EP-11 | الملفات والوثائق | file_objects، attachment_links | upload، ربط هدف، download، archive/restore | AC-FILE-01…05 | حرجة | منفذ | VS-5 |
| EP-12 | لوحة المتابعة والتحليلات | read models مشتقة | KPIs، تدخلات، charts، drill-down | AC-DASH-01…05 | عالية | منفذ | VS-4 |
| EP-13 | البحث والتنبيهات | notifications، search queries | global search، notification center | AC-SEARCH-01…02، AC-NOTIFY-01…03 | متوسطة | منفذ | VS-4 |
| EP-14 | مركز البيانات والتعافي | data_jobs/errors، file_objects، backups/restores | import wizard، export، backup/restore | AC-IMPORT-01…04، AC-EXPORT-01…02، AC-BACKUP-01…04 | حرجة | منفذ | VS-6 |
| EP-15 | الحالات والإعدادات | workflow_statuses، notification/backup/company settings | settings، reorder، colors | AC-SET-01…04 | عالية | منفذ | VS-2/6 |
| EP-16 | قوالب الفواتير | invoice templates، line_items | مكتبة، محرر، معاينة، نسخ، أرشفة/استعادة، PDF | AC-TEMPLATE-01…08 | عالية | منفذ | VS-5 |
| EP-17 | التدقيق والتزامن | activity_logs، lock_version | activity tab، conflict UX | AC-AUDIT-01…03، AC-CONCUR-01 | حرجة | منفذ | VS-0 وكل الشرائح |
| EP-18 | الهوية البصرية والوصولية والأداء | design tokens، لا كيان مجال | RTL، responsive، keyboard، loading/error | AC-A11Y-01…05، AC-RESP-01، AC-PERF-01…03، AC-BRAND-01 | حرجة | منفذ | كل الشرائح |

### 4.1 أدلة المستودع وبوابات التشغيل

وجود ملف اختبار لا يغلق Epic تلقائياً؛ يجب أن يطابق السلوك الفعلي ومعيار القبول.
المسارات أدناه نسبية إلى جذر المشروع، والعمود الأخير يفصل ما يحتاج بيئة أو جهة
خارجية عن اكتمال الكود.

| النطاق | دليل المستودع القابل للتحقق | حكم المستودع | بوابة تشغيل خارجية |
|---|---|---|---|
| EP-00 وEP-01 | `tests/Feature/Auth/*`، `tests/Feature/ProvisionAdminCommandTest.php`، `tests/Feature/SecurityActivityAuditTest.php`، `app/Listeners/SecurityActivitySubscriber.php` واختبارات منع الوصول المباشر | المصادقة والصلاحيات وتدقيق login/logout/failure و2FA/Passkey دون credential material منفذة. | TLS وخصائص Session Cookie ومراقبة السجلات على المضيف الفعلي. |
| EP-02…EP-04 | `tests/Feature/ClientContactCrudTest.php`، `tests/Feature/TeamWorkflowTest.php`، `tests/Feature/ProjectWorkflowTest.php`، `tests/Browser/critical-journey.mjs` | العميل→جهة اتصال→مشروع بلا مهام→أول مهمة له تدفق متكامل، مع الأرشفة والصلاحيات. | إعادة تشغيل الرحلة على SHA المرشح وبيئة staging. |
| EP-05…EP-07 | `tests/Feature/GovernanceResourcesTest.php`، `tests/Feature/TaskWorkflowTest.php`، `tests/Feature/ProjectDocumentWorkflowTest.php`، `tests/Browser/ui-task-regressions.mjs` | المتطلبات والمهام والإسناد والقائمة وكانبان والربط ضمن المشروع منفذة. | قياس الاستخدام الفعلي ضمن الـpilot. |
| EP-08 وEP-12 | `tests/Unit/WeeklyScheduleBuilderTest.php`، `tests/Unit/ProjectMetricsTest.php`، `tests/Feature/ProjectMetricsIntegrationTest.php`، `tests/Feature/DashboardTest.php`، `tests/Feature/DashboardDrilldownTest.php` | الأسبوع المختار وأشرطة البداية/النهاية والـKPIs والصحة والمرحلة التالية تستند إلى مصادر حساب موحدة؛ لا تُنشأ trends بلا بيانات. | قياس INP على بيانات وجهاز ممثلين. |
| EP-09 وEP-10 | `tests/Feature/TimelineMeetingWorkflowTest.php`، `tests/Feature/GovernanceResourcesTest.php`، `tests/Browser/governance-smoke.mjs` | CRUD والأرشفة/الاستعادة والمحاضر والمرفقات وتعارض `lock_version` للاجتماعات/المحاضر والمراحل والمخاطر والمشكلات منفذة. | إعادة رحلة الحوكمة على مرشح الإصدار. |
| EP-11 | `tests/Feature/ProjectDocumentWorkflowTest.php`، `tests/Feature/ProjectFileSecurityTest.php`، `tests/Feature/ProjectFileTargetLinkTest.php`، `tests/Unit/CommandMalwareScannerTest.php`، `tests/Feature/OrphanedFileRetentionTest.php` | التخزين الخاص وربط المشروع/المهمة/المتطلب، والتحقق والصلاحيات والأرشفة/الاستعادة وعقد scanner fail-closed والاحتفاظ الآمن منفذة. | تهيئة ماسح حقيقي وتجربة clean/detected/outage في staging. |
| EP-13 | `tests/Feature/GlobalSearchTest.php`، `tests/Feature/NotificationCenterTest.php`، `tests/Browser/notifications-smoke.mjs` | البحث مقيد بالصلاحيات، والتنبيهات دائمة ومزامنتها idempotent وتحدث `read_at`. | إثبات تشغيل scheduler ومراقبته في بيئة النشر. |
| EP-14 | `tests/Feature/DataCenterCsvTest.php`، `tests/Feature/DataCenterXlsxTest.php`، `tests/Feature/PdfExportAuthorizationTest.php`، `tests/Feature/SqliteBackupControllerTest.php`، `tests/Feature/SqliteBackupRestoreIntegrationTest.php`، `tests/Feature/RestoreWriteFenceTest.php`، `tests/Feature/AutomaticBackupCommandTest.php` | Excel/CSV/PDF حقيقية ومصرح بها؛ الاستيراد ذري، وحزمة `.pdesk` مشفرة وكاملة وتتحقق من القاعدة والملفات، مع safety backup وسياج كتابة وWAL/SHM rollback. | نسخ off-host وتمرين استعادة معزول يثبت RPO/RTO. |
| EP-15 وEP-16 | `tests/Feature/SystemSettingsTest.php`، `tests/Feature/WorkflowStatusSettingsTest.php`، اختبارات قالب الفاتورة والصلاحيات، `tests/Unit/SalesCalculatorTest.php`، `tests/Browser/sales-smoke.mjs` | إعدادات الشركة والحالات والتنبيهات والنسخ، وقالب الفاتورة وحساب المستند وPDF منفذة؛ لا توجد دورة تحصيل أو حالات دفع. | معايرة العرض/الطباعة على بيئة المستخدم المستهدفة. |
| EP-17 | اختبارات `lock_version` في `ProjectWorkflowTest.php` و`TaskWorkflowTest.php` و`ProjectDocumentWorkflowTest.php` و`SalesDocumentWorkflowTest.php` و`GovernanceResourcesTest.php` و`TimelineMeetingWorkflowTest.php`، واختبارات `activity_logs` | التعارض محمي في كيانات المجال القابلة للتحرير. سجل النشاط يكتب داخل معاملة المجال نفسها، فلا يظهر إلا عند commit ويلغى مع rollback؛ هذا اتساق ذري مناسب لـv1 أحادي قاعدة البيانات ولا يحتاج outbox لإثبات AC-AUDIT-01. | اختبار التعارض ضمن رحلة staging متعددة الجلسات يبقى دليل تشغيل إضافياً لا فجوة كود. |
| EP-18 | `tests/Feature/PerformanceVolumeTest.php`، اختبار 1000 صف في `DataCenterCsvTest.php`، `tests/Browser/accessibility-responsive-smoke.mjs`، `tests/Browser/unsaved-dialogs-smoke.mjs`، وبوابات format/lint/types/build | ضوابط RTL/Responsive/keyboard/focus/unsaved dialogs والأحجام المستهدفة ممثلة في الكود والاختبارات الآلية. | قارئ شاشة وتكبير 200% يدوياً، وقياس p75 INP وSUS/نجاح السيناريوهات في pilot؛ لا تدعى مطابقة WCAG كاملة قبلها. |

### 4.2 أوامر التحقق المرجعية

```bash
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
composer test
pnpm run format:check
pnpm run lint:check
pnpm run types:check
pnpm run build
pnpm run browser:check
```

اختبارات المتصفح تشغّل على تطبيق محلي مهيأ؛ لا تحتسب ناجحة لمجرد وجود ملفاتها
في المستودع. سجلت البوابة المحلية النهائية 261 اختبار PHP و2841 تحققاً، وصفر
أخطاء PHPStan، و10/10 رحلات متصفح، وبناء 2305 modules؛ تفاصيلها وبصمة manifest
في [RELEASE_READINESS.md](RELEASE_READINESS.md). لا يوجد commit baseline بعد،
ولذلك يجب إعادة البوابة على SHA المثبت نفسه قبل تصريح الإنتاج.

## 5. معايير القبول التفصيلية

### 5.1 المنصة والهوية

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-PLAT-01 | المستخدم يملك متصفحاً مدعوماً | يفتح رابط النظام | تظهر تجربة Web كاملة دون حاجة لتثبيت Desktop client | E2E |
| AC-PLAT-02 | قاعدة بيانات v1 | تنشأ السجلات | لا يوجد tenant selector أو وصول لمؤسسة ثانية، وتطبق إعدادات CloudTech الواحدة | Feature/Schema |
| AC-AUTH-01 | مستخدم داخلي نشط | يسجل الدخول ببيانات صحيحة | تنشأ جلسة آمنة وينتقل إلى لوحة المتابعة | Feature/E2E |
| AC-AUTH-02 | مستخدم غير مفوض | يطلب مورداً بمعرف مباشر | يعاد 403/404 مناسب ولا تسرب بيانات المورد | Feature/Security |
| AC-AUTH-03 | زائر أو عميل غير داخلي | يطلب صفحة محمية | لا يرى البيانات ويعاد إلى المصادقة؛ لا توجد بوابة عميل | Feature |
| AC-AUTH-04 | مستخدم يغير كلمة المرور أو 2FA/Passkey | يحفظ الإعداد | تطبق Fortify والسياسة وتدقق العملية الحساسة | Feature/Security |
| AC-PERM-01 | كل دور من Admin/PM/Member/Viewer | ينفذ عمليات كل كيان | تنجح المسموحة وترفض غير المسموحة على الخادم | Policy matrix |
| AC-PERM-02 | عضو خارج المشروع | يبحث أو يصدر أو يطلب ملف المشروع | لا يظهر السجل ولا يمكن تنزيله | Integration/Security |

### 5.2 العملاء والفريق والمشاريع

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-CLIENT-01 | مدير مخول وبيانات عميل صحيحة | ينشئ العميل | يحفظ الكود والاسم والتواصل وتظهر التفاصيل | Feature/E2E |
| AC-CLIENT-02 | عميل موجود | يضيف جهات اتصال متعددة | تحفظ كل جهة منفصلة ويمكن تحديد الرئيسية | Feature |
| AC-CLIENT-03 | عميل له مشاريع | يفتح تفاصيله | تظهر مشاريعه ومستنداته المصرح بها دون بيانات غير مصرح بها | Feature |
| AC-TEAM-01 | عضو فريق | تفتح بطاقته | تظهر الوظيفة والتواصل والحالة والمشاريع والمهام الحالية | Feature |
| AC-TEAM-02 | مدير مشروع | يضيف عضواً للمشروع بدور | تنشأ عضوية فريدة وتطبق صلاحيات الدور | Feature/Policy |
| AC-TEAM-03 | عضو مؤرشف | يفتح نموذج إسناد جديد | لا يظهر كخيار جديد، ويبقى اسمه في التاريخ | Feature |
| AC-ARCH-01 | سجل مهم ذو علاقات | يؤرشفه مستخدم مخول | يختفي من الاختيارات النشطة ولا تحذف العلاقات أو audit history | Feature |
| AC-PROJ-01 | مدير مشروع وبيانات صحيحة | يكمل خطوات الإنشاء | يحفظ المشروع والعميل والاتصال والمدير والفريق والجدول والحالة | E2E |
| AC-PROJ-02 | لا توجد مهام | يحفظ المشروع | ينجح الحفظ وتظهر «إضافة أول مهمة» | Feature/E2E |
| AC-PROJ-03 | عدة مشاريع | يبحث أو يفلتر أو يفرز | تظهر نتائج وعدد صحيحان ويحفظ النطاق في URL | E2E |
| AC-PROJ-04 | مشروع موجود | يفتح مساحته | تظهر جميع أقسام إدارة المشروع والعميل والنشاط؛ قالب الفاتورة اختياري ولا يعد قسماً محاسبياً | E2E |
| AC-PROJ-05 | بيانات مهام/زمن حقيقية | تعرض قائمة المشاريع | تحسب قيمة التقدم والصحة والمرحلة القادمة وفق معادلة موثقة قابلة للتتبع | Unit/Feature |

### 5.3 المتطلبات والمهام

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-REQ-01 | مشروع مخول | ينشئ المستخدم متطلباً | يحفظ العنوان والوصف ومعايير القبول والأولوية والحالة | Feature |
| AC-REQ-02 | متطلب ومهام في المشروع نفسه | يربطها المستخدم | تحفظ علاقة M:N دون تكرار وتظهر من الطرفين | Feature |
| AC-REQ-03 | مهمة من مشروع آخر | يحاول ربطها بمتطلب | يرفض الخادم العلاقة العابرة للمشروع | Validation/Security |
| AC-BOOK-01 | مشروع بلا كراسة | يرفع الإصدار 1.0 بملف صالح | تنشأ كراسة وإصدار حالي واحد | Integration |
| AC-BOOK-02 | كراسة ذات إصدار حالي | يرفع إصداراً أحدث فريداً | يبقى القديم في السجل ويصبح الجديد الحالي ذرياً | Feature |
| AC-BOOK-03 | رقم إصدار مستخدم | يحاول رفعه ثانية | يرفض الحفظ ولا يتكون ملف يتيم | Validation/Integration |
| AC-TASK-01 | أي صفحة داخل النظام | يختار المستخدم الإضافة السريعة | يفتح نموذج المهمة مع المشروع السياقي إن وجد | E2E |
| AC-TASK-02 | مهمة بلا مسؤول | يحفظها المستخدم | تنجح و`assignee_id` و`assigned_at` فارغان وتظهر غير معيّنة | Feature |
| AC-TASK-03 | اختير مسؤول | يفتح أو يغير حقل المسؤول | يملأ وقت الإسناد تلقائياً ويمكن تعديله قبل الحفظ | Component/E2E |
| AC-TASK-04 | مهمة جديدة أو معدلة | يرسلها دون بداية أو نهاية | يرفض الخادم والواجهة الحفظ برسالة مرتبطة بالحقل | Feature/E2E |
| AC-TASK-05 | نهاية أقدم من البداية | يحفظ المستخدم | يرفض الحفظ؛ وإذا كانتا في اليوم نفسه يقبل الترتيب الزمني الصحيح | Unit/Feature |
| AC-TASK-06 | مهمة مجدولة | يعاد إسنادها | لا تتغير البداية أو النهاية | Unit/Feature |
| AC-TASK-07 | تغير المسؤول ثم حفظ | تكتمل المعاملة | يسجل from/to/assignedAt/recordedAt/actor/note مرة واحدة | Feature |
| AC-TASK-08 | تغير المسؤول في الواجهة ثم ألغي | يغادر دون حفظ | لا يسجل AssignmentEvent | E2E |
| AC-TASK-09 | أزيل المسؤول ثم حفظ | تكتمل المعاملة | يسجل الحدث إلى غير معيّن ويصبح assignedAt فارغاً في المهمة الحالية | Feature |
| AC-WORK-01 | مهمة موجودة | تتغير حالتها في القائمة أو كانبان | تتحدث كل طرق العرض وتقدم المشروع من المصدر نفسه | E2E |
| AC-WORK-02 | مستخدم لا يستطيع السحب | يستخدم قائمة الحالة بالكيبورد | ينفذ تغيير الحالة نفسه | A11y/E2E |
| AC-WORK-03 | قائمة المهام | تعرض السجلات | تظهر المهمة والمشروع والمسؤول ووقت الإسناد والبداية والنهاية والحالة والأولوية | E2E |

### 5.4 الزمن والاجتماعات والحوكمة

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-TIME-01 | أوقات مخزنة UTC | يعرضها مستخدم بتوقيت طرابلس | تظهر التواريخ والأيام المحلية الصحيحة دون انزياح | Unit/E2E |
| AC-SCHED-01 | فتح لوحة التخطيط | لا يحدد المستخدم تاريخاً | يظهر الأسبوع المحلي الحالي من الأحد إلى السبت | Unit/E2E |
| AC-SCHED-02 | مهمة ذات بداية ونهاية | يقع جزء منها في الأسبوع | يمتد شريطها عبر كل الأيام المشمولة | Visual/E2E |
| AC-SCHED-03 | مهمة في يوم واحد | تعرض في الأسبوع | تشغل عرض خلية يوم كامل لا صفراً | Visual |
| AC-SCHED-04 | مهمة تبدأ قبل الأسبوع أو تنتهي بعده | تعرض | تقص مع مؤشر استمرار واسم وصولي يحفظ النطاق الكامل | Visual/A11y |
| AC-SCHED-05 | مهام متداخلة في مشروع | تعرض | توضع في lanes مستقلة ولا تتراكب | Unit/Visual |
| AC-SCHED-06 | مشروع بلا مهام | يعرض الجدول | يبقى صفه بحالة فارغة سليمة | E2E |
| AC-SCHED-07 | عرض 768px | يمرر المستخدم الجدول | التمرير داخل المنطقة فقط وعمود المشروع ثابت | Responsive |
| AC-SCHED-08 | مستخدم كيبورد | يركز شريط مهمة | يفتح المهمة ويحصل على اسم ووصف تاريخي كامل | A11y/E2E |
| AC-MEET-01 | مشروع مخول | يجدول اجتماعاً بوقت صحيح | يحفظ ويظهر في timeline والأسبوع من المصدر نفسه | Feature/E2E |
| AC-MEET-02 | نهاية اجتماع لا تتبع البداية | يحفظ | يرفض الحفظ برسالة واضحة | Validation |
| AC-MEET-03 | اجتماع موجود | يحفظ محضراً بملخص | يرتبط محضر واحد بالاجتماع ويظهر في وثائق المشروع | Feature |
| AC-MEET-04 | محضر يحوي ملفاً | يفتحه مستخدم مخول | يخضع الملف لصلاحية المشروع ولا يمكن الوصول إليه خارجها | Security |
| AC-GOV-01 | مدير مشروع | ينشئ أو يحدث مخاطرة | تحفظ الاحتمالية والأثر والمالك والمعالجة والحالة والموعد | Feature |
| AC-GOV-02 | مدير مشروع | ينشئ أو يحل مشكلة | تحفظ الشدة والمالك والحالة والموعد والحل | Feature |
| AC-GOV-03 | مخاطرة/مشكلة تستدعي تدخلاً | تضغط من dashboard | تفتح القائمة المفلترة التي تفسر المؤشر | E2E |

### 5.5 الوثائق واللوحة والبحث

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-FILE-01 | ملف بامتداد/MIME/حجم غير مسموح | يرفع | يرفض قبل الإتاحة ويسجل السبب دون تنفيذ الملف | Security/Integration |
| AC-FILE-02 | ملف مسموح | يرفع | يخزن باسم آمن خاص وchecksum وحالة فحص | Integration |
| AC-FILE-03 | ملف لم يجتز الفحص | يطلب تنزيله | يمنع التنزيل حتى SAFE | Security |
| AC-FILE-04 | مستخدم غير مصرح يعرف ID | يطلب التنزيل | يرفض ولا يكشف metadata حساسة | Security |
| AC-FILE-05 | رفع/تنزيل/أرشفة ناجح | تكتمل العملية | يسجل Audit actor/time/target | Integration |
| AC-DASH-01 | بيانات عمل حالية | تفتح اللوحة | تعرض المشاريع النشطة والتقدم والمتأخر والقريب والزمن والحوكمة | Feature/E2E |
| AC-DASH-02 | KPI أو قطاع رسم | يفعله المستخدم | تفتح قائمة مصرح بها بفلتر يفسر الرقم ومصدر قابل للمسح | E2E |
| AC-DASH-03 | لا توجد بيانات تاريخية | تعرض الرسوم | لا يظهر trend مختلق | Unit/Review |
| AC-DASH-04 | رسم تفاعلي | يستخدمه مستخدم كيبورد أو دون تمييز لون | يبقى النص والعدد والنسبة والإجراء متاحين | A11y |
| AC-DASH-05 | بيانات من مشاريع غير مصرح بها | يفتح عضو اللوحة | لا تدخل في الأرقام أو drill-down | Security/Feature |
| AC-SEARCH-01 | عبارة تطابق مشروعاً/مهمة/عميلاً/عضواً | يبحث المستخدم | تظهر نتائج مصنفة ويمكن فتحها بالكيبورد | E2E |
| AC-SEARCH-02 | عبارة تطابق سجلاً غير مصرح | يبحث المستخدم | لا يظهر السجل أو عدد يكشف وجوده | Security |
| AC-NOTIFY-01 | موعد يطابق سياسة التنبيه | يعمل scheduler | ينشأ تنبيه واحد للمستخدمين المقصودين | Integration |
| AC-NOTIFY-02 | يفتح المستخدم التنبيه | يفعله | ينتقل للسجل الصحيح وتحدث read_at | E2E |
| AC-NOTIFY-03 | ألغيت الجدولة/الاجتماع | تعالج الأحداث | يلغى التنبيه غير الصالح ولا يرسل إشعاراً قديماً | Integration |

### 5.6 قوالب الفواتير

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-TEMPLATE-01 | مستخدم مخول | ينشئ قالب فاتورة دون عميل أو مشروع | يحفظ قالباً مستقلاً من نوع الفاتورة فقط | Feature/E2E |
| AC-TEMPLATE-02 | اختير عميل ومشروع كبيانات معاينة | يختلف عميل المشروع | يرفض الخادم الربط غير المتسق | Validation |
| AC-TEMPLATE-03 | بنود وخصم وضريبة صالحون | يحفظ القالب | يحسب subtotal ثم الخصم ثم الضريبة بدقة decimal للقالب الواحد | Unit/Feature |
| AC-TEMPLATE-04 | خصم/ضريبة خارج 0–100 أو كمية/سعر سالب | يحفظ | يرفض الحفظ برسائل واضحة | Validation |
| AC-TEMPLATE-05 | قالب موجود | ينشئ المستخدم نسخة | ينشأ قالب مستقل ولا تنشأ مطالبة أو علاقة دفع | Feature |
| AC-TEMPLATE-06 | قالب موجود | يؤرشفه ثم يستعيده | يختفي افتراضياً ثم يعود دون حذف أو فقد البنود | Feature/E2E |
| AC-TEMPLATE-07 | مسودة معدلة | يحاول المستخدم المغادرة | تظهر حراسة ويمكن البقاء أو التجاهل صراحة | E2E |
| AC-TEMPLATE-08 | قالب صالح ومصرح | يطلب PDF | يولد ملف A4 بعلامة «نموذج/معاينة» وهوية CloudTech ويخضع للتدقيق والصلاحية | Integration/E2E |

### 5.7 البيانات والإعدادات والتعافي

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-IMPORT-01 | قالب صحيح | يرفع ويفحص | تظهر معاينة وعدد صفوف دون كتابة بيانات عمل | Integration |
| AC-IMPORT-02 | خطأ في الملف | يكتمل الفحص | يظهر sheet/row/field/code/message ويعطل commit | Integration/E2E |
| AC-IMPORT-03 | أي خطأ مانع | يحاول commit | لا يكتب أي صف | Transaction test |
| AC-IMPORT-04 | ملف صالح | ينفذ Admin commit | تنشأ السجلات ذرياً ويظهر تقرير النتيجة وAudit | Integration |
| AC-EXPORT-01 | قائمة مفلترة | يطلب المستخدم Excel | يحتوي الملف السجلات المصرح بها والنطاق نفسه ويمنع formula injection | Integration |
| AC-EXPORT-02 | مشروع مخول | يطلب PDF summary | ينتج ملخصاً من البيانات الحالية مع وقت النطاق | Integration |
| AC-BACKUP-01 | Admin | ينشئ backup | تشمل قاعدة البيانات والملفات مع checksum وتشفير وحالة | Recovery test |
| AC-BACKUP-02 | نسخة تالفة/غير متوافقة | يطلب استعادتها | يرفض قبل الاستبدال ويعرض السبب | Recovery test |
| AC-BACKUP-03 | نسخة صحيحة | يؤكد Admin الاستعادة | ينشأ safety backup ثم تتم الاستعادة مع Audit | Recovery drill |
| AC-BACKUP-04 | بيئة إطلاق | ينفذ restore drill | ينجح ضمن RPO≤24h وRTO≤4h ويوثق | Operational |
| AC-SET-01 | Admin | يغير اسم/لون/ترتيب حالة | تنعكس على القوائم وكانبان بعد الحفظ دون تغيير code الثابت | Feature/E2E |
| AC-SET-02 | حالة مستخدمة | يحاول حذفها | يمنع الحذف الفيزيائي ويوفر التعطيل/الترحيل الآمن | Feature |
| AC-SET-03 | Admin | يعدل سياسة التنبيه أو النسخ | تحفظ وتطبق في jobs اللاحقة | Integration |
| AC-SET-04 | Admin | يعدل ملف الشركة والترقيم | تستخدمه المستندات الجديدة دون تغيير تاريخ المستندات القديمة | Feature |

### 5.8 التدقيق والجودة

| AC | Given | When | Then | نوع الاختبار |
|---|---|---|---|---|
| AC-AUDIT-01 | عملية إنشاء/تعديل/أرشفة/حالة/ملف/بيانات | تكتمل المعاملة | يكتب actor/time/entity/project/before/after/correlation داخل معاملة المجال نفسها؛ لا يظهر السجل إلا عند commit ويلغى مع rollback | Integration |
| AC-AUDIT-02 | مستخدم عادي | يحاول تعديل audit entry | يرفض | Security |
| AC-AUDIT-03 | مشروع مخول | يفتح تبويب النشاط | يرى أحداث المشروع فقط بترتيب زمني وpagination | Feature/E2E |
| AC-CONCUR-01 | مستخدمان يعدلان النسخة نفسها | يحفظ الثاني بعد الأول | يكتشف التعارض ولا يكتب فوق التعديل الأحدث بصمت | Feature |
| AC-A11Y-01 | أي صفحة أساسية | تستخدم بالكيبورد فقط | يمكن الوصول لكل إجراء مع focus واضح وترتيب منطقي | Manual/E2E |
| AC-A11Y-02 | حوار مفتوح | يستخدم Tab/Escape ثم يغلق | يحبس focus ويغلق ويعيده للمستدعي | Component/E2E |
| AC-A11Y-03 | مستخدم reduced motion أو دون إدراك اللون | يستخدم النظام | يبقى المعنى والتفاعل متاحين | CSS/Manual |
| AC-A11Y-04 | نص عربي مع هاتف/بريد/كود/تاريخ | يعرض | يظل ترتيب Bidi مقروءاً وصحيحاً | Visual |
| AC-A11Y-05 | تكبير 200% وقارئ شاشة | تنفذ الرحلات الحرجة | تمر WCAG 2.2 AA دون حواجز حرجة | Audit |
| AC-RESP-01 | 1440×900 أو 1024 أو 768 | تعرض الصفحات | لا تمرير أفقي للصفحة؛ جدول الأسبوع فقط داخل scroller مقصود | Visual/E2E |
| AC-BRAND-01 | أي صفحة أو PDF | يعرض | يستخدم هوية CloudTech وتبايناً صحيحاً ولا يستخدم التركوازي كنص صغير على فاتح | Visual review |
| AC-PERF-01 | حمل معتاد | يتفاعل المستخدم | p75 INP ≤200ms | Performance |
| AC-PERF-02 | حجم البحث المستهدف | يبحث المستخدم | p95 ≤500ms | Performance |
| AC-PERF-03 | 1,000 و10,000 مهمة | تفتح القائمة/كانبان/الأسبوع | تبقى قابلة للاستخدام عبر pagination/virtualization | Load/E2E |

## 6. تتبع البيانات الوهمية

| العنصر في الـWireframe | التصنيف | قاعدة الانتقال للإنتاج |
|---|---|---|
| أسماء وأكواد ومشاريع وعملاء وأعضاء ومهام | Demo | تستبدل بFactories آمنة ثم بيانات تشغيلية عبر واجهات مصرح بها |
| تاريخ 11 أغسطس 2026 | Demo ثابت | يستخدم Clock قابل للاختبار ووقت النظام الحقيقي |
| نسب التقدم والصحة | اختيار Prototype | تعتمد معادلة موثقة واختبارات ومصدر بيانات حقيقي |
| بطاقات المخاطر والمشكلات | محتوى ثابت | تستبدل بكيانات EP-10 وQueries |
| ملفات العرض ومحاضر/كراسة metadata | كانت محاكاة في الـWireframe | التنفيذ الحالي يستخدم تخزيناً خاصاً وروابط أهداف وفحصاً fail-closed ومحفوظات فعلية؛ بيانات العرض نفسها لا تنتقل للإنتاج |
| import/export/PDF/backup/restore | كانت محاكاة في الـWireframe | التنفيذ الحالي يستخدم ملفات XLSX/CSV/PDF ونسخاً حقيقية؛ يستمر إثبات الإنتاج عبر اختبارات AC وتمارين التعافي التشغيلية |
| أسماء الحالات وألوانها | Defaults قابلة للتخصيص | تحفظ كـWorkflowStatus مع code ثابت |
| بيانات قوالب الفواتير والأرقام والعملات | Demo، بينما الوحدة نفسها معتمدة | تنشأ من قاعدة البيانات بصلاحيات ولا تدخل في أرصدة أو تقارير محاسبية |

## 7. تغطية الشرائح

| الشريحة | Epics المغطاة | شرط الخروج |
|---|---|---|
| VS-0 | EP-00، EP-01، EP-17، EP-18 | أساس آمن قابل للنشر مع Policy/Audit/Test harness |
| VS-1 | EP-02، EP-03، EP-04، جزء EP-07 | رحلة عميل→مشروع بلا مهام→مهمة مجدولة تعمل end-to-end |
| VS-2 | EP-05، بقية EP-07، جزء EP-15 | List/Kanban/reassignment/requirements من مصدر واحد |
| VS-3 | EP-06، EP-08، EP-09، EP-11 | التخطيط الأسبوعي والاجتماعات والكراسة والملفات بمحفوظات حقيقية |
| VS-4 | EP-10، EP-12، EP-13 | لوحة قرار قابلة للتتبع والبحث والتنبيهات |
| VS-5 | EP-11، EP-16 | ملفات آمنة وقوالب فواتير وPDF |
| VS-6 | EP-14، EP-15، EP-18 | استيراد/تصدير/تعافي وإعدادات وصلابة إطلاق |

## 8. Definition of Done للتتبع

لا يغلق Epic أو Story إلا بعد:

- ربطه بمعرف AC واحد على الأقل؛
- وجود اختبارات happy path والتحقق والخطأ والصلاحية والوصولية المناسبة؛
- تحديث حالة الصف من «مخطط» إلى الحالة الفعلية مع رابط PR/اختبار عند اعتماد أداة backlog؛
- عدم تحويل محاكاة أو بيانات Demo إلى دليل تنفيذ؛
- مراجعة أن كل AC المرتبط ناجح وأن وثائق النطاق والمعمارية لم تتعارضا معه.
