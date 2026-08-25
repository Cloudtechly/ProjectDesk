# التوثيق التقني لإضافات Development v2

## 1. ملخص معماري

يظل النظام Modular Monolith. أضيفت حدود مجال جديدة داخل التطبيق، مع عملية خارجية محلية واحدة هي Ollama. لا يوجد مزود سحابي أو Public API جديد.

```text
React / Inertia
    -> Laravel Controllers + Form Requests + Policies
        -> Domain Services + DB Transactions
            -> SQLite / private storage / Database Queue
                -> Local worker
                    -> Poppler / Tesseract / Ollama loopback
```

### 1.1 قرارات حاكمة

- يعاد استخدام `timeline_entries` للمراحل والمعالم.
- تحديث الخطة جماعي وذري؛ لا توجد عملية تعديل وزن منفردة تتجاوز قاعدة 100%.
- نتائج النموذج Suggestions في جداول Candidates منفصلة عن المتطلبات المعتمدة.
- يثبت endpoint Ollama في `OllamaClient::BASE_URL` ولا يقرأ من request أو DB.
- عمليات الاستخراج والتحليل الطويلة تعمل عبر Queue `local-ai`.
- لا تسجل prompts أو النص الكامل في logs أو activity metadata.

## 2. مكونات Backend الجديدة

### 2.1 التخطيط

| المكون | المسؤولية |
|---|---|
| `PhasePlanController` | قراءة الخطة وحفظ payload جماعي. |
| `SavePhasePlanRequest` | صلاحية الإدارة، الأنواع، التواريخ، المجموع 100، العلاقات الأبوة. |
| `PhasePlanService` | المعاملة، التقدم، البوابات، الصحة، المرحلة الحالية والمعلم القادم. |
| `ProjectMetrics` | اختيار تقدم tasks أو phases وإسقاط الصحة إلى dashboard legacy semantics. |

### 2.2 إدخال المشروع القائم

| المكون | المسؤولية |
|---|---|
| `ExistingProjectController` | endpoint مستقل للإنشاء القائم. |
| `StoreExistingProjectRequest` | التحقق من الخطوات ومراجع العميل/الفريق والخطة. |
| `ExistingProjectOnboardingService` | إنشاء المشروع وكل موارده داخل `DB::transaction`. |
| `ProjectOnboardingSnapshot` | لقطة الانتقال، المعتمد، التوقيت، JSON والبصمة. |

### 2.3 التصنيف

| المكون | المسؤولية |
|---|---|
| `RequirementTaxonomyController` | الشجرة وCRUD الفئات والمجموعات والنقل والدمج والعلاقات. |
| `RequirementTaxonomyService` | الأنواع، منع الدورة، النقل والدمج، مؤشرات التغطية. |
| `RequirementCategory/Group` | حدود الشجرة الخاصة بالمشروع وترتيبها. |
| `RequirementRelation` | علاقة موجهة بين متطلبين في المشروع نفسه. |
| `RequirementSource` | provenance دقيق للمتطلب المعتمد. |

### 2.4 التحليل المحلي

| المكون | المسؤولية |
|---|---|
| `LocalEngineStatus` | تجميع Ollama/GPU/Poppler/Tesseract والخصوصية. |
| `LocalAiSettings` | قراءة الإعدادات المسموحة وحدودها. |
| `OllamaClient` | health وstructured chat على loopback. |
| `LocalDocumentExtractor` | PDF text/OCR وDOCX XML/tables/images. |
| `DocumentChunker` | مقاطع 12-18 ألف حرف مع overlap وحفظ metadata. |
| `PromptInjectionScanner` | تصنيف إشارات التلاعب وإيقاف الحالات الحرجة. |
| `RequirementAnalysisService` | بدء/إلغاء/إعادة ومفاتيح idempotency. |
| `AnalyzeRequirementBook` | Job طويل، حالة الانتظار، retries والفشل المنقح. |
| `RequirementAnalysisPipeline` | استخراج، تحليل مقاطع، دمج، مقارنة وأثر. |
| `RequirementCandidateApprovalService` | الاعتماد الفردي والجماعي الذري. |

## 3. نموذج البيانات التفصيلي

### 3.1 امتدادات التخطيط

`projects`:

- `progress_mode`: `tasks|phases`.
- `onboarding_type`: `new|existing`.
- `actual_started_at`, `transitioned_at` للمشروع القائم.

`timeline_entries`:

- `parent_phase_id`: self FK للمعلم.
- `weight_percent`: وزن المرحلة.
- `completion_criteria`: شروط الاعتماد.
- `is_gate`: المعلم الإلزامي.
- `completed_at`, `completed_by`: أثر الإكمال.

