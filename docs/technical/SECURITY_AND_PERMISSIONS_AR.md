# الأمن والصلاحيات في Project Desk

## 1. نموذج الثقة

النظام تطبيق داخلي أحادي الشركة. المتصفح غير موثوق؛ جميع القرارات الحساسة في الخادم عبر authentication، Form Requests، Policies/Gates، scopes، nested parent checks، ومعاملات. إخفاء زر React ليس حد أمان.

الأصول الحساسة: credentials و2FA/passkey material، بيانات المشاريع والعملاء، الملفات الخاصة، قاعدة SQLite، APP_KEY، مفتاح `.pdesk`، session/cookies، وسجل النشاط. لا تضعها في Git أو وثائق عامة أو خدمات AI غير معتمدة.

## 2. المصادقة والجلسات

- Laravel Fortify guard `web` وusername=email lowercase.
- يقبل login فقط حساب `status=active` و`archived_at=null` مع password صحيح.
- login و2FA خمس محاولات/دقيقة؛ passkeys عشر/دقيقة بحسب credential/session+IP.
- Email verification إلزامي لمسارات الأعمال.
- 2FA يتطلب confirmation وكلمة مرور؛ Passkeys تعتمد RP host من `APP_URL` وallowed origin نفسه.
- `SESSION_DRIVER=database`, lifetime 120 دقيقة، encryption مفعّل في `.env.example`, serialization JSON، HttpOnly true، SameSite Lax. الإنتاج يجب أن يفعّل Secure cookie وHTTPS.
- تغيير كلمة المرور/email/الدور الحساس يلغي الجلسات الأخرى ويغير remember token. الاستعادة تمسح sessions وpassword reset tokens وتدور remember tokens.
- الحساب المعطل يطرد فوراً عبر `active` middleware.

في production، password default: 12+ مع mixed case/letters/numbers/symbols وuncompromised. في local/testing تتبع قاعدة Laravel الافتراضية الأخف.

## 3. مصفوفة الصلاحيات العامة

الجدول يصف المقصود العام، لكن عضوية المشروع قد تضيف قدرة محلية ولا تضيق النطاق دائماً في التنفيذ الحالي. خصوصاً، `ProjectPolicy::update` لا يمنع global `viewer` إذا كانت عضويته active بدور manager، بينما `uploadFile` يمنعه صراحة. الخلايا الموسومة «فجوة» أدناه تحتاج قراراً وتوحيداً قبل اعتبار Viewer قراءة فقط قطعياً.

| القدرة | Admin | Project Manager | Member | Viewer |
| --- | --- | --- | --- | --- |
| عرض المشاريع المرئية | كل المشاريع | managed/member | member | member |
| إنشاء مشروع/عميل | نعم | نعم | لا | لا |
| تعديل مشروع | نعم | manager_id أو project_role=manager | فقط إذا project_role=manager | ممكن إذا project_role=manager؛ فجوة |
| إدارة مهام المشروع | نعم | في مشروع يديره | فقط إن كان project_role=manager | ممكن عبر update project؛ فجوة |
| تغيير حالة مهمة مسندة | نعم | حسب المشروع/الإسناد | نعم للمهمة المسندة | لا |
| رفع ملف | نعم | manager أو active project member manager/member | project role member | لا |
| المتطلبات/الخط الزمني/الحوكمة/الاجتماعات | نعم | داخل مشروع يديره | فقط إن كان manager محلياً | قد يعدل كmanager محلي؛ فجوة |
| إدارة المستخدمين | نعم | لا | لا | لا |
| مركز البيانات والنسخ | نعم | لا | لا | لا |
| إعدادات الشركة وحالات workflow | نعم | لا | لا | لا |
| عرض/إنشاء قوالب الفواتير | كل القوالب/نعم | قوالبه فقط/نعم | لا | لا |
| تعديل قالب فاتورة | draft يملكه أي منشئ/كلها كAdmin | draft أنشأه فقط | لا | لا |

### صلاحية المشروع

