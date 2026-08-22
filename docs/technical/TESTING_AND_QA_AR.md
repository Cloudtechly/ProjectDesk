# الاختبارات وضمان الجودة

## 1. سياسة الدليل

المصدر الحاكم هو الاختبار القابل لإعادة التنفيذ في نفس commit وبيئة CI، لا رقم نجاح قديم. جرد الشيفرة الحالي يحتوي 239 طريقة اختبار Feature و17 طريقة Unit، أي 256 طريقة مكتشفة نصياً، إضافة إلى 10 تدفقات متصفح يشغلها `run-all.mjs`. هذه أرقام جرد وليست شهادة أن آخر تشغيل نجح؛ يجب إرفاق رابط CI أو سجل مؤرخ بكل إصدار.

حالة منظومة الجودة:

| الطبقة | الحالة | الأداة/النطاق |
| --- | --- | --- |
| Unit | منفذ | PHPUnit 12؛ حسابات ومقاييس وجدولة أسبوعية ونسخ وفحص malware |
| Feature/Integration | منفذ على نطاق واسع | Laravel HTTP/DB/policies/files/backups/imports/settings |
| Browser workflows | منفذ | Playwright Chromium عبر 10 scripts متسلسلة |
| تحليل PHP الساكن | منفذ | Larastan/PHPStan level 7 |
| أنواع TypeScript | منفذ | `tsc --noEmit` مع إعداد صارم |
| lint/format | منفذ | Pint، ESLint، Prettier |
| فحص dependencies | منفذ في CI لـ Composer | `composer audit --locked`؛ يلزم أيضاً سياسة دورية للنظام وnpm/pnpm |
| تغطية سطرية بحد أدنى | غير منفذ | CI يعطل coverage؛ لا توجد عتبة تمنع الدمج |
| اختبار حمل/أداء مستمر | جزئي | يوجد `PerformanceVolumeTest`، وليس benchmark إنتاجياً أو SLO |
| DAST/اختبار اختراق | غير منفذ في المستودع | مطلوب قبل نشر حساس |

## 2. بيئة الاختبار

`phpunit.xml` يضبط بيئة معزولة تقريباً: `APP_ENV=testing`، SQLite في الذاكرة، cache/session من نوع array، queue متزامنة، mail array، وhashing منخفض الكلفة. لذلك:

- لا تستنتج أداء SQLite على القرص أو سلوك WAL/أقفال المضيف من اختبارات in-memory وحدها.
- اختبارات backup/restore التكاملية التي تنشئ ملفات مؤقتة هي الدليل الأهم لمسار القرص، ويجب إبقاؤها معزولة.
- queue sync لا يختبر worker/retry/failure transport؛ حالياً لا توجد Jobs مجال مخصصة أصلاً.
- اختبارات المتصفح تستخدم قاعدة seeded بعد `migrate:fresh --seed`، لذلك ممنوع تشغيلها على قاعدة عمل.

متطلبات CI المعلنة: Ubuntu، PHP 8.4 مع `bcmath,curl,dom,fileinfo,gd,intl,mbstring,pdo_sqlite,sqlite3,xml,zip`، Node 22.12.0، pnpm 11.16.0، Chromium. يدعم Composer PHP `^8.3`، لكن build الحالي Vite 8 يحتاج toolchain Node حديثاً؛ لا تعتمد على Node 18 في التطوير أو الإصدار.

## 3. أوامر الجودة

من جذر `project-desk`:

```powershell
# Backend: format check + PHPStan + PHPUnit
composer test

# كل بوابات الشيفرة باستثناء build/browser
composer run ci:check

# اختبارات Laravel فقط أثناء التشخيص
php artisan test
php artisan test --filter=SqliteBackupRestoreIntegrationTest

# Frontend
pnpm run format:check
pnpm run lint:check
pnpm run types:check
pnpm run build

# Browser؛ يتطلب تطبيقاً محلياً وقاعدة اختبار seeded
pnpm run browser:check
```

