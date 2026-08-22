# دليل المطور

## 1. نقطة البداية

Project Desk تطبيق Laravel 13 / PHP 8.3+ مع Inertia 3 وReact 19 وTypeScript وVite 8 وTailwind 4. المعمارية modular monolith: طلب الويب يمر عبر Route ثم Form Request/Policy ثم Controller وخدمة مجال ثم Eloquent/SQLite، ويعيد صفحة Inertia أو redirect/JSON. لا توجد microservices أو outbox أو domain event bus، ولا توجد `app/Jobs` مخصصة حالياً؛ `TaskService` وخدمات المجال هي التنفيذ الحقيقي، وليس أي تصميم قديم يذكر `SaveTaskAction`.

الحد الوظيفي المهم: المبيعات الحالية هي **قوالب فواتير غير محاسبية**. لا تضف ledger أو دفعات أو أرصدة أو ترحيل قيود تحت اسم صيانة عادية؛ ذلك منتج/نطاق جديد يحتاج SRS وقراراً صريحاً.

## 2. المتطلبات المحلية

| الأداة | المتطلب |
| --- | --- |
| PHP | `^8.3`؛ CI يستخدم 8.4 |
| Extensions | bcmath, curl, dom, fileinfo, gd, intl, mbstring, pdo_sqlite, sqlite3, xml, zip |
| Composer | v2 ومن lockfile |
| Node | استخدم 22.12 أو أحدث مدعوم من المشروع؛ Node 18 غير مناسب لـVite 8 |
| pnpm | 11.16.0 عبر Corepack |
| قاعدة التطوير | SQLite محلية، ملف واحد ومضيف واحد |

تحقق قبل البدء:

```powershell
php -v
php -m
composer --version
node --version
corepack enable
corepack prepare pnpm@11.16.0 --activate
pnpm --version
```

إذا غاب `intl` أو امتداد آخر، أصلح PHP CLI نفسه؛ نجاح PHP-FPM منفصل لا يعني أن أوامر Artisan/CI المحلية سليمة.

## 3. إعداد نسخة تطوير جديدة

المسار المختصر:

```powershell
Copy-Item .env.example .env
New-Item -ItemType File -Path database/database.sqlite -Force
composer install
php artisan project-desk:ensure-app-key
php artisan migrate
php artisan db:seed --class=WorkflowStatusSeeder
php artisan project-desk:provision-admin
pnpm install --frozen-lockfile
pnpm run build
```

أو `composer setup` ينفذ التسلسل تقريباً، ويتوقف تفاعلياً عند provisioning أول مدير. لا تضع بيانات مدير افتراضية في commit أو توثيق.

للتطوير:

```powershell
composer run dev
```

أو شغّل Laravel وVite كل واحد في terminal مستقل. شغل scheduler في terminal ثالث عند تطوير التنبيهات/النسخ:

```powershell
php artisan schedule:work
```

لا تستخدم `migrate:fresh --seed` على قاعدة عمل؛ هو مخصص للاختبار/fixtures.

## 4. خريطة المستودع

| المسار | المسؤولية |
| --- | --- |
| `app/Models` | 25 نموذج Eloquent في جرد النسخة الحالية |
| `app/Http/Controllers` | 31 Controller؛ تحويل HTTP وتنسيق الاستجابة |
| `app/Http/Requests` | validation وauthorization الأولي وعقود payload |
| `app/Policies` | 17 Policy؛ صلاحيات الكائنات والمشروع |
| `app/Services` | 31 خدمة؛ معاملات وتدفقات المجال والحساب/النسخ/الملفات |
| `app/Console/Commands` | backup، notifications، orphan pruning، app key، admin provisioning |
| `app/Support` | أدوات مشتركة للسياق والتواريخ والأمان/التنسيق بحسب الاستخدام |
| `database/migrations` | مصدر حقيقة المخطط والقيود والفهارس |
| `database/seeders` | حالات workflow وfixtures التطوير/الاختبار |
| `routes/web.php` | routes الأساسية |
| `routes/settings.php` | الملف الشخصي والأمن والتنبيهات |
| `routes/workflow.php` | إعداد حالات workflow |
| `routes/console.php` | scheduler |
| `resources/js/pages` | صفحات Inertia |
| `resources/js/components` | عناصر مشتركة وواجهات المجال |
| `resources/js/hooks` | سلوك مشترك مثل unsaved changes |
| `resources/js/actions`, `routes`, `wayfinder` | مخرجات/تكامل Wayfinder لعقود routes typed |
| `tests/Feature`, `tests/Unit`, `tests/browser` | اختبارات الخادم والمتصفح |
| `config/project-desk.php` | حدود الملفات والنسخ والوقت والسياسات التطبيقية |