الرؤية: Admin، `manager_id`، أو عضوية project active. التعديل: المشروع غير مؤرشف وAdmin أو manager_id أو عضوية active بدور manager. لا يفحص فرع العضوية الدور العام، وهذا مصدر فجوة Viewer المذكورة. الاستعادة لغير Admin تشترط عضوية active مناسبة حتى لو ظل manager_id.

### العملاء

Admin يرى الكل. غيره يرى من أنشأه أو عميل مشروع مرئي. Project Manager يدير من أنشأه أو عميل مشروع يديره. Contact يرث Policy العميل.

### قوالب الفواتير

صلاحيتها مستقلة عن المشروع: Project Manager لا يرى إلا `created_by=self`. اختيار مشروع مرئي كسياق لا يمنح الآخرين وصولاً. `member/viewer` ممنوعان. binding يقبل invoice draft/archived فقط.

## 4. Object-level authorization

- كل nested route يفحص أن child يخص project الموجود في المسار؛ المخالفة 404 لتقليل تسريب الوجود.
- queries تستخدم `Project::visibleTo`, `Client::visibleTo/manageableBy`, `SalesDocument::visibleTo`.
- البحث، dashboard، exports، التنبيهات والملفات تبدأ من project IDs المرئية.
- تنزيل الملف يتطلب scan=safe، رابطاً نشطاً، ورؤية مشروع واحد على الأقل.
- DataJob، SystemSetting وWorkflowStatus Admin-only.
- Policies ترفض تلقائياً المستخدم inactive/archived عبر `before` في معظم الموارد.

## 5. حماية الإدخال والملفات

### Form/data

- CSRF من Laravel web middleware؛ mass assignment عبر fillable/validated fields.
- فلاتر sort/enum allowlist؛ search العام يهرب wildcards `%` و`_`.
- URLs للاجتماع/الموقع http/https فقط بحسب الحقل.
- optimistic lock يمنع overwrite الصامت.
- CSV/XLSX يرفض formula injection (`=,+,-,@,tabs`) مع استثناء phone موجب صالح؛ export يحول القيم الخطرة إلى نص آمن.
- XLSX يرفض macros، external links، أكثر من ورقة، نسخة template خاطئة، ZIP bomb بالحجم/entries.

### مرفقات المشاريع

تدفق الحماية:

1. Policy وrate limit per user/project.
2. scanner availability في production؛ غياب تكامل حقيقي يرفض الرفع.
3. allowlist extension/MIME وحجم وتوقيع/بنية؛ مفاتيح التخزين عشوائية خاصة.
4. quota للمشروع والمستخدم وعدد الملفات تحت cache lock.
5. عقد `MalwareScanner`: command يمرر absolute path كوسيط دون shell interpolation، أو callback يطبق interface.
6. clean فقط ينتقل `safe`; infected=`quarantined`; scanner failure=`structurally_safe` وغير قابل للتنزيل.
7. التنزيل يعيد authorization ويستخدم `private,no-store`, `nosniff`, `CSP sandbox`.

الوضع الافتراضي local `MALWARE_SCANNER_DRIVER=none` يفيد التطوير فقط. الإنتاج غير جاهز حتى يثبت command/callback حقيقي وفحوص clean/infected/failure.

## 6. أمن النسخ والاستعادة

- `.pdesk` يحتوي ZIP payload لكنه يخزن مشفراً في chunks حجمها 1MiB بـAES-256-GCM authenticated.
- header versioned مع key ID غير سري؛ المفتاح 32-byte منفصل في `BACKUP_ENCRYPTION_KEY`; المفاتيح السابقة للدوران فقط.
- manifest يسجل checksums وقائمة الملفات وinventory؛ paths allowlisted، ويمنع duplicate/traversal/زيادة expanded size/entries.
- validate قبل restore يتحقق من schema، checksum، الملفات، ووجود المدير الحالي active.
- requires recent password، exact phrase، checksum، nonce موقّع أحادي الاستعمال، وthrottle 3/10 دقائق افتراضياً.
- restore يدخل maintenance، ينتظر requests، ينشئ `pre_restore`, يعزل WAL/SHM والملفات، ويعمل rollback عند الفشل.
- legacy SQLite uploads تحول إلى encrypted `.pdesk` مع `legacy_database_only=true`; لا تعيد bytes ملفات غير موجودة.