`composer test` يبدأ بـ`config:clear` ثم Pint check وPHPStan والاختبارات. لا تشغل أوامر الإصلاح مثل `pint` أو `eslint --fix` كجزء من التحقق الصامت إن أردت كشف diff غير مقصود.

تنبيه حالة الأحرف: مجلد المصدر الموجود هو `tests/browser` بينما script في `package.json` يشير حالياً إلى `tests/Browser/run-all.mjs`. يعمل ذلك عادة على Windows غير الحساس للحالة، وقد يفشل على Linux الحساس لها. هذه فجوة يجب حسمها قبل الاعتماد على CI Linux؛ التوثيق لا يعتبر browser gate ناجحاً دون تشغيل فعلي.

## 4. خريطة تغطية Backend

| المجال | الاختبارات الممثلة | ما يجب أن تثبته |
| --- | --- | --- |
| المصادقة والحساب | مجلدات `Feature/Auth` و`Feature/Settings`، `ProvisionAdminCommandTest` | login، verification، password، 2FA/passkeys، session invalidation، provisioning |
| المشاريع/المهام | `ProjectWorkflowTest`, `TaskWorkflowTest`, `Dashboard*`, `ProjectMetrics*` | CRUD، archive/restore، صلاحيات، status، dates، metrics، lock/version conflicts |
| الفريق | `TeamWorkflowTest`, `SharedNavigationAbilitiesTest` | الأدوار والحالة والعضوية وقدرات navigation |
| العملاء | `ClientContactCrudTest` | العميل وجهات الاتصال والتحقق والأرشفة |
| الحوكمة | `GovernanceResourcesTest`, `TimelineMeetingWorkflowTest` | متطلبات ومخاطر وقضايا واجتماعات ومحاضر |
| المستندات والملفات | `ProjectDocumentWorkflowTest`, `ProjectFileSecurityTest`, `ProjectFileTargetLinkTest`, `OrphanedFileRetentionTest` | authorization، ربط الأهداف، scanning، download، orphan pruning |
| قوالب الفاتورة | `SalesDocumentWorkflowTest`, `SalesDocumentAuthorizationTest`, `SalesCalculatorTest`, `PdfExportAuthorizationTest` | draft/archive/restore/duplicate، الإجماليات، PDF، عدم التسريب |
| Data Center | `DataCenterCsvTest`, `DataCenterXlsxTest` | preview/commit، الصيغ الخبيثة، checksum، all-or-nothing |
| النسخ والاستعادة | `SqliteBackupControllerTest`, `SqliteBackupRestoreIntegrationTest`, `SqliteBackupManagerTest`, `RestoreWriteFenceTest`, `AutomaticBackupCommandTest` | تشفير/manifest، صلاحيات، nonce/phrase/checksum، قفل وrollback وautomatic schedule |
| الأمن والتدقيق | `SecurityHeadersTest`, `SecurityActivityAuditTest`, `ActivityLogContextTest` | headers، request/correlation context، activity/security events |
| التنبيهات والبحث | `NotificationCenterTest`, `GlobalSearchTest` | permission-scoped results، stable notifications، preferences |
| إعدادات النظام/workflow | `SystemSettingsTest`, `WorkflowStatusSettingsTest`, `WeeklyScheduleBuilderTest` | validation، immutable codes، week rules والحدود المعروفة |
| malware | `CommandMalwareScannerTest` | command result/timeout/failure contract |
| حجم | `PerformanceVolumeTest` | عدم الانهيار على fixture أكبر؛ لا يمثل capacity proof |

عند إضافة حالة عمل جديدة، أضف اختبار Policy/Request/Service وليس اختبار صفحة سعيد فقط. اختبر 401، 403، 404 لمنع enumeration، 409 للنسخة المتعارضة، 422 للتحقق، و423/429 حيث ينطبق.

## 5. تدفقات المتصفح

يشغّل `tests/browser/run-all.mjs` هذه التدفقات بالترتيب:

| الملف | الهدف العام |
| --- | --- |
| `smoke.mjs` | login والتنقل الأساسي |
| `critical-journey.mjs` | رحلة مشروع/مهمة حرجة |
| `sales-smoke.mjs` | قوالب الفاتورة وPDF دون محاسبة |
| `documents-data-settings-smoke.mjs` | المستندات وData Center والإعدادات |
| `governance-smoke.mjs` | المتطلبات والمخاطر والقضايا/الحوكمة |
| `notifications-smoke.mjs` | مركز التنبيهات |
| `notification-preferences-smoke.mjs` | تفضيلات التنبيه وحدودها |
| `accessibility-responsive-smoke.mjs` | مؤشرات وصول واستجابة أساسية |
| `ui-task-regressions.mjs` | انحدارات واجهة المهام والجدول |
| `unsaved-dialogs-smoke.mjs` | حوار التغييرات غير المحفوظة والتنقل |

الـrunner ينشئ حالة مصادقة مؤقتة بصلاحية ملف `0600` ويحذفها في `finally`. عند الفشل تحفظ CI مجلد `storage/app/browser-tests` وسجل الخادم 14 يوماً. يجب التأكد أن evidence لا يتضمن session/cookies أو بيانات شخصية قبل مشاركته.

اختبارات الوصول هنا smoke وليست تدقيق WCAG كامل. الفحص اليدوي المطلوب يشمل: keyboard-only، ترتيب focus، dialog focus trap/return، قارئ شاشة عربي، RTL/LTR mixed content، contrast، zoom 200%، ورسائل الأخطاء المرتبطة بالحقول.

## 6. خط CI الفعلي

تسلسل `.github/workflows/ci.yml`:

1. checkout بمرجع action مثبت وبدون credentials باقية.
2. إعداد PHP 8.4 وNode 22.12 وpnpm 11.16.
3. إنشاء `.env` وSQLite للاختبار فقط.
4. `composer validate` ثم install و`composer audit --locked`.
5. توليد مفتاح مؤقت وmigrate.
6. `pnpm install --frozen-lockfile` ثم format/lint/types/build.
7. `composer test`.
8. تثبيت Playwright Chromium.
9. `migrate:fresh --seed` لfixtures المتصفح، تشغيل خادم محلي ثم browser workflows.
10. رفع evidence عند الفشل.

يوجد timeout 20 دقيقة وconcurrency تلغي التشغيل الأقدم لنفس المرجع. قبل اعتماد الخط كحاجز إصدار، أصلح/اختبر اختلاف `Browser`/`browser` على Ubuntu.

## 7. استراتيجية بيانات الاختبار

- استخدم factories/seeders وحسابات وهمية؛ لا تنسخ production PII إلى CI.
- احتفظ بحالات boundary: بداية/نهاية اليوم UTC/Tripoli، أسبوع يبدأ الأحد، عطلة الجمعة/السبت، DST لمناطق بديلة إن سمحت الإعدادات بها لاحقاً.
- أنشئ ملفات fixtures صغيرة صالحة وخبيثة: MIME مضلل، signature خاطئ، ZIP bomb محدود، XLSX formula/macro/external ref، path traversal، checksum خاطئ.
- اختبر `lock_version` بعميلين وpreview/commit بعد تغيير سجل.
- اختبر أدواراً متقاطعة، خصوصاً global `viewer` مع project membership `manager`: بعض Policies تسمح تحديث المشروع عبر العضوية بينما upload يمنع viewer صراحة. هذا تضارب فعلي يجب تحويله إلى قرار متطلب واختبار موحد.
- اختبر archived resources وعلاقاتها الدائمة حتى لا يحذف orphan pruner ملفاً ما زال مرجعاً.

## 8. بوابات القبول قبل الدمج

