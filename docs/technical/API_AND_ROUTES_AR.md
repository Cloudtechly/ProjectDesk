# المسارات والعقود الداخلية في Project Desk

> لا يوجد Public REST API في الإصدار الحالي. هذه عقود Web/Inertia وJSON داخلية محمية بالجلسة وCSRF؛ أي عميل خارجي يحتاج عقداً وإصداراً ومصادقة منفصلة قبل الاعتماد.

## 1. العقد العام

### 1.1 Middleware والاستجابة

جميع مسارات الأعمال تقريباً تستخدم `web + auth + verified + active`. الاستثناءات: صفحة البداية؛ مسارات Fortify للضيف؛ profile/notification preferences تحتاج auth+active ولا تشترط verified؛ health `/up`.

| نوع الطلب | نجاح | فشل شائع |
| --- | --- | --- |
| Inertia page | HTML أولي أو Inertia JSON props | redirect/login، 403، 404 |
| Inertia mutation | 302/303 إلى صفحة مع flash toast | 422 validation bag، 403 |
| JSON مع `Accept: application/json` | `{data: ...}` أو payload موثق؛ create غالباً 201 + Location | `{message, errors}` عند 422؛ 401/403/404؛ 409 stale؛ 423 restore |
| تنزيل | PDF/XLSX/CSV/private file response | 403 أو 404 دون كشف storage key |

كل response ويب يضم `X-Request-Id` و`X-Correlation-Id`. الطلب يقبل معرفاً يطابق `[A-Za-z0-9][A-Za-z0-9._:-]{0,99}` وإلا يولد UUID.

### 1.2 رموز الأخطاء

| HTTP | الدلالة في النظام |
| --- | --- |
| 401 | لا يوجد مستخدم مصادق في نقطة تتوقعه |
| 403 | Policy/Gate أو حساب معطل؛ تنزيل scan غير safe |
| 404 | سجل خارج النطاق/parent mismatch/نوع legacy محجوب/مجموعة غير مدعومة |
| 409 | lock_version قديم في مشروع أو قالب فاتورة في مواضع محددة |
| 422 | Form Request، lock_version قديم في معظم الموارد، ملف/استيراد/حالة مجال غير صالحة |
| 423 | الاستعادة تمسك قفل الكتابة ولا تقبل طلبات متزامنة |
| 429 | login/2FA/passkey/upload/restore rate limit |
| 503 | maintenance mode أثناء restore |

لا تعتمد على نص الخطأ وحده؛ تعامل مع status و`errors.<field>`. تعاد الصفحة أو المعاينة عند conflict، ثم يرسل `lock_version` الجديد.

## 2. مسارات الواجهة العليا

| Method | URI | الاسم | النتيجة |
| --- | --- | --- | --- |
| GET | `/` | home | welcome |
| GET | `/dashboard` | dashboard | Dashboard Inertia |
| GET | `/search?q=` | search | JSON نتائج مرئية؛ 2–80 حرفاً، وإلا فارغ |
| POST | `/notifications/{uuid}/open` | notifications.open | mark read ثم redirect لوجهة يعاد التحقق منها |
| GET | `/up` | — | health baseline |

## 3. المشاريع والعملاء والفريق

### 3.1 المشاريع

| Method | URI | الغرض |
| --- | --- | --- |
| GET/POST | `/projects` | list/create |
| GET/PUT | `/projects/{project}` | show/update |
| POST | `/projects/{project}/archive` | archive |
| POST | `/projects/{project}/restore` | restore |
| GET | `/projects/{project}/summary.pdf` | PDF ملخص مصرح ومدقق |

فلاتر list: `q,status,priority,client,activity=active,risk=high,health=danger|attention|healthy,scope=active|archived,sort=end_date|start_date|name|priority|created_at,direction=asc|desc`. pagination ثابت 20.

عقد create/update الأساسي:

```json
{
  "code": "PRJ-042",
  "name": "اسم المشروع",
  "description": null,
  "client_id": 3,
  "primary_contact_id": 8,
  "manager_id": 5,
  "status_id": 2,
  "priority": "high",
  "start_date": "2026-08-12",
  "end_date": "2026-09-30",
  "members": [{"id": 5, "role": "manager"}, {"id": 7, "role": "member"}],
  "lock_version": 4
}
```

