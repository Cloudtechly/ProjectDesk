# معمارية Project Desk

> الحالة: خط أساس معماري للإصدار الأول  
> آخر تحديث: 12 أغسطس 2026  
> النطاق الحاكم: [PRODUCT_SCOPE.md](PRODUCT_SCOPE.md)

## 1. ملخص القرار

يبنى Project Desk كتطبيق Web داخلي لشركة واحدة، باستخدام **Modular Monolith** يضم الخادم والواجهة في مستودع ونشر واحد:

- PHP 8.3+ وLaravel 13؛
- React 19 وTypeScript؛
- Inertia.js 3 لربط صفحات React بمسارات وتحكم Laravel؛
- Tailwind CSS 4 ومكونات Radix المتاحة في المشروع؛
- SQLite بوضع WAL للتطوير والاختبار ونشر v1 أحادي الخادم؛
- تخزين ملفات محلي خاص في v1، مع نسخ `.pdesk` مشفرة إلى موقع خارج الخادم.

لا تُقسم النواة إلى microservices في v1. حدود الموديولات منطقية ومختبرة داخل التطبيق الواحد، ويمكن فصل موديول لاحقاً فقط عند وجود سبب تشغيلي مقاس.

## 2. حالة الأساس التقني

المستودع الحالي مبني بالفعل على Laravel React Starter Kit، وتتطابق الحزم الأساسية مع القرار:

- `laravel/framework ^13.17`؛
- `inertiajs/inertia-laravel ^3.0`؛
- `@inertiajs/react ^3.0.0`؛
- `react` و`react-dom ^19.2.0`؛
- TypeScript وVite 8 وTailwind CSS 4؛
- Laravel Fortify، 2FA وPasskeys؛
- PHPUnit، Larastan، Pint، ESLint وPrettier.

الحالة الحالية تتضمن الوحدات الإنتاجية الأساسية: لوحة المتابعة والجدول الأسبوعي،
المشاريع والمهام والعملاء والفريق، الحوكمة والاجتماعات والملفات، قوالب الفواتير، مركز
البيانات والنسخ الاحتياطية، إعدادات سير العمل، والمصادقة الداخلية. تبقى هذه
الوثيقة مرجع الحدود المعمارية، بينما يثبت `REQUIREMENTS_TRACEABILITY.md` حالات
القبول والاختبارات المرتبطة بكل مطلب.

## 3. المبادئ

1. **مصدر حقيقة واحد:** المهمة والمشروع والمستند لا ينسخان لكل طريقة عرض.
2. **صلاحية على الخادم:** كل Controller/Action يمر عبر Policy أو Gate؛ إخفاء الواجهة ليس حماية.
3. **حدود موديولات واضحة:** لا يعدل موديول جداول موديول آخر مباشرة خارج واجهته التطبيقية.
4. **المعاملات أولاً:** الإسناد والاستيراد والتحويلات التجارية والنسخ عمليات ذرية.
5. **الأحداث للتكامل الداخلي:** تستخدم Domain Events بعد نجاح المعاملة للتدقيق والتنبيهات وتحديث القراءات.
6. **منطق الأعمال في الخادم:** التواريخ والحالات والإجماليات والصلاحيات لا تعتمد على JavaScript وحده.
7. **واجهات رقيقة:** صفحات React تنسق العرض والتفاعل؛ قواعد المجال في Actions/Services/Models/Value Objects.
8. **قابلية الاختبار:** كل حد موديول وقاعدة قبول له اختبار تلقائي مناسب.
9. **العربية وRTL من البداية:** ليست ترجمة تضاف في النهاية.
10. **لا افتراضات متعددة المؤسسات:** v1 شركة واحدة ولا يوجد `tenant_id` موزع على الجداول.

## 4. الشكل العام

```text
Browser
  React 19 + TypeScript + Inertia 3
        │ Inertia requests / forms / partial reloads
        ▼
Laravel 13 Web Layer
  Routes → Form Requests → Controllers → Policies
        │
        ▼
Application Layer
  Commands / Actions / Queries / DTOs / Transactions
        │
        ▼
Domain Modules
  Models / Value Objects / Domain Services / Events
        │
        ├── Relational Database
        ├── Private File Storage
        ├── Queue / Scheduler
        ├── Search adapter
        └── PDF/XLSX/CSV adapters
```

