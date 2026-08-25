# دليل تشغيل Project Desk للإنتاج

هذا الدليل يجهّز ملف النشر المعتمد: خادم Linux واحد، Nginx وPHP-FPM، قاعدة SQLite محلية دائمة بوضع WAL، وتخزين خاص. الملفات في `deploy/linux` قوالب يجب مراجعة أسماء المضيف والمسارات فيها قبل التثبيت.

## 1. تهيئة الخادم

1. أنشئ مستخدم خدمة `project-desk` بلا shell تفاعلي وامنحه ملكية `/var/www/project-desk` فقط.
2. ثبّت PHP 8.4 وامتداداته، Nginx، ClamAV daemon و`clamdscan` و`freshclam`، و`rclone`.
3. ضع الأسرار في ملف بيئة خارج Git بصلاحية `0600`. القيم الإلزامية تشمل `APP_KEY` و`BACKUP_ENCRYPTION_KEY` وبيانات البريد و`RCLONE_REMOTE`.
4. استخدم قرصًا محليًا دائمًا لقاعدة SQLite و`storage/app/private`. لا تستخدم NFS أو SMB.
5. اضبط `APP_ENV=production` و`APP_DEBUG=false` و`APP_URL=https://...` و`SESSION_ENCRYPT=true` و`SESSION_SECURE_COOKIE=true`.

## 2. الماسح وfail-closed

اضبط `MALWARE_SCANNER_DRIVER=command` و`MALWARE_SCANNER_EXECUTABLE=clamdscan` و`MALWARE_SCANNER_ARGUMENTS=--fdpass,--no-summary`. يجب أن يعمل `freshclam` دوريًا وأن يكون عمر أحدث `daily.cld` أو `daily.cvd` أقل من 48 ساعة.

على بيئة معزولة نفّذ ثلاث حالات وسجّل الوقت ومعرفات النشاط: ملف نظيف يصبح `safe` ويمكن تنزيله؛ عينة EICAR القياسية تُحجر ولا يمكن تنزيلها؛ ثم أوقف `clamd` مؤقتًا وتأكد أن الفحص يفشل ولا يصبح الملف `safe`. لا تضع عينة EICAR في المستودع.

## 3. الخدمات الخلفية وHTTPS

انسخ وحدات systemd من `deploy/linux/systemd` إلى `/etc/systemd/system`، ثم فعّل `project-desk-queue` و`project-desk-scheduler` وtimer النسخ الخارجي. راقب restart count و`failed_jobs` وفشل أوامر scheduler. ثبّت إعداد Nginx بعد استبدال اسم المضيف ومسار الشهادة، واختبر تحويل HTTP وHSTS وsecure cookie.

## 4. النسخ الخارجية والاستعادة

يضبط `RCLONE_REMOTE` على مسار مشفر أو immutable خارج المضيف. خدمة النسخ تنشئ `.pdesk` ثم تنقلها بـchecksum و`--immutable` ثم تنفذ `rclone check`. احتفظ بمفتاح التشفير خارج المضيف والنسخة.

مرة كل شهر، استعد أحدث حزمة في بيئة معزولة لا تشارك قاعدة الإنتاج أو ملفاتها. ابدأ المؤقت قبل الاستعادة، وسجّل عمر أحدث نقطة قابلة للاستعادة لحساب RPO. بعد الاستعادة تحقق من الدخول، ومشاريع ومهام ممثلة، وكراسات ومحاضر، وتنزيل عدة ملفات. الحد المقبول `RPO ≤ 24h` و`RTO ≤ 4h`.

## 5. الوصولية والأداء والـPilot

أكمل يدويًا: لوحة المفاتيح، قارئ الشاشة، العربية والإنجليزية وBidi، تكبير 200%، والحركة المخفضة. قِس على بيانات ممثلة `p75 INP ≤ 200ms` و`search p95 ≤ 500ms`. نفّذ Pilot على مشروعين و8–12 مستخدمًا؛ النجاح المطلوب 90% من الرحلات دون مساعدة و`SUS ≥ 75`.

انسخ `production-evidence.example.json` إلى مسار `PRODUCTION_EVIDENCE_PATH`، استبدل القيم بالأدلة الفعلية، وأضف توقيعات المنتج والتقنية والتشغيل. لا تُملأ قيم افتراضية على أنها دليل.

## 6. قرار Go/No-Go

على commit النهائي شغّل:

```bash
composer ci:check
pnpm run browser:release
php artisan project-desk:audit-viewer-assignments
php artisan project-desk:production-readiness
```

أي خروج غير صفري يعني No-Go. كذلك يبقى القرار No-Go إذا كانت الأدلة الخارجية غير مكتملة حتى لو نجحت اختبارات المستودع.