`lock_version` update فقط. code unique max40؛ end ≥ start؛ status مشروع فعال؛ priority low/medium/high/critical؛ client/manager/member نشطون؛ contact تابع للعميل؛ members بلا تكرار وبأدوار manager/member/viewer. لا يسمح نقل موارد المشروع عبر nested routes.

صفحة show تقبل `tab=overview|requirements|tasks|timeline|meetings|risks|issues|team|documents|client|activity`, و`archived=1`, و`tab_page`/`activity_page`. يحمل الخادم التبويب النشط فقط.

### 3.2 العملاء وجهات الاتصال

| Method | URI | الغرض |
| --- | --- | --- |
| GET | `/clients`, `/clients/create`, `/clients/{id}`, `/clients/{id}/edit` | صفحات |
| POST/PUT | `/clients`, `/clients/{id}` | create/update |
| POST | `/clients/{id}/archive` أو `/clients/{id}/restore` | تاريخ دون حذف |
| POST/PUT | `/clients/{id}/contacts[/{contact}]` | create/update contact |
| POST | `/clients/{id}/contacts/{contact}/archive` أو `.../restore` | تعطيل/إعادة تفعيل |

Client payload: `code` unique max40، name، nullable email/phone/address(max5000)، status active/inactive. فلاتر: q، status active/inactive/archived، archived active/only/all، per_page 1–100. Contact: name، role، email، phone، booleans `is_primary/is_active`; primary يجب أن يكون active.

### 3.3 الفريق

| Method | URI | الغرض |
| --- | --- | --- |
| GET/POST | `/team` | list/create user |
| PUT | `/team/{member}` | update |
| POST | `/team/{member}/archive` أو `/team/{member}/restore` | archive/restore |

Payload: name/email unique/phone/job_title/global_role/status؛ عند الإنشاء password + confirmation. عند التعديل password اختياري. تعديل المستخدم لنفسه في email/role/password يتطلب current_password وتأكيداً حديثاً؛ Admin فقط يدير الحسابات ولا يستطيع أرشفة نفسه.

## 4. المهام والمتطلبات

### 4.1 المهام

| Method | URI | الغرض |
| --- | --- | --- |
| GET | `/tasks`, `/tasks/create`, `/tasks/{task}/edit` | list/form |
| POST/PUT | `/tasks`, `/tasks/{task}` | create/update كامل |
| PATCH | `/tasks/{task}/status` | تغيير الحالة فقط |
| POST | `/tasks/{task}/archive` أو `/tasks/{task}/restore` | archive/restore |

Payload الكامل:

```json
{
  "project_id": 1,
  "title": "تنفيذ الواجهة",
  "description": null,
  "status_id": 6,
  "priority": "high",
  "assignee_id": 7,
  "assigned_at": "2026-08-12T09:00",
  "start_at": "2026-08-13T08:00",
  "due_at": "2026-08-18T17:00",
  "estimated_minutes": 1200,
  "notes": null,
  "requirement_ids": [4, 5],
  "assignment_note": "بدء المرحلة",
  "lock_version": 3
}
```

start/due مطلوبان وdue ≥ start؛ assignee اختياري لكن يجب أن يكون مدير المشروع أو عضواً نشطاً بدور manager/member؛ المتطلبات من المشروع نفسه؛ لا نقل مهمة بعد إنشائها؛ estimated 1–100000. طلب status هو `{status_id, lock_version}` ويمكن للمسند إليه تنفيذه وإن لم يملك التعديل الكامل.

### 4.2 المتطلبات

النمط لكل nested resource هو GET collection، POST collection، GET item، PUT item، POST item/archive، POST item/restore تحت `/projects/{project}/requirements`.

Payload: `code` اختياري max40 وفريد داخل المشروع، title، description max20000، acceptance_criteria max50000، priority، status_id لحالة requirement فعالة، owner_id عضو نشط، وlock_version للتعديل/الأرشفة/الاستعادة. الخادم يولد code عند غيابه.

### 4.3 كراسة المتطلبات

| Method | URI | Payload مختصر |
| --- | --- | --- |
| GET | `/projects/{p}/requirement-book` | `archived` اختياري |
| POST | `/projects/{p}/requirement-book/versions` | multipart: title، version_number؟، status؟، note؟، is_current؟، file |
| PUT | `.../versions/{v}` | lock_version + title/status/note/is_current fields |
| POST | `.../{v}/make-current` أو `.../archive` أو `.../restore` | `{lock_version}` |