Inertia هو طبقة النقل الأساسية لصفحات v1؛ لا حاجة إلى REST API عام مكرر. يمكن إنشاء endpoints داخلية محدودة للبحث أو الرفع المباشر، وتخضع لنفس الجلسة والسياسات. أي Public API لاحق خارج نطاق v1.

## 5. الموديولات وحدودها

### 5.1 Identity & Access

**يمتلك:** `users`, authentication credentials, passkeys, 2FA، الأدوار العامة.  
**يقدم:** المستخدم الحالي، إدارة الحساب، تحقق الدور العام.  
**لا يمتلك:** عضوية المشروع أو صلاحيات سجل تجاري بعينه.

### 5.2 Directory

**يمتلك:** العملاء، جهات الاتصال وملفات أعضاء الفريق التشغيلية.  
**يقدم:** بيانات العميل/الاتصال، أعضاء متاحون للإسناد، سياسات الخصوصية والأرشفة.  
**لا يعدل:** المشاريع أو المهام عند أرشفة عميل/عضو؛ يرفض أو ينشر حدثاً لتطبيق السياسة.

### 5.3 Projects

**يمتلك:** المشاريع وعضوياتها وأدوار المشروع.  
**يقدم:** نطاق المشروع، العميل والمدير والفريق، تواريخ المشروع وحالته.  
**يعتمد على:** Directory للتحقق من العميل والاتصال، Identity للمستخدمين.

### 5.4 Requirements

**يمتلك:** المتطلبات، روابط المهمة/المتطلب، كراسة المتطلبات وإصداراتها.  
**يقدم:** متطلبات المشروع، معايير القبول، الإصدار الحالي وسجل الإصدارات.  
**يعتمد على:** Projects وDocuments.

### 5.5 Work

**يمتلك:** المهام وسجل الإسناد.  
**يقدم:** CRUD المهمة، التصفية، list/Kanban projections، حسابات الحالة والإنجاز الأساسية.  
**يعتمد على:** Projects للتحقق من العضوية، Requirements للروابط، Workflow للحالات.

### 5.6 Planning & Meetings

**يمتلك:** البنود الزمنية والاجتماعات والحضور والمحاضر وإجراءات الاجتماع المنظمة.  
**يقدم:** جدول المشروع والقراءة الأسبوعية الموحدة.  
**يقرأ:** مهام Work المجدولة، ولا ينسخها إلى جدول مستقل.

### 5.7 Governance

**يمتلك:** المخاطر والمشكلات وخطط المعالجة والحلول.  
**يقدم:** الاستثناءات المفتوحة وملخصات لوحة المتابعة.

### 5.8 Documents

**يمتلك:** metadata الملف، مفاتيح التخزين، checksum، نتيجة الفحص وروابط الملفات.  
**يقدم:** upload/download/archive وربط آمن بالكيانات.  
**لا يقرر منفرداً الصلاحية:** يستدعي Policy للسجل المالك قبل الرفع أو التنزيل.

### 5.9 Invoice Templates

**يمتلك:** قوالب الفواتير وبنودها ونسخها المؤرشفة.  
**يقدم:** محرر القالب، حسابات المستند الواحد، معاينة A4، النسخ وPDF.  
**لا يقدم:** دفتر حسابات أو أرصدة أو تحصيلاً أو حالات دفع أو تحويلات بين أنواع المستندات.  
**يعتمد على:** Directory وProjects لبيانات معاينة اختيارية، وCompany Settings للهوية والترقيم.

### 5.10 Dashboard & Search

**يمتلك:** لا يملك نسخاً من كيانات الأعمال؛ قد يمتلك read models أو cache قابلة لإعادة البناء.  
**يقدم:** KPIs والرسوم والتدخلات والبحث العام ضمن صلاحية المستخدم.  
**يعتمد على:** Queries منشورة من Projects وWork وPlanning وGovernance؛ لا تدخل قيم قوالب الفواتير في مؤشرات لوحة المتابعة.

### 5.11 Data Operations

**يمتلك:** import/export jobs، أخطاء الاستيراد وbackup/restore metadata.  
**يقدم:** قوالب، فحص، commit ذري، exports وعمليات التعافي.  
**لا يكتب مباشرة عشوائياً:** يستخدم application services للموديولات أو bulk interfaces موثقة داخل transaction.

