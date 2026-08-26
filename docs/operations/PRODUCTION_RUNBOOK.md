# دليل تشغيل Project Desk للإنتاج

هذا الدليل يجهّز ملف النشر المعتمد: Ubuntu 24.04 LTS على خادم Linux واحد، PHP 8.4، Nginx وPHP-FPM، قاعدة SQLite محلية دائمة بوضع WAL، وتخزين خاص. الملفات في `deploy/linux` قوالب يجب مراجعة أسماء المضيف والمسارات فيها قبل التثبيت. التنفيذ المحلي يجهز الأدوات فقط ولا يمثل تصريح إنتاج.

## 1. تهيئة الخادم

1. أنشئ مستخدم خدمة `project-desk` بلا shell تفاعلي وامنحه ملكية `/var/www/project-desk` و`/var/lib/project-desk` فقط.
2. ثبّت PHP 8.4 وامتداداته، Nginx، SQLite CLI، ClamAV daemon و`clamdscan` و`freshclam`، و`rclone` و`jq` وAWS CLI.
3. انسخ `deploy/linux/app.env.example` إلى `/etc/project-desk/app.env` و`operations.env.example` إلى `/etc/project-desk/operations.env`. اجعل الملفين `root:root` وبصلاحية `0600`، واستبدل القيم الوهمية. لا تضعهما داخل checkout ولا تطبعهما في السجلات.
4. استخدم قرصًا محليًا دائمًا لقاعدة SQLite و`storage/app/private`. لا تستخدم NFS أو SMB.
5. اضبط `APP_ENV=production` و`APP_DEBUG=false` و`APP_URL=https://...` و`SESSION_ENCRYPT=true` و`SESSION_SECURE_COOKIE=true`.
6. انسخ pool الموجود في `deploy/linux/php-fpm/project-desk.conf` إلى `/etc/php/8.4/fpm/pool.d/`، وdrop-in الخاص بـPHP-FPM إلى `/etc/systemd/system/php8.4-fpm.service.d/`. هكذا يقرأ systemd الأسرار وهو root ثم يمررها إلى pool المعزول دون ملف `.env` داخل Git.

## 2. الماسح وfail-closed

اضبط `MALWARE_SCANNER_DRIVER=command` و`MALWARE_SCANNER_EXECUTABLE=clamdscan` و`MALWARE_SCANNER_ARGUMENTS=--fdpass,--no-summary`. يجب أن يعمل `freshclam` دوريًا وأن يكون عمر أحدث `daily.cld` أو `daily.cvd` أقل من 48 ساعة.

على staging معزول نفّذ ثلاث حالات وسجّل الوقت ومعرفات النشاط: ملف نظيف يصبح `safe` ويمكن تنزيله؛ عينة EICAR القياسية المأخوذة وقت الاختبار من مصدر رسمي تُحجر ولا يمكن تنزيلها؛ ثم أوقف `clamd` مؤقتًا وتأكد أن الفحص يفشل ولا يصبح الملف `safe`. لا تضع عينة EICAR في المستودع أو النسخ الاحتياطية. يجب أن يكون أحدث توقيع أقل من 48 ساعة.

## 3. الخدمات الخلفية وHTTPS

انسخ وحدات systemd من `deploy/linux/systemd` إلى `/etc/systemd/system`، ثم فعّل `project-desk-queue` و`project-desk-scheduler` وtimer النسخ الخارجي وtimer المراقبة. الوحدات تستخدم ثلاث محاولات للصف، restart تلقائيًا، وقيود systemd. يفحص monitor كل خمس دقائق الخدمات و`failed_jobs` وSQLite WAL والمساحة وعمر توقيعات ClamAV وآخر نسخة خارجية، ويخرج بفشل ظاهر في journal عند أي مشكلة؛ اربط فشل الوحدة بمنصة التنبيه التشغيلية المعتمدة على الخادم. ثبّت إعداد Nginx بعد استبدال اسم المضيف ومسار شهادة Let's Encrypt، ثم نفّذ `nginx -t` واختبر تحويل HTTP وHSTS وخصائص Cookie.

الوحدة `project-desk-preflight.service` ليست timer ولا تعمل تلقائيًا. شغّلها يدويًا قبل قرار الإصدار، واقرأ نتيجتها من journal. خروجها غير الصفري يعني `No-Go`.