`tasks.phase_id`: FK اختيارية إلى مرحلة المشروع.

`requirement_timeline_entry`: pivot تربط المتطلبات بالمراحل والمعالم.

### 3.2 شجرة المتطلبات

```text
projects 1---* requirement_categories
requirement_categories 1---* requirement_groups
requirement_groups 1---* requirements
requirements *---* requirements عبر requirement_relations
requirements 1---* requirement_sources
```

الكيانات:

- `requirement_categories`: project، name، position، metadata.
- `requirement_groups`: project، category، name، position، metadata.
- `requirements`: group_id nullable وtype.
- `requirement_relations`: source_requirement_id، target_requirement_id، type.
- `requirement_sources`: requirement، book_version، analysis_run، locator_type/value، excerpt، confidence.
- `taxonomy_templates`: name، payload، active.

### 3.3 المشروع القائم

`project_onboarding_snapshots`:

- project_id unique.
- snapshot JSON.
- checksum.
- approved_by/approved_at.
- لا يوجد update/delete endpoint.

### 3.4 تشغيل التحليل

`requirement_analysis_runs`:

- project_id وrequirement_book_version_id.
- status، file_fingerprint، instruction_version، model، context_size.
- attempt_count، started/finished/cancelled timestamps.
- security_flags وerror_code/error_message المنقحة.
- unique/idempotency key للبصمة + التعليمات + النموذج.

`requirement_candidates`:

- analysis_run_id وordinal.
- payload JSON المنظم.
- category/group/type/title/description/acceptance criteria/priority.
- page/section/paragraph/excerpt/confidence.
- review_status، reviewed_by/at، resulting_requirement_id.
- affected_entities والأسئلة والعلاقات المقترحة.

## 4. العقود والمسارات

### 4.1 خطة المراحل

| Method | Route | الغرض |
|---|---|---|
| GET | `/projects/{project}/phase-plan` | ملخص الخطة والتقدم والصحة. |
| PUT | `/projects/{project}/phase-plan` | استبدال الخطة الموزونة دفعة واحدة. |

Payload الأساسي:

```json
{
  "phases": [
    {
      "id": 10,
      "title": "التحليل",
      "starts_at": "2026-08-01T09:00",
      "ends_at": "2026-08-15T17:00",
      "status": "in_progress",
      "weight_percent": 25,
      "completion_criteria": "اعتماد المتطلبات",
      "milestones": [
        {"title": "اعتماد الكراسة", "date": "2026-08-15T12:00", "is_gate": true}
      ]
    }
  ]
}
```

### 4.2 المشروع القائم

`POST /projects/existing` يستقبل المشروع، العميل، الفريق، تواريخ الانتقال، المراحل والمعالم والمهام والمخاطر والمشكلات. الاستجابة تعيد المشروع وSnapshot وChecklist. لا تنشأ سجلات جزئية عند 422 أو exception.

### 4.3 التصنيف والعلاقات

- `GET /projects/{project}/requirement-taxonomy`
- CRUD للفئات والمجموعات داخل project scoped binding.
- `POST .../groups/{group}/merge`
- `POST .../requirements/{requirement}/relations`
- `DELETE .../requirement-relations/{relation}`
- نسخ taxonomy template إلى المشروع بإذن الإدارة.

### 4.4 التحليل والمراجعة

- بدء run لإصدار كراسة.
- إلغاء، إعادة، وإرجاع status/progress.
- جلب Candidates مع pagination/filter.
- إجراءات Candidate: approve، update-and-approve، reject، merge، question، risk.
- اعتماد مجموعة كاملة في معاملة واحدة.
- endpoint حالة المحرك في settings، لا يستقبل document content.

## 5. خوارزميات المجال

### 5.1 تقدم المرحلة

```text
إذا status=completed -> 100
وإلا task_progress = completed non-cancelled tasks / all non-cancelled tasks
إذا task_progress=100 وmandatory gate مفتوحة -> 99 + awaiting_approval
وإلا progress = task_progress
```

تقدم المشروع في وضع phases هو مجموع `phase.progress * phase.weight / 100`. المراحل الملغاة تستبعد، ويجب أن يبقى مجموع النشطة 100 عند الحفظ.

### 5.2 منع دورة depends_on

قبل إضافة `A depends_on B` يبحث النظام من B عبر حواف depends_on. إذا وصل إلى A ترفض العلاقة. العلاقات الأخرى لا تدخل في فحص الدورة.

### 5.3 Idempotency التحليل

المفتاح المنطقي:

```text
SHA-256(file) + instruction_version + model
```

لا يبدأ run مطابق نشط/مكتمل مرة ثانية. تغيير الملف أو النموذج أو التعليمات ينشئ run جديداً قابلاً للمقارنة.