الأعداد أعلاه snapshot تساعد على اكتشاف ملف منسي، وليست contract؛ حدثها أو احذفها عند تغير الجرد.

## 5. دورة الطلب

الوصف النصي الكامل:

`المتصفح -> middleware المصادقة/التحقق/الرؤوس/السياق -> route model binding -> Form Request -> Policy -> Controller -> Service داخل transaction عند الكتابة -> Model/SQLite والملف الخاص -> Activity/Security log -> Inertia redirect أو JSON`

القواعد:

1. لا تعتمد على إخفاء زر في React؛ كل عملية تملك authorization خادمياً.
2. استخدم Form Request للأنواع والحدود والرسائل، وPolicy لحق الفاعل في الكائن.
3. اجعل Controller رفيعاً؛ الحسابات والتغييرات المركبة في Service.
4. اجمع الكتابات المرتبطة في `DB::transaction` واستخدم row/operation lock حيث يوجد سباق.
5. سجّل before/after والسياق عبر آلية ActivityLogger الحالية دون أسرار.
6. عد بخطأ محدد: 403 منع، 404 إخفاء/غير موجود، 409 تعارض نسخة، 422 validation، 423 قفل، 429 throttle.

## 6. إضافة ميزة مجال جديدة

اتبع هذا الترتيب العملي:

1. اكتب contract وحالة `implemented/partial/planned` والحدود، خاصة الأدوار والأرشفة والوقت.
2. أضف migration forward آمنة مع foreign keys/unique/indexes و`lock_version` إن كان السجل قابلاً للتحرير المتزامن.
3. أضف Model بعلاقات وcasts وfillable محددة؛ تجنب mass assignment لحقول الملكية/الدور غير المصرحة.
4. أضف Policy واختبارات matrix تشمل cross-project وarchived وinactive/global role + project role.
5. أضف Form Request، ثم Service transaction، ثم Controller وroutes مسماة.
6. مرر props الدنيا إلى Inertia؛ لا ترسل صفوفاً غير مصرح بها ثم تخفيها في الواجهة.
7. استخدم Wayfinder بدلاً من كتابة URLs متفرقة، وشغّل build/types لإعادة التوليد والتحقق.
8. أضف Unit للحساب الخالص، Feature للعقود/DB/Policy، ومتصفحاً للرحلة الحرجة فقط.
9. حدث docs التقنية وSRS ودليل المستخدم في نفس التغيير.

لا تنشئ status codes حرة؛ workflow statuses بيانات مضبوطة، وcode/entity type غير قابلين للتغيير عبر الإعدادات الحالية.

## 7. البيانات والمعاملات

- Migration هي الحقيقة؛ لا تعدل SQLite يدوياً لتطوير ميزة.
- archive منطقي مقصود في المشاريع والموارد ذات الصلة؛ راجع scopes وroute binding حتى لا تعيد legacy/archived بلا قصد.
- حقول `lock_version` تتطلب إرسال النسخة التي قرأها العميل؛ عند mismatch أرجع 409 ودع المستخدم يعيد التحميل/الدمج.
- Data Center preview/commit يعتمد checksum وrecord versions وall-or-nothing؛ لا تحول commit إلى upsert جزئي صامت.
- Activity log متزامن غالباً ضمن تدفق الطلب، ولا يوجد outbox. إذا نقل إلى queue مستقبلاً، صمم ضمان التسليم/الترتيب/الفشل صراحة.
- queue tables scaffold من Laravel وليست دليلاً على asynchronous domain processing.