لا تخزن مفتاح النسخة مع الحزمة. انسخ الحزم إلى off-host/immutable storage، واختبر restore شهرياً في نسخة معزولة.

## 7. ترويسات المتصفح وTLS

كل response يضيف:

- `X-Content-Type-Options: nosniff`؛
- `X-Frame-Options: DENY`؛
- `Referrer-Policy: strict-origin-when-cross-origin`؛
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`؛
- `Cross-Origin-Opener-Policy: same-origin`؛
- `Cross-Origin-Resource-Policy: same-origin`؛
- HSTS سنة مع subdomains عند production+HTTPS.

لا توجد Content-Security-Policy عامة للصفحات في middleware الحالي؛ يوجد CSP sandbox للتنزيلات/PDF. إعداد CSP صفحة شامل **تحسين أمني مخطط** ويحتاج اختبار Inertia/Vite/fonts قبل التفعيل.

## 8. التدقيق والخصوصية

`ActivityLogger` يسجل actor، project، action، subject، before/after، request/correlation، IP وuser-agent. أحداث login/logout/failure و2FA وPasskey تسجل دون password/secret/recovery credential. فشل login لحساب غير معروف لا ينشئ subject اصطناعياً.

اعتبارات:

- before/after قد يحتوي بيانات عمل؛ امنح عرض activity فقط لمرئي المشروع، واضبط retention خارج التطبيق إذا لزم قانونياً.
- لا يوجد UI عام لحذف audit، وهو append-only تطبيقياً، لكن DB admin يستطيع التعديل؛ الحماية غير قابلة للعبث cryptographically غير منفذة.
- request/correlation يسمحان الربط مع reverse proxy logs؛ حافظ على نفس المعرف بعد تنقيحه.

## 9. قائمة hardening الإنتاج

- `APP_ENV=production`, `APP_DEBUG=false`, أسرار من secret manager.
- TLS وتحويل HTTP؛ Secure/HttpOnly/SameSite cookies؛ اختبر HSTS والنطاق.
- Mailer موثوق للتحقق/reset؛ لا تستخدم log mailer.
- ماسح malware حقيقي fail-closed وتحديث signatures ومراقبة الأعطال.
- مفتاح backup مخصص، off-host replication، restore drill.
- least-privilege OS user، صلاحيات `storage` وSQLite فقط، لا expose `storage/app/private`.
- PHP/Nginx upload limits متطابقة مع application limits.
- تدوير logs وحجب الأسرار، مراقبة 401/403/409/423/429/5xx وأحداث scanner/restore.
- scheduler وqueue تحت systemd/Supervisor؛ أوقف custom workers وقت restore.
- Composer/npm audit، CI من lockfiles، build artifacts موثقة.
- نسخ SQLite على local durable disk فقط، لا NFS/SMB.

## 10. تهديدات وحدود متبقية

| الخطر | الوضع الحالي | الإجراء |
| --- | --- | --- |
| XSS/asset injection | React escaping + validation؛ لا CSP صفحة شامل | أضف CSP tested ونظف أي HTML مستقبلي |
| رفع ضار | عقد وتنفيذ fail-closed production | تهيئة ماسح حقيقي وإثباته قبل Go |
| DB/file loss | `.pdesk` كامل ومشفر | off-host + drills + مراقبة RPO/RTO |
| سرقة جلسة | HttpOnly/SameSite/encryption available | TLS + Secure + rotation + incident runbook |
| إساءة Admin | audit فقط؛ لا فصل موافقات | least privilege ومراجعة دورية؛ dual-control للاستعادة إن تطلبت المخاطر |
| Horizontal race | ملف v1 single-host وflock | لا توسع قبل distributed locks/backup adapter |
| Audit tampering by DB operator | غير محمي cryptographically | export/WORM أو signing إذا أصبح مطلب امتثال |
