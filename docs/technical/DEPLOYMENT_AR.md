# دليل النشر

## 1. نموذج النشر المدعوم

الإصدار الحالي مصمم لتطبيق ويب عربي داخلي على **مضيف واحد**:

`المستخدمون عبر HTTPS -> reverse proxy/Nginx -> PHP-FPM/Laravel -> SQLite محلية + storage خاص محلي`

وعلى نفس المضيف أو تحت نفس القرص/القفل يعمل scheduler. الأصول المبنية تخدم من `public/build`. البريد وماسح malware وخزن النسخ الخارجي تكاملات تشغيلية. لا توجد Jobs مجال مخصصة حالياً، لذلك queue worker ليس ضرورياً للسلوك الحالي، مع بقاء جداول/تهيئة queue scaffold.

| النمط | الحالة |
| --- | --- |
| Linux أو Windows مضيف واحد بقرص محلي دائم | مدعوم وفق التهيئة والاختبار |
| Container واحد مع volume دائم لـSQLite وstorage | ممكن، ويحتاج اختبار permissions/signals/backup |
| عدة PHP replicas على SQLite/storage محلي | غير مدعوم |
| SQLite على NFS/SMB | غير مدعوم/عالي المخاطر |
| نشر أفقي مع DB/objects/locks موزعة | مخطط مستقبلي، يحتاج إعادة تصميم adapters والأقفال والاستعادة |

## 2. متطلبات الخادم

- PHP 8.3+؛ استخدم نسخة مدعومة أمنياً. CI الحالي يتحقق على PHP 8.4.
- Extensions: `bcmath,curl,dom,fileinfo,gd,intl,mbstring,pdo_sqlite,sqlite3,xml,zip`.
- Composer 2 لبناء artifact أو تثبيت vendor من lockfile.
- Node 22.12+ وpnpm 11.16 للبناء فقط؛ لا يلزمان runtime إذا شحنت `public/build`.
- Nginx/Apache أو خادم مكافئ مع PHP-FPM وTLS.
- cron/systemd/Supervisor لتشغيل scheduler.
- ماسح malware production حقيقي عبر driver command أو callback.
- SMTP/مزود بريد موثوق إذا استعملت verification/reset.
- مساحة محلية دائمة للقاعدة وWAL وprivate files والنسخ والسجلات، مع مراقبة وهامش للاستعادة staging.

لا تنشر إذا كان PHP CLI مختلفاً عن PHP-FPM في extensions أو config دون اختبار الاثنين. البيئة المحلية التي ينقصها `intl` أو تعمل بـNode 18 ليست مطابقة للبناء المعتمد.

## 3. بناء artifact

نفذ في CI نظيفة من lockfiles:

```powershell
composer validate --strict --no-check-publish
composer install --prefer-dist --no-progress --optimize-autoloader
composer audit --locked --no-interaction
pnpm install --frozen-lockfile
pnpm run format:check
pnpm run lint:check
pnpm run types:check
pnpm run build
composer test
```

لا تضمّن `.env` أو SQLite أو `storage/app/private` أو logs أو browser auth state في artifact. ضمّن الشيفرة، `vendor` إن كان نموذج النشر artifact كامل، و`public/build` وملفات lock. سجّل commit SHA وchecksums ونتيجة CI.

تنبيه: script المتصفح يشير حالياً إلى `tests/Browser` بينما المجلد `tests/browser`. يجب توحيد حالة الأحرف والتحقق على Linux قبل اعتماد browser gate.

## 4. إعدادات البيئة

استخدم secret manager أو ملف بيئة مقيد القراءة. الجدول يذكر الأسماء لا القيم:

| المجموعة | المتغيرات الأساسية | قاعدة الإنتاج |
| --- | --- | --- |
| التطبيق | `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, `BUSINESS_TIMEZONE`, locales | production، debug false، URL HTTPS، `APP_KEY` ثابت وسري، business timezone مقصودة |
| قاعدة البيانات | `DB_CONNECTION`, SQLite path عند الحاجة، `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_TRANSACTION_MODE` | SQLite محلية؛ القيم الافتراضية للمشروع تختبر قبل تغييرها |
| الجلسة | `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE` | database، Secure مع HTTPS، HttpOnly، SameSite lax افتراضياً، domain ضيق |
| التخزين/queue/cache | `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | private/local والدatabase حسب `.env.example`؛ لا تجعل private files public |
| البريد | `MAIL_MAILER`, host/port/user/password/from | مزود حقيقي، لا `log` لبيانات حساسة |
| malware | `MALWARE_SCANNER_DRIVER`, executable/arguments/timeout أو callback | command/callback حقيقي؛ `none` مرفوض عملياً في production |
| الرفع | `UPLOAD_MAX_FILE_KB`, rate/quota/file limit/orphan retention | متسقة مع Nginx وPHP ومساحة القرص |
| Data Center | `CSV_MAX_*`, `XLSX_MAX_*` | أبق الحدود الدفاعية واختبر الملفات القصوى |
| backup | `BACKUP_MAX_*`, `BACKUP_DISK`, `BACKUP_FILE_DISKS`, `BACKUP_ENCRYPTION_KEY`, `BACKUP_PREVIOUS_ENCRYPTION_KEYS`, restore TTL/attempts | مفتاح مستقل، previous للقراءة أثناء rotation، قرص خاص وoff-host copy |
| logs | `LOG_CHANNEL`, `LOG_LEVEL` | دوران وتنبيه وتنقيح أسرار؛ info أو أشد حسب السياسة |

لا تضع أسراراً في `VITE_*`؛ هذه القيم تدخل bundle عام. لا تغير `APP_KEY` أو `BACKUP_ENCRYPTION_KEY` في مكانها بلا اختبار فك البيانات/النسخ ومهلة تدوير.

## 5. نظام الملفات والصلاحيات

وثّق مسار الإصدار الفعلي، مثلاً `/var/www/project-desk/current`. القواعد:

- document root هو `public` فقط.
- مستخدم PHP يكتب إلى `storage` و`bootstrap/cache` وملف SQLite **ومجلده**.
- ملفات الشيفرة و`.env` لا يكتبها مستخدم الويب؛ `.env` يقرأه فقط مستخدم الخدمة/النشر المناسب.
- `storage/app/private` وbackup directory غير قابلين للوصول المباشر عبر Nginx.
- احتفظ بضعفي مساحة أكبر عملية restore تقريباً، لأن staging و`pre_restore` قد يتعايشان.

مثال أوامر Linux يجب تكييف المستخدم والمسار قبل التنفيذ:

```bash
chown -R deploy:www-data /var/www/project-desk
chown -R www-data:www-data /var/www/project-desk/storage /var/www/project-desk/bootstrap/cache /var/www/project-desk/database
chmod -R u=rwX,g=rwX,o= /var/www/project-desk/storage /var/www/project-desk/bootstrap/cache /var/www/project-desk/database
chmod u=rw,g=r,o= /var/www/project-desk/.env
```

لا تنسخ المثال حرفياً إن اختلف نموذج الملكية؛ اختبر القراءة/الكتابة كمستخدم PHP الفعلي.

## 6. إعداد خادم الويب

Nginx يجب أن يطبق TLS ويخدم الملفات الموجودة من `public` ويرسل غيرها إلى `public/index.php`. الحد الأدنى المنطقي:

```nginx
root /var/www/project-desk/current/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ /\. {
    deny all;
}
```

أضف TLS/HSTS بعد اختبار النطاق، وحدود body/timeouts المتسقة مع upload والـPDF/import. لا تضف alias إلى `storage/app/private`. رؤوس التطبيق موجودة، لكن لا توجد CSP عامة شاملة للصفحات حالياً؛ أي CSP في proxy يجب اختبارها مع Inertia/Vite/الخطوط وPasskeys.

اضبط trusted proxy/protocol في البنية حتى يرى Laravel HTTPS الحقيقي، وإلا قد تتأثر URLs وSecure cookies.

## 7. scheduler والخدمات

cron القياسي:

```cron
* * * * * cd /var/www/project-desk/current && php artisan schedule:run >> /dev/null 2>&1
```

يفضل توجيه stdout/stderr إلى logging مراقب بدلاً من فقده. بديل systemd هو `php artisan schedule:work` مع restart policy. المهام: automatic backup كل دقيقة، notification sync كل دقيقة، orphan pruning 03:30 بتوقيت العمل.

لا تشغل نسختين scheduler على نفس نشر v1 بلا فهم الأقفال. إن أضيف queue worker لاحقاً، شغله عبر systemd/Supervisor وحدد retries/timeouts و`queue:restart` في النشر، وأوقفه أثناء restore.

## 8. أول نشر

1. أنشئ مستخدم الخدمة والمجلدات والـTLS والـsecret file.
2. ضع artifact واضبط symlink `current` إن استعملت releases ذرية.
3. أنشئ SQLite الفارغة والمسارات الخاصة بالصلاحيات الصحيحة.
4. نفذ:

```powershell
php artisan project-desk:ensure-app-key
php artisan migrate --force
php artisan db:seed --class=WorkflowStatusSeeder --force
php artisan optimize
php artisan project-desk:provision-admin
```

5. شغّل PHP-FPM/scheduler، ثم افتح `/up` و`/login` عبر HTTPS.
6. سجل دخول المدير، غير/أكد بياناته، فعّل 2FA/Passkey حسب السياسة، واختبر البريد.
7. اضبط company/general/notifications/automatic backup/calendar، مع العلم أن calendar/timezone integration جزئي في بعض الخدمات.
8. اختبر upload نظيفاً وخبيثاً، PDF قالب فاتورة، بحثاً scoped، نسخة `.pdesk` واستعادة في بيئة معزولة.

`project-desk:ensure-app-key` لا يستبدل مفتاحاً موجوداً. provisioning لا يجوز أن ينتج credential مشتركاً أو محفوظاً في script.

## 9. ترقية إصدار قائم

نفذ نافذة تغيير معلنة؛ SQLite والمضيف الواحد يفضلان إيقاف الكتابة أثناء migration:

1. تحقق من CI/artifact/checksum ومتغيرات البيئة الجديدة ومساحة القرص.
2. أنشئ نسخة `.pdesk` كاملة مؤكدة وانقل نسخة off-host.
3. سجل baseline: `/up`، نسخة التطبيق/commit، counts/عينات، آخر scheduler/backup.
4. أدخل الصيانة: `php artisan down`، وأوقف scheduler وأي worker مخصص.
5. انشر artifact جديداً دون مسح shared `.env`, SQLite, `storage`.
6. نفذ `php artisan migrate --force` ثم `php artisan optimize`.
7. أعد تشغيل PHP-FPM لتفريغ OPcache، ثم scheduler/workers.
8. `php artisan up` ونفذ smoke tests.
9. راقب الأخطاء والقفل والقرص والمستخدمين أول فترة بعد النشر.

لا تشغل `migrate:fresh` ولا تستبدل قاعدة الإنتاج. لا تعتمد على `migrate:rollback` إذا كانت migration حذفت/حوّلت بيانات؛ استخدم خطة الإصدار ونسخة `.pdesk` المختبرة.

## 10. Smoke tests بعد النشر

- [ ] `GET /up` ناجح، و`/login` يحمل assets بلا mixed content.
- [ ] login/logout وCSRF والجلسة وemail flow حسب البيئة.
- [ ] dashboard ومشروع مسموح ومشروع غير مسموح لا يتسربان.
- [ ] إنشاء/تحديث مهمة مع start/end و409 عند نسخة قديمة.
- [ ] upload نظيف ينزل بعد scan؛ infected/failure لا ينزل.
- [ ] بحث لا يظهر بيانات مشروع غير مصرح.
- [ ] قالب فاتورة وPDF يعملان؛ لا تظهر وظائف محاسبة/دفع.
- [ ] scheduler يحدّث التنبيهات والنسخ، وpruner لا يحذف مرجعاً دائماً.
- [ ] إنشاء `.pdesk` والتحقق منها؛ لا تنفذ restore على الإنتاج للاختبار.
- [ ] logs/request IDs بلا أسرار ووقت UTC/business صحيح.