### 5.12 Workflow & Settings

**يمتلك:** تعريفات الحالات، إعدادات التنبيهات والنسخ، ملف الشركة وترقيم المستندات.  
**يقدم:** stable status codes وترجمة/لون/ترتيب وتصنيف دلالي.

### 5.13 Audit & Notifications

**يمتلك:** audit entries، notifications، delivery attempts والتفضيلات الشخصية.  
**يستهلك:** domain events بعد commit.  
**قاعدة:** Audit append-only من منظور التطبيق؛ لا يعتمد على نصوص الواجهة المترجمة.

## 6. الاعتماد بين الموديولات

```text
Identity ─────────────┐
Directory ──► Projects ──► Requirements
                  │            │
                  ├──► Work ◄──┘
                  │      │
                  ├──► Planning & Meetings
                  ├──► Governance
                  └──► InvoiceTemplates ◄── Directory

Documents ◄── Requirements / Work / Meetings / InvoiceTemplates
Workflow  ◄── Projects / Requirements / Work
Dashboard & Search ◄── read interfaces from all business modules
Data Operations ──► application interfaces of business modules
Audit & Notifications ◄── committed domain events
```

يحظر الاعتماد الدائري بين طبقات المجال. إذا احتاج موديول معلومة من آخر، يستخدم معرفاً وQuery interface أو Application Service، لا يستدعي Controller أو مكون React.

## 7. هيكل المجلدات المنفذ

المستودع حاليًا modular monolith متوافق مع Laravel. لا يوجد `app/Domain`، ولا تدّعي هذه الوثيقة وجوده. فصل المسؤوليات منفذ عبر Models وPolicies وRequests وخدمات تطبيق مركزة، مع صفحات Inertia ومكونات حسب الوظيفة:

```text
app/
  Http/
    Controllers/
    Requests/
  Models/
  Policies/
  Services/
    ProjectIndexData.php
    ProjectWorkspaceData.php
    ProjectTeamService.php
    ProjectLifecycleService.php
    BackupBundleCryptographer.php
    BackupFileRestoreTransaction.php
  Security/
resources/js/
  components/
    data-center/
      data-center-contracts.ts
      data-center-workspace.tsx
    projects/
      governance-dialogs.tsx
      phase-plan-workspace.tsx
      project-tab-content.tsx
      requirement-taxonomy-workspace.tsx
      requirement-analysis-workspace.tsx
    sales/
      sales-contracts.ts
      sales-workspace.tsx
    settings/
      settings-contracts.ts
      settings-workspace.tsx
    tasks/
      task-form-dialog.tsx
      task-kanban-board.tsx
  layouts/
  pages/
    dashboard/
    projects/
    tasks/
    clients/
    team/
    sales/
    data-center/
    settings/
routes/
  web.php
  settings.php
database/
  migrations/
  factories/
  seeders/
tests/
  Feature/
  Unit/
  Browser/
```

عند نمو موديول يُستخرج إلى خدمة أو مكون وظيفي موجود فعليًا، ولا ينشأ هيكل `Domain` افتراضي بلا كود. `ProjectController` مثلًا ينسق الطلب فقط، بينما بناء بيانات القائمة ومساحة المشروع والفريق ودورة الحياة في خدمات مستقلة. النسخ الاحتياطي يفصل التشفير ومعاملة ملفات الاستعادة عن المنسق العام. صفحات المشروع والمهام والإعدادات ومركز البيانات وقوالب الفواتير أصبحت منسقات رقيقة، بينما توجد العقود واللوحات والوورك فلوز في المكونات الوظيفية المبينة أعلاه مع بقاء Inertia props والمسارات كما هي.

## 8. النموذج العلائقي الأساسي

الجداول الأساسية المتوقعة:

```text
users
clients ──< contacts
projects ──< project_members >── users
projects ──< requirements >──< task_requirement >── tasks
tasks ──< assignment_events
projects ──< timeline_entries
timeline_entries ──0..1 meetings ──< meeting_attendees
meetings ──0..1 meeting_minutes
projects ──< risks
projects ──< issues
requirement_books ──< requirement_book_versions
files ──< attachment_links
clients ──0..N invoice templates (optional preview context)
invoice templates ──< line items
workflow_statuses
activity_logs
notifications
import_jobs ──< import_errors
export_jobs
backups ──< restore_jobs
company_settings
```