- [ ] `composer validate --strict --no-check-publish` وaudit بلا ثغرات غير مقبولة.
- [ ] Pint check وPHPStan level 7 ناجحان.
- [ ] Prettier وESLint وTypeScript ناجحة.
- [ ] build production ناجح من lockfiles على Node المدعوم.
- [ ] كل Unit/Feature ناجحة ولا توجد skip جديدة بلا تبرير/issue.
- [ ] browser workflows ناجحة على نظام حساس لحالة الأحرف.
- [ ] migration جديدة تختبر fresh install وupgrade وbackup/restore compatibility.
- [ ] تغيير صلاحيات يملك positive وnegative وcross-project tests.
- [ ] تغيير ملف يملك MIME/signature/scanner/quota/orphan tests.
- [ ] تغيير تاريخ يختبر UTC والمنطقة وحدود الأسبوع.
- [ ] تغيير UI يراجع RTL، responsive، keyboard، empty/error/loading states وunsaved guard.
- [ ] لا توجد أسرار أو fixtures حقيقية أو browser auth state في artifact.

## 9. قبول الإصدار

بالإضافة إلى بوابات الدمج:

1. شغّل CI كاملاً على commit المرشح وسجّل SHA والرابط والوقت والنتائج.
2. نفذ `migrate:fresh --seed` وsmoke في staging مشابه للإنتاج.
3. اختبر upgrade من نسخة مدعومة، لا fresh install فقط.
4. أنشئ `.pdesk` في staging واستعدها في نسخة بيئة أخرى واختبر rollback متعمداً.
5. اختبر scanner الحقيقي وmailer وscheduler وdisk permissions وTLS/cookies.
6. تحقق من invoice template PDF بالعربية والعملات المدعومة، ومن عدم ظهور ledger/payment/accounting workflows.
7. راجع release notes، تغييرات البيئة، runbook والرجوع، وموافقة مالك المنتج/الأمن/التشغيل.

## 10. فجوات وخطة تحسين QA

| الأولوية | الفجوة | الإجراء المقترح |
| --- | --- | --- |
| P0 | case mismatch في مسار browser script | توحيد الاسم وتشغيل CI Ubuntu قبل الإصدار |
| P0 | لا evidence حديث مضمّن تلقائياً في الوثائق | حفظ ملخص JUnit/CI وSHA مع كل release |
| P1 | تضارب global viewer/project manager | قرار صلاحية مركزي، تعديل Policies واختبارات matrix |
| P1 | إعدادات calendar/timezone غير موصولة بكل الخدمات | contract tests من setting إلى weekly schedule/backup/UI |
| P1 | لا حد coverage | جمع coverage أولاً ثم عتبة تدريجية للمجالات الحرجة |
| P1 | backup/scanner يعتمد على قرص/command فعليين | integration suite على filesystem وscanner sandbox في CI آمنة |
| P2 | وصول وأداء جزئيان | axe/قارئ شاشة يدوي، budgets، وload profile على staging |
| P2 | لا DAST/SBOM gate موثق | إضافة SBOM، dependency policy، وDAST مصادق محدود |

## 11. تشخيص فشل الاختبار

| الفشل | افحص أولاً | لا تفعل |
| --- | --- | --- |
| Vite/Node syntax أو engine | `node --version` وpnpm lock؛ استخدم Node 22.12 كما CI | لا تغير lockfile بمدير حزم آخر |
| PHP extension missing | `php -m` وقائمة CI، خصوصاً `intl/bcmath/sqlite/zip` | لا تتجاوز الاختبار من production image ناقصة |
| test يعمل منفرداً لا ضمن suite | state/leak/time/factory/order | لا تثبت ترتيب الاختبارات كحل |
| SQLite locked | عمليات متوازية وملفات مؤقتة وWAL | لا تحذف WAL أثناء كاتب نشط |
| browser لا يجد الملف على Linux | حالة `Browser` مقابل `browser` | لا تعتبر نجاح Windows دليلاً على Linux |
| browser selector متقلب | semantics/accessibility locator وانتظار الحالة | لا تضف sleep ثابتاً طويلاً |
| timezone assertion | UTC normalization و`BUSINESS_TIMEZONE` وCarbon clock | لا تعتمد على ساعة الجهاز بلا تثبيت |
| snapshot/fixture يحتوي سراً | evidence وstorage state وlogs | لا ترفعه قبل التنقيح |