## 11. الرجوع

قرار الرجوع إذا فشل migration، تعذر login/authorization، ظهر فساد قاعدة/ملفات، scanner أصبح fail-open، أو تجاوز الخطأ/SLO حد التغيير.

التسلسل الآمن:

1. أدخل الصيانة وأوقف الكتاب/جدولة.
2. احتفظ بسجلات ونسخة الحالة الفاشلة للتحقيق؛ لا تكتب فوقها عشوائياً.
3. إن كانت الشيفرة فقط ولم تتغير schema، أعد symlink/artifact السابق وأعد PHP-FPM.
4. إن تغيرت البيانات/schema، استخدم تدفق الاستعادة الرسمي للنسخة pre-deploy/`pre_restore` مع العبارة/checksum/nonce، لا نسخ ملف SQLite وهو حي.
5. استعد الملفات مع القاعدة كحزمة واحدة؛ اعزل WAL/SHM وفق runbook.
6. نفذ smoke tests وأعد scheduler ثم اخرج من الصيانة.
7. وثق timeline، الأثر، RPO/RTO، السبب والإجراء التصحيحي.

## 12. المراقبة والسعة

راقب `/up` و`/login`، 5xx/latency، request/correlation IDs، scheduler heartbeat، scan/mail failures، SQLite lock time، WAL/DB size، مساحة/inodes، آخر backup وoff-host copy، وفشل restore/import. التطبيق لا يتضمن منصة observability كاملة.

حدود السعة:

- SQLite تسلسل الكتابة ويحتاج `busy_timeout` وtransactions قصيرة.
- توليد PDF/import/backup/scan متزامن قد يستهلك worker وزمناً؛ راقب timeouts والذاكرة.
- storage المحلي والـflock يمنعان التوسع الأفقي الساذج.
- قبل التوسع: انقل DB إلى خادم مناسب، files إلى object storage خاص، locks/cache/session إلى خدمة مشتركة، backup إلى adapter جديد، ثم أعد اختبارات consistency/restore/authorization.

## 13. hardening قبل Go-Live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, TLS وSecure/HttpOnly/SameSite cookies.
- [ ] أسرار فريدة في secret manager، rotation وbreak-glass موثقان.
- [ ] mailer حقيقي وscanner fail-closed مثبت باختبار.
- [ ] private/backup/database غير مكشوفة، least privilege ونسخ off-host.
- [ ] NTP/timezone وPHP/Nginx upload limits متسقة.
- [ ] Composer audit وOS/PHP/Nginx patching وSBOM/فحص artifact حسب السياسة.
- [ ] scheduler مراقب، logs مدوّرة ومنقحة، alerts فعالة.
- [ ] restore drill ناجح ومؤرخ، مع ربط owner وRPO/RTO.
- [ ] مراجعة الأدوار، خصوصاً تضارب global viewer مع project manager، قبل منح الحسابات.
- [ ] CSP عامة إما منفذة ومختبرة أو المخاطرة موثقة؛ لا تدّع وجودها.

## 14. قائمة إصدار نهائية

- [ ] commit/tag وartifact/checksums ونتائج CI/browser مثبتة.
- [ ] migration upgrade/fresh/restore compatibility اختبرت.
- [ ] release notes وenv delta وrunbook/rollback وملاك القرار جاهزون.
- [ ] `.pdesk` pre-deploy صالحة وoff-host ومتاحة بالمفتاح الصحيح.
- [ ] نافذة الصيانة والإبلاغ والدعم بعد النشر محددة.
- [ ] smoke/security/accessibility/RTL وinvoice PDF مكتملة.
- [ ] scheduler/scanner/mail/backup/monitoring مثبتة من بيئة النشر.
- [ ] لا أسرار أو debug أو test admin أو بيانات browser في artifact.
- [ ] القيود المعلنة واضحة: single-host، SQLite، templates غير محاسبية، calendar integration جزئي.