عند إضافة migration:

```powershell
php artisan make:migration add_example_to_projects_table
php artisan migrate
php artisan migrate:status
```

اختبر fresh install وupgrade من نسخة مدعومة وbackup/restore. لا تجعل rollback blind هو خطة استرجاع إنتاج؛ بعض migrations غير قابلة للعكس دون فقد.

## 8. الوقت والتقويم

- datetimes التجارية تدخل بتوقيت العمل وتخزن/تقارن UTC، ثم تعرض في المنطقة المحددة.
- الافتراضي `BUSINESS_TIMEZONE=Africa/Tripoli`.
- builder الأسبوعي الحالي يعتمد `config/project-desk.php`: بداية الأسبوع الأحد (`0`) وعطلة الجمعة/السبت (`[5,6]`).
- توجد إعدادات system calendar/timezone، لكنها ليست موصولة بالكامل بكل builders وUI/automatic backup. عامل ذلك كـ`partial` ولا تدّع أن تغيير الإعداد يغير كل السلوك.
- اختبر midnight، حدود الأسبوع، قيم date-only، والاجتماعات/التنبيهات عند التحويل إلى UTC.

لا تستخدم `now()` الموزع عشوائياً في منطق قابل للاختبار؛ مرر clock/ثبّت Carbon حيث يلزم.

## 9. الملفات

لا تكتب upload مباشرة في `public` أو تثق بالامتداد. المسار الحالي يفرض allowlist للامتداد/MIME/signature، حدود الحجم والعدد والمساحة/rate، تخزيناً خاصاً بمفتاح عشوائي، ثم malware scan. في الإنتاج فشل/غياب الماسح fail-closed.

لإضافة نوع ملف:

1. حدّث allowlists المتسقة في config/validator/signature inspector.
2. أضف fixtures صحيحة ومضللة واختبارات scanner/quota/download.
3. أثبت أن التنزيل لا يتم إلا لحالة clean وبPolicy صحيحة.
4. أضف المرجع الجديد إلى orphan retention وbackup manifest، بما فيه archived resource.
5. اختبر restore وchecksum ومسارات Unicode دون path traversal.

## 10. قوالب الفاتورة

العقد الحالي:

- نوع document المقبول `invoice` فقط؛ الحالة `draft` أو `archived`.
- عميل/مشروع/issue/due date اختياري، مع صف واحد على الأقل.
- العملات LYD/USD/EUR والنسب 0..100، والحساب في الخادم باستخدام BCMath.
- create/update/duplicate/archive/restore وPDF منفذة.
- جداول proposal/receipt/letterhead legacy محفوظة للتوافق، لكن routes/scopes الحالية تخفيها؛ لا تعيدها تلقائياً.

أي طلب دفع أو balance أو chart of accounts أو tax filing أو reconciliation خارج هذا المجال. سمِّ UI «قوالب فواتير» لا «المحاسبة».

## 11. React وInertia

- التطبيق RTL عربي، و`app.tsx` يهيئ صفحات Inertia وtheme.
- الحالة محلية عبر React وInertia `useForm`/fetch؛ لا يوجد global state store أو WebSocket حالياً.
- shared props يجب أن تكون صغيرة ومصرحاً بها.
- hook التغييرات غير المحفوظة يحرس Inertia navigation و`popstate` و`beforeunload` ويعرض dialog؛ استخدمه في النماذج الجديدة.
- theme محفوظ في localStorage وcookie؛ راع SSR/flash عند تغييره.
- استخدم semantic HTML وRadix primitives، focus management ورسائل field errors، واختبر keyboard وRTL والهواتف.
- فرّق loading/empty/error/permission/archived/conflict states؛ لا تعرض failure عام عندما يملك الخادم 409 أو 422 مفيداً.

## 12. البحث والتنبيهات