### 8.1 قيود قاعدة البيانات

- أكواد العميل والمشروع والمهمة وأرقام المستندات فريدة.
- `(project_id, user_id)` فريد في العضوية.
- `(task_id, requirement_id)` فريد.
- `(requirement_book_id, version)` فريد، مع إصدار حالي واحد.
- `meeting_minutes.meeting_id` فريد.
- `tasks.start_at` و`tasks.due_at` غير قابلين لـNULL في المنتج، و`due_at >= start_at` يتحقق في Form Request والمجال، ومع constraint حيث يدعمه المحرك بصورة محمولة.
- `assigned_at` يكون NULL إذا كان `assignee_id` NULL.
- إذا جمع قالب الفاتورة عميلاً ومشروعاً كبيانات معاينة، يجب أن يتبع المشروع العميل نفسه.
- البنود غير سالبة والخصم والضريبة بين 0 و100.
- الحذف الفيزيائي للسجلات المهمة غير افتراضي؛ تستخدم `archived_at` أو soft delete بسياسة واضحة.

## 9. قواعد الزمن

- تخزن اللحظات الزمنية في قاعدة البيانات كـUTC وتعرض بتوقيت المستخدم؛ الافتراضي `Africa/Tripoli`.
- التواريخ التجارية أو اليومية التي لا تمثل لحظة زمنية تحفظ كـ`date`.
- `start_at` و`due_at` للمهمة لحظتان مخططتان ومطلوبتان.
- `assigned_at` لحظة قرار الإسناد، مستقلة تماماً عن الجدولة.
- `recorded_at` لحظة حفظ حدث الإسناد بواسطة النظام.
- `completed_at` لحظة الإنجاز الفعلي، وتدار عند الانتقال إلى/من semantic `DONE` وفق سياسة موثقة.
- العرض الأسبوعي يحسب الأيام محلياً بعد التحويل إلى timezone المستخدم، لا بقص سلسلة UTC.
- اختبارات الحدود تشمل DST حتى إن كان توقيت طرابلس الحالي لا يستخدمه، لأن المستخدم أو بيئة العرض قد تتغير.

## 10. نمط الطلب والتنفيذ

مثال حفظ مهمة:

1. Inertia form يرسل الحقول إلى Laravel route.
2. Form Request يتحقق من الشكل والحقول المطلوبة.
3. Policy تتحقق من حق إدارة المشروع.
4. `SaveTaskAction` يبدأ transaction ويطبق قواعد المجال.
5. ينشأ/يحدث Task، ويضاف AssignmentEvent فقط إذا اعتمد تغيير الإسناد.
6. يحدث `version`، ثم تسجل domain events.
7. بعد commit يستهلك Audit/Notifications الأحداث.
8. يعيد Controller redirect/partial props ورسالة نجاح؛ React يحدث طرق العرض من بيانات الخادم.

لا تسجل إعادة الإسناد من `onChange` في React، ولا يعاد ضبط تاريخ المهمة عند تغيير المسؤول.

## 11. القراءة، البحث ولوحة المتابعة

- Queries منفصلة عن Commands، دون اشتراط CQRS infrastructure كاملة.
- كل KPI يعيد القيمة والنطاق و`drill_down` filter قابل لإعادة التطبيق في القائمة.
- نتائج البحث تمر عبر scope صلاحيات المستخدم قبل pagination.
- الفلاتر لها تمثيل URL/query string لتدعم الرجوع والمشاركة الداخلية.
- تستخدم database indexes في البداية؛ يضاف محرك بحث خارجي فقط إذا أثبتت القياسات الحاجة.
- يمكن cache للقراءات المكلفة، لكنه قابل للإبطال وإعادة البناء ولا يصبح مصدر الحقيقة.

## 12. الملفات

مسار الرفع المنفذ في v1، مع بوابة الفحص الخارجي المطلوبة قبل الإطلاق بملفات غير موثوقة:

1. تحقق Policy من السجل الهدف.
2. تحقق allow-list من الامتداد وMIME وfile signature والحجم.
3. يولد الخادم storage key عشوائياً ولا يستخدم الاسم الأصلي كمسار.
4. يرفع إلى مساحة خاصة غير قابلة للتنفيذ.
5. يحسب checksum وينفذ فحص التوقيع والبنية؛ لا تُسمّى هذه النتيجة فحص malware.
6. يبقى الملف في تخزين خاص. في الإنتاج الذي يستقبل ملفات غير موثوقة يجب ربط ماسح malware خارجي/عامل quarantine وتفعيل `MALWARE_SCANNER_ENABLED=true` قبل منحه حالة `SAFE`؛ وحتى ذلك تستخدم الحالة الصريحة `structurally_safe`.
7. التنزيل عبر controller أو signed URL قصير العمر بعد تحقق جديد من الصلاحية.
8. يسجل الرفع والتنزيل والأرشفة في Audit.

يستخدم `Storage` contract في Laravel حتى تعمل البيئة المحلية والإنتاجية دون تغيير منطق المجال.

## 13. قوالب الفواتير

- القالب هو مستند تصميمي قابل لإعادة الاستخدام، وليس معاملة محاسبية.
- الإجماليات تحسب في Value Object/Service على الخادم باستخدام decimal، وليس float.
- تخزن العملة لكل قالب؛ لا يوجد تحويل عملات أو تجميع أرصدة.
- `subtotal`, `discount_amount`, `tax_amount`, `total` تخص معاينة القالب الواحد ويعاد التحقق منها عند العرض.
- العميل والمشروع وسياق التاريخ بيانات معاينة اختيارية، ولا ينشئ القالب استحقاقاً أو حالة دفع.
- النسخ ينشئ قالباً مستقلاً، بينما الأرشفة والاستعادة تحفظان التاريخ دون حذف.
- PDF يولد حالياً بصورة متزامنة ضمن حدود الحجم الموثقة، بعلامة نموذج واضحة، ويعاد كتنزيل خاص مصرح ومدقق.

## 14. الاستيراد والتصدير

- يعالج الملف كـJob مع template version وحالة واضحة.
- مرحلة validation لا تكتب كيانات العمل.
- تجمع الأخطاء حسب sheet/row/field/code، مع حد آمن للحجم وعدد الصفوف.
- commit الناجح داخل transaction أو دفعات ذات استراتيجية rollback موثقة للملفات الكبيرة؛ لا يسمح بنجاح جزئي صامت.
- كل صف يمر عبر قواعد المجال نفسها قدر الإمكان.
- exports تستخدم snapshot للفلاتر والصلاحيات وقت الإنشاء وتسجل الفاعل.
- يمنع CSV formula injection عند التصدير.

## 15. الصلاحيات

تطبق Laravel Policies لكل كيان، مع قواعد عامة:

- Admin كامل ضمن الشركة الواحدة.
- Project Manager يدير الموارد داخل المشاريع التي يديرها.
- Member يرى مشاريع عضويته ويحدث مهامه وفق السياسة.
- Viewer قراءة فقط للسجلات المصرح بها.
- عمليات users/workflow/import/backup/restore إدارية.
- قوالب الفواتير تحتاج صلاحية صريحة مستقلة، ولا تستنتج من مجرد إمكانية رؤية عميل أو مشروع.
- روابط الملفات والبحث والتصدير تمر عبر object-level authorization.

يجب وجود اختبارات رفض، وليس اختبارات المسار المسموح فقط.

## 16. التدقيق والتزامن

- `activity_logs` append-only ويحتوي actor، event، entity type/id، project، before/after، timestamp وcorrelation ID.
- البيانات الحساسة مثل كلمات المرور والتوكنات ومحتوى الملفات لا تكتب في before/after.
- يستخدم `version` أو `updated_at` كشرط optimistic locking للمهام والمشاريع والمستندات الحساسة.
- التعارض يعيد رسالة مفهومة ومعلومات reload/compare، ولا يكتب فوق تعديل أحدث بصمت.
- الأحداث الجانبية المهمة تستخدم outbox أو dispatch-after-commit لتجنب إشعار عن معاملة فاشلة.

## 17. قواعد الواجهة