File max من `UPLOAD_MAX_FILE_KB`; النوع يخضع لخدمة الملفات. status من draft/under_review/approved/superseded. لا يمكن إلغاء current مباشرة؛ عيّن غيره. لا يمكن أرشفة آخر إصدار حالي دون انتقال صالح.

## 5. التخطيط والحوكمة

### 5.1 خط الزمن

Nested تحت `/projects/{p}/timeline-entries`: GET/POST collection، GET/PUT item، POST archive/restore. Query: archived boolean، kind، from، to، per_page≤100.

Payload: kind milestone/delivery/review/deadline/phase/event؛ title؛ starts_at؛ ends_at nullable ≥ start؛ status planned/in_progress/completed/cancelled؛ owner active member؛ note؛ metadata object؛ lock_version للتعديل. `kind=meeting` ممنوع هنا.

### 5.2 الاجتماعات والمحاضر

Nested تحت `/projects/{p}/meetings`: GET/POST collection، GET/PUT item، POST archive/restore، وPUT `/{meeting}/minutes`.

Meeting payload:

```json
{
  "title": "اجتماع الاعتماد",
  "starts_at": "2026-08-15T10:00",
  "ends_at": "2026-08-15T11:00",
  "status": "planned",
  "organizer_id": 5,
  "location": "قاعة 1",
  "meeting_url": "https://meet.example/room",
  "agenda": "...",
  "note": null,
  "attendees": [{"user_id": 5, "attendance_status": "accepted"}],
  "lock_version": 2
}
```

ends > starts، URL http/https، organizer/attendees أعضاء نشطون. Minutes payload: summary مطلوب max100000؛ decisions/action_items؛ إما `file_object_id` آمن أو multipart `attachment` لا كلاهما؛ lock_version عند وجود محضر سابق.

### 5.3 المخاطر والمشكلات

كل منهما يوفر GET/POST collection، GET/PUT item، POST archive/restore تحت المشروع. List يقبل archived، status، per_page≤100.

- Risk: title، description، probability/impact 1–5، status open/monitoring/mitigated/accepted/closed، owner عضو، mitigation، due_at، lock_version.
- Issue: title، description، severity low/medium/high/critical، status open/in_progress/resolved/closed، owner، due_at، resolution، lock_version. resolution مطلوب للحالتين resolved/closed.

## 6. الوثائق

| Method | URI | العقد |
| --- | --- | --- |
| GET | `/projects/{p}/files` | JSON list؛ `archived`, `per_page≤100` |
| GET | `/projects/{p}/file-targets` | JSON targets؛ query type/q/per_page |
| POST | `/projects/{p}/files` | multipart file + target_type project/task/requirement + target_id حسب النوع |
| POST | `/projects/{p}/files/{f}/archive` أو `.../restore` | رابط project العام |
| POST | `/projects/{p}/files/{f}/links/{link}/archive` أو `.../restore` | رابط هدف بعينه |
| GET | `/files/{fileObject}/download` | تنزيل خاص؛ safe + رابط نشط + authorization |

الأنواع المسموحة PDF/DOCX/XLSX/CSV/JPEG/PNG/WebP؛ الحجم الافتراضي 25MB؛ extension/MIME/signature/ZIP active content تفحص. response تنزيل `private, no-store`, `nosniff`, وCSP sandbox.

## 7. قوالب الفواتير

| Method | URI | العقد |
| --- | --- | --- |
| GET/POST | `/sales` | list/create |
| GET/PUT | `/sales/{template}` | JSON detail/update |
| POST | `/sales/{template}/archive` أو `.../restore` أو `.../duplicate` | `{lock_version}` |
| GET | `/sales/{template}/pdf` | PDF خاص ومدقق |

List filters: q، project، status draft/archived؛ pagination 20. Legacy rows تعيد 404 حتى لو ID موجود.

Create/update payload:

```json
{
  "type": "invoice",
  "title": "قالب خدمات التطوير",
  "status": "draft",
  "client_id": null,
  "project_id": null,
  "issue_date": null,
  "due_date": null,
  "reference": null,
  "currency": "LYD",
  "discount_rate": "0",
  "tax_rate": "15",
  "notes": null,
  "line_items": [
    {"name": "تطوير", "description": null, "quantity": "1.000", "unit": "خدمة", "unit_price": "1000.00"}
  ],
  "lock_version": 1
}
```