البحث الداخلي permission-scoped؛ query بين 2 و80 حرفاً، مع escaping لـLIKE wildcards وحد 5 نتائج لكل نوع. عند إضافة نوع نتيجة، طبق scope في query نفسه واختبر cross-project، ولا تجمع كل البيانات ثم ترشحها في PHP.

التنبيهات الدائمة ينتجها scheduler بمعرف ثابت لتجنب التكرار للمهام المتأخرة/القادمة والاجتماعات. تفضيلات المستخدم يمكنها تضييق سياسة النظام ولا تتجاوز نافذة lead العليا. لا تنشئ المنتج نفسه من UI وscheduler في آن واحد.

## 13. الأمن والأسرار

- احتفظ بـ`.env` خارج Git، ولا تعرض `APP_KEY` أو backup keys أو credentials في exception/screenshot/log.
- لا تبدل `APP_KEY` في بيئة قائمة دون خطة للجلسات والحقول المشفرة.
- استخدم `BACKUP_ENCRYPTION_KEY` مخصصاً في الإنتاج ومفاتيح previous للقراءة أثناء rotation المنضبط.
- لا تستخدم mail `log` أو scanner `none` كإعداد إنتاج.
- Global `viewer` ليس مطبقاً بصورة متسقة مع project membership: `ProjectPolicy::update` قد يسمح لعضو pivot manager حتى لو global viewer، بينما `uploadFile` يمنعه صراحة. لا توسع هذا السلوك بالنسخ؛ احسم المتطلب ووحّد Policies واختباراتها.

## 14. أوامر يومية

```powershell
# فحص routes والمخطط
php artisan route:list
php artisan migrate:status

# الجودة
composer test
pnpm run format:check
pnpm run lint:check
pnpm run types:check
pnpm run build

# تشغيل مهام المجال يدوياً للتشخيص
php artisan project-desk:sync-notifications
php artisan project-desk:automatic-backup
php artisan project-desk:prune-orphaned-files
```

مسارات الإصلاح تكتب ملفات (`pint`, `prettier --write`, `eslint --fix`)؛ راجع diff ولا تستخدمها لتغطية تغيير غير مقصود.

## 15. تشخيص التطوير

| المشكلة | الفحص |
| --- | --- |
| صفحة 419 | CSRF/cookie/domain/proxy وsession driver؛ لا تعطل CSRF |
| 403 غير متوقع | global role، user status/archive، project pivot status/role وPolicy |
| 409 | `lock_version` أو checksum/record version؛ أعد القراءة ولا تجبر الكتابة |
| route/types قديمة | شغل Vite/Wayfinder build وتحقق من imports وحالة الأحرف |
| build يفشل | Node 22.12+ وpnpm 11.16 وfrozen lockfile |
| Artisan يفشل | PHP CLI extensions و`.env` وSQLite write permissions |
| ملف غير قابل للتنزيل | scan status وPolicy وobject/checksum؛ لا تجعل القرص عاماً |
| إشعار لا يظهر | scheduler/timezone/policy/preferences وUTC value |
| تغير إعداد التقويم بلا أثر | التكامل partial؛ تتبع consumer الفعلي في `config/project-desk.php` والخدمة |

## 16. تعريف الإنجاز للتغيير

- [ ] Request وPolicy وService تفصل المسؤوليات وتمنع cross-project access.
- [ ] المعاملة/القفل/audit/timezone والأرشفة معالجة صراحة.
- [ ] migration وindexes والعلاقات واختبار upgrade صحيحة.
- [ ] Unit/Feature/browser بقدر الخطر، وكل quality gates ناجحة.
- [ ] RTL والوصول والاستجابة والتغييرات غير المحفوظة مجربة.
- [ ] الملفات/backup/search/notifications محدثة إن أصبح للميزة أثر فيها.
- [ ] لا أسرار، لا legacy route غير مقصود، ولا توسع محاسبي ضمن قالب الفاتورة.
- [ ] توثيق API/data/security/operations وSRS محدث ووضع القدرة صحيح.