## 4. النسخ الخارجية والاستعادة

يضبط `RCLONE_REMOTE` على bucket متوافق مع S3 ومفعّل عليه Versioning وObject Lock بوضع `COMPLIANCE` لمدة 35 يومًا على الأقل، مع تشفير الخادم ومنع الوصول العام. استخدم credentials محدودة للكتابة إلى prefix النسخ فقط. شغّل `verify-s3-object-lock.sh` على الـbucket وعلى أحدث object؛ يتحقق من الإعداد ومن Version ID ومدة الحجز الفعلية. غياب bucket معتمد أو Object Lock يبقي القرار `No-Go`.

خدمة النسخ تنشئ `.pdesk` جديدة، تنقل الملف وحده بـchecksum و`--immutable`، ثم تنفذ `rclone check` وتكتب نتيجة منقحة في `/var/lib/project-desk/offsite-backup-status.json`. لا يحتوي هذا الملف أسرارًا، لكنه لا يصبح دليلًا نهائيًا إلا بعد مطابقته بنتيجة فحص S3 وSHA الإصدار. احتفظ بمفتاح `.pdesk` خارج الخادم وخارج وجهة النسخ نفسها.

مرة كل شهر، استعد أحدث حزمة في بيئة معزولة لا تشارك قاعدة الإنتاج أو ملفاتها. لا تشغّل restore آليًا على مسار الإنتاج. ثبّت الهدف المطلق في سجل التمرين، ابدأ المؤقت قبل الاستعادة، وسجّل عمر أحدث نقطة قابلة للاستعادة لحساب RPO. بعد الاستعادة تحقق من الدخول، ومشاريع ومهام ممثلة، وكراسات ومحاضر، وتنزيل عدة ملفات، ثم قارن checksums. الحد المقبول `RPO ≤ 24h` و`RTO ≤ 4h`، ولا يقبل الأمر تمرينًا أقدم من 35 يومًا.

## 5. الوصولية والأداء والـPilot

أكمل يدويًا على Chrome وFirefox: لوحة المفاتيح، NVDA، العربية والإنجليزية وBidi، تكبير 200%، والحركة المخفضة. قِس على بيانات تمثل 10,000 مهمة و1,000 متطلب وكراسة 300 صفحة ومع 12 جلسة: `p75 INP ≤ 200ms` من 200 تفاعل حقيقي على الأقل، و`search p95 ≤ 500ms` من 100 بحث دافئ وبارد على الأقل. نفّذ Pilot على مشروعين و8–12 مستخدمًا وسجّل محاولات كل رحلة ونجاحها غير المساعد؛ المطلوب ≥90% و`SUS ≥ 75` مع استجابة من كل مستخدم. نموذج جمع الأدلة في `EVIDENCE_COLLECTION_AR.md`.

انسخ `production-evidence.example.json` (schema v2) إلى مسار `PRODUCTION_EVIDENCE_PATH`. ادمج القيم الفعلية فقط، ثم أضف أسماء وتواريخ توقيع المنتج والتقنية والتشغيل. يتحقق الأمر من الأعمار وSHA-256 والحدود والعينات الخام ومطابقة `release_sha` في كل قسم. القالب يفشل افتراضيًا عمدًا ولا يجوز تحويل قيمه الوهمية إلى دليل.

## 6. قرار Go/No-Go

على commit النهائي شغّل:

```bash
composer ci:check
pnpm run browser:release
php artisan project-desk:audit-viewer-assignments
php artisan project-desk:production-readiness
```

بعد تثبيت وحدات الخادم شغّل أيضًا:

```bash
sudo systemctl start project-desk-preflight.service
sudo journalctl -u project-desk-preflight.service --since today
```

أي خروج غير صفري يعني `No-Go`. كذلك يبقى القرار `No-Go` إذا كانت الأدلة الخارجية غير مكتملة حتى لو نجحت اختبارات المستودع. بعد `Go` راقب 24 ساعة، وارجع للإصدار السابق وحزمة `.pdesk` المختبرة عند فشل الدخول أو سلامة SQLite أو الملفات، أو استمرار 5xx، أو تعطل queue/ClamAV.