### 5.4 chunking

الأولوية للعنوان والصفحة، ثم حد الأحرف. يحافظ overlap صغير على استمرارية التعريفات، ولا يتكرر النص الكامل أو يحمل المستند كله في prompt واحد.

## 6. أمن الذكاء الاصطناعي المحلي

### 6.1 حدود الثقة

```text
ملف مرفوع (غير موثوق)
  -> فحص بنيوي/أمني
  -> نص مستخرج (غير موثوق)
  -> Prompt Injection scanner
  -> Ollama بلا أدوات
  -> JSON غير موثوق
  -> Laravel schema/domain validation
  -> Candidate
  -> قرار بشري
  -> transaction معتمدة
```

### 6.2 الضوابط

- loopback ثابت، cloud_enabled=false.
- structured output مع `additionalProperties=false` حيث يلزم.
- temperature منخفضة وseed ثابت لتحسين الاتساق.
- رفض confidence خارج المجال ومصادر غير موجودة وأنواع مجهولة.
- لا cross-project relation أو timeline link.
- لا raw content في exception/log/activity.
- Admin فقط يتجاوز `security_review_required` الحرج.

## 7. التشغيل على Windows

المكونات المثبتة في بيئة التطوير المرجعية:

- Ollama 0.32.15.
- `qwen3:8b-q4_K_M` بحجم 5.2GB.
- Poppler 25.07.
- Tesseract 5.5.3 مع `ara` و`eng`.

تشغيل التطبيق يجب أن يشمل:

```powershell
php artisan serve --host=127.0.0.1 --port=8010
php artisan queue:work database --queue=local-ai,default --sleep=2 --tries=3 --timeout=7200 --memory=512
php artisan schedule:work
```

تستخدم أداة Windows المرفقة القيم نفسها. لا تشغل أكثر من عامل `local-ai` على جهاز RTX 4060 8GB.

## 8. النسخ والاستعادة

تشمل `.pdesk` الجداول والحقول الجديدة لأنها ضمن قاعدة البيانات. لا تشمل:

- ملفات نماذج Ollama.
- cache أو ملفات OCR المؤقتة.
- برامج Poppler/Tesseract/Ollama المثبتة.

بعد الاستعادة على جهاز جديد يجب تثبيت التبعيات، سحب النموذج، اختبار الحالة، ثم تشغيل العامل.

## 9. المراقبة واستكشاف الأعطال

| العرض | التشخيص | الإجراء |
|---|---|---|
| waiting_for_engine | Ollama متوقف أو model missing | تشغيل Ollama أو `ollama pull`؛ المهمة تعود تلقائياً. |
| extracting failed | PDF تالف/مشفر أو dependency ناقصة | فحص حالة Poppler/Tesseract وإعادة رفع نسخة صالحة. |
| security_review_required | حقن حرج محتمل | Admin يراجع المقتطف ولا يتجاوز آلياً. |
| analyzing timeout | مقطع كبير أو ضغط GPU | إبقاء عامل واحد، خفض السياق، إعادة run. |
| invalid structured JSON | إخراج لا يطابق schema | retries ثم failed منقح؛ لا اعتماد جزئي. |
| OCR ضعيف | مسح منخفض الدقة/لغة غير متاحة | تحسين المصدر والتحقق من `ara+eng`. |

## 10. جودة الإصدار

بوابات Development v2 المنفذة:

- PHPUnit: 278، نجح 276، skipped 2، assertions 3059.
- PHPStan: صفر أخطاء.
- Pint، TypeScript، ESLint، Prettier، Vite: ناجحة.
- i18n catalog: 100% عربي/إنجليزي بلا collisions.
- Browser: 12 workflows، 360/768/1440، RTL/LTR.
- Accessibility responsive audit: 50 تدقيقاً على 10 routes.
- Ollama structured JSON: اختبار تكامل فعلي ناجح باللغة العربية.
- GPU/Poppler/Tesseract/OCR languages: مكتشفة ومختبرة محلياً.

## 11. ملفات التنفيذ المرجعية

- migrations: `2026_08_23_000450...000470`.
- controllers: ExistingProject، PhasePlan، RequirementTaxonomy، RequirementAnalysis، LocalAiStatus.
- services: PhasePlan، Onboarding، Taxonomy، Extraction، Chunking، Ollama، Analysis، Approval.
- UI: existing-project-dialog، phase-plan-workspace، requirement-taxonomy-panel، requirement-analysis-panel، settings.
- docs: `LOCAL_REQUIREMENTS_ANALYSIS_AR.md` وهذا الملحق.

---

**نهاية التوثيق التقني لإضافات Development v2.**