`number`, `source_document_id`, `proposal`, `receipt`, `letter` prohibited. line_items min1؛ quantity >0 حتى 3 decimals؛ price ≥0؛ rates≤100؛ due لا تسبق issue؛ إذا وجد client وproject فيجب أن يتبعه. `lock_version` prohibited في create ومطلوب في update.

## 8. مركز البيانات

Admin فقط.

| Method | URI | ملاحظات |
| --- | --- | --- |
| GET | `/data-center` | صفحة Inertia |
| GET | `/data-center/jobs[/{id}]` | JSON pagination/detail |
| GET | `/data-center/csv/{resource}/template` و`.../export`؛ ونظيرهما تحت `/xlsx` | resource clients/projects/tasks للتصدير/القوالب |
| POST | `/data-center/csv/{resource}/preview` | clients/tasks فقط؛ multipart CSV ≤10MB default |
| POST | `/data-center/xlsx/{resource}/preview` | clients/tasks فقط؛ template v1، ورقة واحدة |
| POST | `/data-center/imports/{job}/commit` | `{checksum_sha256}` لنفس ملف المعاينة |
| GET | `/exports/xlsx/{resource}` | export scoped للرؤية؛ ليس صفحة Admin حصراً |

CSV headers:

- clients: `code,name,email,phone,address,status`؛
- projects export: `code,name,description,client_code,manager_email,status_code,priority,start_date,end_date`؛
- tasks: `project_code,code,title,description,status_code,priority,assignee_email,assigned_at,start_at,due_at,estimated_minutes,notes`.

المعاينة لا تكتب سجلات العمل. تعرض `DataJob` و`import_errors`; commit يتطلب status validated، checksum مطابق، snapshot إصدارات صالح، وينفذ transaction لجميع الصفوف.

## 9. النسخ والاستعادة

| Method | URI | الحماية/العقد |
| --- | --- | --- |
| POST | `/data-center/backups` | إنشاء `.pdesk`؛ Admin |
| POST | `/data-center/backups/upload` | multipart بامتداد `.pdesk` أو `.sqlite` أو `.sqlite3` أو `.db` |
| POST | `/data-center/backups/{f}/validate` | تأكيد كلمة مرور حديث 900ث؛ يعيد restore_nonce |
| POST | `/data-center/backups/{f}/restore` | password confirmation + throttle؛ confirmation/checksum/nonce |
| GET | `/data-center/backups/{f}/download` | safe private download + checksum header |

Restore payload:

```json
{
  "confirmation": "RESTORE PROJECT DESK",
  "checksum_sha256": "64-hex",
  "restore_nonce": "64-hex.64-hex"
}
```

nonce أحادي الاستخدام، مرتبط بالمستخدم والملف والبصمة، TTL افتراضي 600 ثانية. الاستعادة تسجل خروج الجلسة بعد النجاح.

## 10. الإعدادات والمصادقة

- `/system-settings`: GET all، PUT `/{group}` partial fields، DELETE `/{group}` reset. groups: general/company/notifications/automatic_backup/calendar. Admin فقط.
- `/settings/workflow-statuses/{project|task|requirement}`: GET وPUT/PATCH **مجموعة كاملة**؛ يجب إرسال كل status مرة واحدة؛ code/entity_type immutable؛ لا delete.
- `/settings/profile`: GET/PATCH؛ تغيير email يتطلب current password + recent confirmation ويعيد التحقق ويلغي الجلسات الأخرى.
- `/settings/security`: GET بعد password confirmation؛ `/settings/password` PUT throttled.
- `/settings/notifications`: GET/PATCH `{enabled, overdue_tasks, upcoming_tasks, meetings, lead_hours}`؛ lead لا يتجاوز سياسة النظام.
- Fortify: login/logout، forgot/reset password، email verification، confirm password، 2FA lifecycle/recovery codes وPasskeys. Self-registration غير مفعّل.

## 11. مثال عميل JSON داخلي

استخدم Cookie session وCSRF؛ المثال إيضاح عقد لا API token:

```http
GET /search?q=بوابة HTTP/1.1
Accept: application/json
X-Request-Id: ui-search-42
X-CSRF-TOKEN: ...
Cookie: project_desk_session=...
```

النتيجة:

```json
{
  "data": [{"id":"project-1","type":"project","type_label":"مشروع","title":"بوابة العملاء","subtitle":"PRJ-001","href":"/projects/1"}],
  "meta": {"query":"بوابة","total":1}
}
```

لا تخزن أو تسجل CSRF/session/password/2FA secrets في عميل أو log.