- صفحات Inertia مجمعة في ستة سياقات ملاحة: لوحة المتابعة، المشاريع، العمل، الدليل، قوالب الفواتير، الإدارة.
- هذا التجميع لا يحذف قائمة/كانبان/الجدول أو أي قسم مشروع.
- Task drawer واحد يمكن أن يبدأ مختصراً ويتوسع، مع كل الحقول وسجل الإسناد.
- breadcrumbs وproject context ظاهرين عند الانتقال بين الموارد.
- URL يحفظ الشاشة والسياق والفلاتر المهمة.
- loading/empty/error/confirmation حالات حقيقية، لا toasts وهمية فقط.
- تستخدم مكونات Radix للحوار والقائمة عندما تساعد، مع تحقق مستقل من RTL وfocus.

## 18. الاختبارات وبوابات الجودة

### 18.1 الخادم

- Unit لقواعد الوقت والإسناد والإجماليات والحالات.
- Feature لكل CRUD وPolicy وvalidation وarchive.
- Integration للاستيراد والتخزين وPDF والنسخ والاستعادة.
- اختبارات SQLite/WAL في CI، واختبارات migration مستقلة قبل اعتماد أي محرك لاحق.

### 18.2 الواجهة

- TypeScript strict، ESLint وPrettier.
- اختبارات مكونات للتفاعلات الحساسة.
- E2E لأهم الرحلات: login، عميل→مشروع→مهمة، إعادة الإسناد، الأسبوع، اجتماع/محضر، مستند تجاري، import وrestore confirmation.
- automated accessibility ثم تدقيق يدوي بالكيبورد وقارئ شاشة و200% zoom.

### 18.3 البوابات

- `composer test` وPHPStan/Pint ناجحة.
- `pnpm lint` و`pnpm types:check` وbuild ناجحة.
- لا ثغرات حرجة أو عيوب authorization/data-loss مفتوحة.
- migration من قاعدة فارغة وrestore drill ناجحان قبل الإنتاج.

## 19. البيئات والنشر

### Development

- SQLite وlocal private storage.
- Queue من نوع `sync` أو database بحسب السيناريو.
- بيانات factories وهمية فقط؛ لا تنسخ بيانات عملاء حقيقية إلى أجهزة التطوير.

### Test/CI

- SQLite بوضع WAL للاختبارات وبوابة الإصدار الأولى.
- اختبارات حجم 10,000 مهمة واختبارات الاستيراد تؤكد ثبات عدد الاستعلامات ضمن الحمل الداخلي المستهدف.

### Production

- ملف v1 المعتمد: خادم Linux واحد، SQLite WAL على قرص محلي دائم، وتخزين ملفات خاص على الخادم نفسه.
- HTTPS، secrets خارج المستودع، scheduler ومراقبة للسجلات والمهام الفاشلة.
- حزم `.pdesk` مشفرة للقاعدة والملفات، منسوخة خارج الخادم وفق سياسة 3-2-1 حيثما أمكن.
- نشر migrations بطريقة backward-compatible مع خطة rollback/roll-forward.

## 20. قرارات مؤجلة لا تعطل v1

- التوسع الأفقي أو الانتقال إلى PostgreSQL مؤجلان، ولا يعتمدان قبل تنفيذ محول نسخ واستعادة خاص واختبار migration/restore على staging.
- مزود Object Storage وmalware scanner الخارجي قرارا توسع وتشغيل؛ PDF/XLSX منفذان داخل التطبيق.
- Redis اختياري للكاش والصفوف إذا أثبت الحمل الحاجة.
- بوابة العميل والموبايل وmulti-tenancy ليست عناصر مخفية داخل مخطط v1.

## 21. Definition of Done المعماري

لا تعتبر القدرة إنتاجية لمجرد ظهور واجهتها. تعد منجزة فقط إذا:

- قاعدة المجال والتحقق منفذان على الخادم؛
- Policy واختبارات السماح والرفض موجودة؛
- migration/factory/seed الآمن موجودة؛
- واجهة Inertia تدعم loading/empty/error/success والكيبورد؛
- Audit والأحداث اللازمة مسجلة بعد commit؛
- الاختبارات الآلية المرتبطة بمعايير القبول ناجحة؛
- لا تعتمد العملية على بيانات Demo أو تخزين ذاكرة المتصفح؛
- الوثائق والتتبع محدثان.
