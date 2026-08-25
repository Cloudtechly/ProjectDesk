# Project Desk — المقارنة التنافسية الشاملة (خط أساس + نسخة التطوير)

> تحفظ هذه الوثيقة نتائج الدراسة المرجعية المؤرخة في أغسطس 2026، وتحدّث تقييم Project Desk فقط وفق الوظائف المنفذة في فرع التطوير.

# الجزء الأول: الدراسة المرجعية الكاملة

# المقارنة العالمية الشاملة لنظام Project Desk

> تاريخ الدراسة: 13 أغسطس 2026  
> النطاق: أنظمة إدارة المشاريع والعمل، مع تركيز خاص على تطوير البرمجيات  
> خط الأساس: تنفيذ Project Desk الحالي، لا النموذج القديم

## 1. الخلاصة التنفيذية

**الحكم الصريح:** Project Desk نواة منتج حقيقية ومختبرة، وليس نموذجاً تجريبياً، لكنه اليوم **نظام حوكمة وتسليم مشاريع برمجية عربي** أكثر منه منصة متكاملة لإدارة دورة تطوير البرمجيات. هذه نقطة قوة إذا حُفظ التركيز، ونقطة ضعف إذا سُوّق كبديل كامل لـJira أو GitLab أو Azure DevOps.

- درجته التحليلية في **نضج التسليم البرمجي العالمي: 46.8/100**.
- درجته في **ملاءمة CloudTech الحالية: 68.5/100**.
- في مصفوفة قدرات أدوات التطوير غير الموزونة جاء 25/80، مقابل 79 لـJira و74 لـYouTrack و73 لـAzure DevOps.
- في اتساع إدارة العمل العامة جاء 23/85، مقابل 76 لـClickUp/Wrike و75 لـAsana؛ لكن هذه المصفوفة تعاقب التخصص ولا تقيس الملاءمة العربية أو سيادة البيانات.

أقوى ما لديك هو الجمع بين العميل والمشروع والمتطلبات بإصداراتها، المهام المجدولة، الاجتماعات والمحاضر، المخاطر والمشكلات، الملفات الخاصة، التدقيق، والواجهة العربية RTL في رحلة واحدة هادئة. أضعف ما لديك هو غياب graph تطوير برمجي يربط backlog وdependency وrepository وPR/MR وCI/CD وrelease، إضافة إلى التعليقات، API/webhooks، الوقت والسعة، وقابلية التخصيص الناضجة.

قبل أي توسع تنافسي، توجد **خمسة عيوب/ديون فعلية** يجب إغلاقها: غموض صلاحية Viewer، إعدادات أسبوع لا تطبق، مصدران للمنطقة الزمنية، غياب Git SHA لمرشح الإصدار، وبقايا مخطط المبيعات القديم. كما توجد بوابات إنتاج غير مثبتة: ماسح malware حقيقي، نسخة خارج المضيف وتمرين استعادة، إعدادات نشر ومراقبة، واختبار وصولية/أداء مع مستخدمين حقيقيين.

القرار الموصى به هو **Hybrid**: يبقى Project Desk مصدر حقيقة العميل والمتطلبات والقرارات والحوكمة، ويتكامل read-only/event-driven مع GitHub أو GitLab كمصدر حقيقة الكود والـCI. لا تُعِد بناء repository أو CI/CD أو المحاسبة داخله.

## 2. ما الذي تعنيه «مقارنة بكل الأنظمة»؟

لا يمكن عملياً حصر كل منتج عالمي أو إضافة أو نسخة خاصة. اعتمدت الدراسة عينة واسعة تمثل أنماط السوق التي قد تنافس Project Desk أو تكمله:

- منصات تطوير برمجيات: Jira، Azure DevOps، GitHub Projects، GitLab، Linear، YouTrack، Shortcut.
- إدارة عمل وخدمات عملاء: Asana، monday.com، ClickUp، Wrike، Smartsheet، Notion، Basecamp، Trello، Microsoft Planner/Project، Teamwork، Productive، Zoho Projects.
- استراتيجية المنتج: Aha! Roadmaps.
- بدائل مفتوحة المصدر/ذاتية الاستضافة: OpenProject، Redmine، Taiga، Plane، Leantime، Tuleap.

هذه 26 منصة تغطي معظم الأنماط المهمة: issue tracker، Agile planning، DevOps/ALM، work management، client services، product roadmapping، PPM، والتشغيل الذاتي. لا تعني الدراسة أن كل ميزة متاحة في كل خطة سعرية.

## 3. المنهجية وحدود الدقة

### 3.1 مصادر الأدلة

- الكود والمواصفات والمصفوفة والاختبارات الحالية لـProject Desk.
- 152 ورقة مصدر من وثائق رسمية وصفحات ميزات ومساعدة وتسعير ومستودعات رسمية للمنافسين.
- لا تعتمد الدرجات على مراجعات مجهولة أو صفحات مقارنة تسويقية لطرف ثالث.
- ميزات التسعير وAI والخطط توسم كمتغيرة زمنياً ولا تعامل كحقائق دائمة.

### 3.2 منظورَا التقييم

توجد نتيجتان لأن اتساع المنتج لا يساوي ملاءمته للشركة:

1. نضج تسليم البرمجيات عالمياً.
2. ملاءمة CloudTech الحالية: عربي، شركة واحدة، مشاريع عملاء، حوكمة وملفات وسيادة بيانات.

راجع [إطار التقييم](findings/scoring-framework.md) للأبعاد والأوزان وقواعد احتساب 0–5.

### 3.3 قيود الدراسة

- لم تُجر تجربة استخدام ميدانية لكل منافس بحساب مدفوع.
- بعض الوظائف تختلف جذرياً حسب الخطة أو الإضافة أو Cloud مقابل Self-managed.
- نتيجة Project Desk تقيس المستودع الحالي؛ لا تثبت الأداء أو الأمان الإنتاجي دون بوابات النشر المعلنة.

## 4. خط أساس Project Desk الحالي

Project Desk الحالي Modular Monolith مبني على Laravel/Inertia/React، عربي RTL، لشركة واحدة وعلى مضيف Linux واحد في نطاق v1. لا نقارن هنا ملف HTML القديم؛ نقارن التطبيق وقاعدة البيانات والسياسات والاختبارات الحالية.

| المؤشر | القيمة المثبتة |
| --- | ---: |
| المتطلبات المتتبعة | 327 |
| منفذة | 306 |
| منفذة جزئياً | 8 |
| مخططة/بوابة | 12 |
| خارج النطاق | 1 |
| Laravel routes | 152 |
| migrations | 17 |
| Feature test files | 46 |
| browser test scripts | 12 |
| آخر تشغيل PHPUnit في هذه الدراسة | 256؛ نجح 254؛ ترك 2؛ 2,724 assertions |

السطح المنفذ يجمع العملاء وجهات الاتصال، الفريق وعضويات المشاريع، المشاريع، المتطلبات وكراسة بإصدارات، المهام وإسنادها، قائمة وكانبان، الجدول الأسبوعي، timeline والاجتماعات والمحاضر، المخاطر والمشكلات، الملفات، dashboard/search/notifications، قوالب فاتورة غير محاسبية، الاستيراد/التصدير، التدقيق، والإعدادات والنسخ المشفرة.

البنية الحالية متعمدة لخادم واحد وSQLite WAL وتخزين خاص محلي. لا يوجد Public API أو Webhooks أو Jobs مجال مخصصة، ولا تعتمد الدراسة أي ادعاء إنتاجي للماسح أو TLS أو النسخة خارج المضيف أو RPO/RTO دون دليل بيئة.

التفاصيل والدليل: [خط الأساس](findings/current-system-baseline.md) و[المصدر المحلي](sources/000-project-desk-baseline.md).

## 5. مقارنة المنصات الموجهة لتطوير البرمجيات

هذه هي المقارنة الأهم. Project Desk يدير «المشروع» جيداً، لكن الأدوات التالية تدير «تسليم البرمجيات» من backlog إلى code/review/build/release.

| النظام | موضع القوة | أبرز قيد | ما يتعلمه Project Desk |
| --- | --- | --- | --- |
| Jira | Agile/Backlog/Workflow/Dependencies/Reports/Marketplace بأكبر عمق | تعقيد إداري وميزات متقدمة مدفوعة واعتماد على التكاملات للكود/CI | حقول وسير وصلاحيات قابلة للتوسع، من دون نسخ التعقيد كاملاً |
| Azure DevOps | Boards + Repos + Pipelines + Tests + Artifacts وسعة الفرق | منظومة كبيرة، mobile محدود، والوقت الفعلي أقل عمقاً من YouTrack/GitLab | capacity والإجازات وربط work item بالـbuild/deploy |
| GitHub Projects | التخطيط الأقرب إلى issues/PRs/Actions والكود | أقل عمقاً في الموارد/الوقت/PPM | رابط task→issue/branch/PR/check بأقل احتكاك |
| GitLab | SDLC/DevSecOps موحد من issue إلى deployment/security | اتساع وتشغيل أثقل وميزات تخطيط مهمة في خطط أعلى | trace requirement→code→pipeline→release |
| Linear | سرعة ووضوح cycles/projects/initiatives وتكامل كود ممتاز | لا time logging أصيل وسعة الأفراد محدودة | UX سريع، اختصارات، وتحديث حالة من PR/MR |
| YouTrack | أفضل توازن: Agile/Gantt/dependencies/time/workflows/API/self-host | ليس repository/CI أصيلاً، وportfolio strategy أقل من Jira | dependency/time/workflow automation بتكلفة معقولة |
| Shortcut | Objective→Epic→Story واضح وموجه للفرق البرمجية | resource scheduling/time/mobile أقل عمقاً | hierarchy بسيطة بلا عبء Jira |

في مصفوفة نضج سير التطوير ذات 16 بعداً متساوياً: Jira 79/80، YouTrack 74، Azure 73، GitLab وLinear 72، Shortcut 69، GitHub 65، وProject Desk 25. هذه نتيجة مجال محدد لا نتيجة ملاءمة CloudTech.

### الاستنتاج الحاسم

لا ينبغي بناء repository أو code review أو CI/CD داخل Project Desk. الأنسب أن يبقى **مصدر الحقيقة للعميل والحوكمة والمتطلبات والقرارات**، بينما يبقى GitHub/GitLab/Azure DevOps مصدر حقيقة الكود. الربط يضيف traceable development links وwebhooks وحالة مشتقة، ولا ينسخ كل issue أو يتيح تحرير السجل نفسه من نظامين بلا مالك واضح.

التفصيل الكامل والخطط والمصادر: [مصفوفة أدوات التطوير](findings/dev-focused-matrix.md).

## 6. مقارنة منصات إدارة العمل العامة

هذه الفئة لا تربط الكود بعمق GitLab/Azure DevOps، لكنها تكشف فجوات التعاون والموارد والمحافظ وتجربة الاستخدام.

| النظام | أقوى ما يقدمه | القيد الأبرز | مقابل Project Desk |
| --- | --- | --- | --- |
| Asana | Goals، Portfolios، Forms/Rules، Workload/Capacity | الميزات الحاسمة في خطط أعلى؛ لا UI عربي منشور | يتفوق في التنظيم والموارد؛ يخسر في العربية وكراسة المواصفات والحوكمة المحلية |
| monday.com | WorkForms، boards قابلة للتشكيل، automation وdashboards | مرونة شديدة تحتاج governance؛ portfolio/resource مؤسسيان | أفضل في no-code؛ Project Desk أوضح وأقل تشظياً |
| ClickUp | أوسع all-in-one: tasks/sprints/docs/chat/goals/time/whiteboards | كثافة وتكلفة ضبط أعلى | مصدر أفكار لا نموذجاً ينسخ بالكامل |
| Wrike | PPM، موارد، وقت وميزانية، proofing/approvals وتحليلات | كلفة وتعقيد وإضافات خطط | أقوى لإدارة مؤسسة؛ Project Desk أبسط وأكثر تخصصاً |
| Smartsheet | PPM وforms وdashboards على نموذج جدولي | add-ons كثيرة وتجربة spreadsheet | أقوى للبيانات والمحافظ؛ أضعف كنموذج مجال جاهز |
| Notion | docs/wiki وقواعد بيانات وتعاون وصفحات ضيوف | يحتاج بناء governance ويفتقر لسعة/وقت أصليين | مكمّل معرفي ممتاز؛ ليس بديلاً لحوكمة Project Desk |
| Basecamp | بساطة، نقاشات، check-ins وClient Mode | لا PPM/Forms/Automation/Resource Planning متقدم | أفضل مرجع للتعاون الهادئ مع العميل |
| Trello | Kanban سهل جداً وButler وPower-Ups | ضحل للمشاريع المعقدة والمحافظ | نموذج للبساطة، لا بديل لعمق Project Desk |
| Microsoft Planner/Project | من Planner بسيط إلى Gantt/critical path/portfolio مع M365 | تراخيص وخدمات وتجربة موزعة | الأقوى إذا الشركة Microsoft-first؛ Project Desk أوحد وأعرب |

في «اتساع قدرات إدارة العمل» غير الموزون للسياق المحلي، سجل الوكيل: Wrike وClickUp 76/85، Asana 75، monday 74، Microsoft 71، Smartsheet 70، Notion 57، Basecamp 47، Trello 45، وProject Desk 23. لا تعني النتيجة أن Project Desk أسوأ اختيار؛ هي تثبت فقط أنه متخصص وليس all-in-one.

التفصيل الكامل لكل نظام، بما فيه خمس نقاط قوة وثلاثة قيود والخطط: [مصفوفة إدارة العمل](findings/work-management-matrix.md). راجع أيضاً [المراجعة المضادة](findings/work-management-adversarial-review.md).

### 6.1 مراجع متخصصة تكمل الصورة

| النظام | لماذا يدخل المقارنة؟ | الدرس المحدد |
| --- | --- | --- |
| Teamwork | الأقرب لإدارة أعمال العملاء: clients، الوقت، السعة، الموارد والربحية | اقتبس client participation وcapacity؛ أبقِ المحاسبة خارج النطاق |
| Productive | تشغيل الوكالات وشركات البرمجيات عبر resourcing/time/budget | effort/capacity فجوة تخطيط حتى إن ظلت الربحية خارج المنتج |
| Zoho Projects | مزيج واسع من Gantt/dependencies/time/issues/automation والتكاملات | قيمة جيدة كمرجع للوظائف العملية لا كنموذج تجربة موحد |
| Aha! Roadmaps | استراتيجية المنتج من goals/initiatives إلى releases/features | لا تضف product strategy كاملة؛ يكفي release/milestone خفيف إذا احتاج الفريق |
| Tuleap | ALM ذاتي الاستضافة مع trackers وAgile وGit/CI/tests/docs | يوضح كلفة بناء lifecycle كامل ولماذا التكامل أفضل من التقليد |

## 7. مقارنة البدائل مفتوحة المصدر وذاتية الاستضافة

هذه الفئة مهمة لأن السيادة والاستضافة المحلية من نقاط قوة Project Desk. قورنت نسخة Community المجانية قدر الإمكان، لا أعلى نسخة تجارية.

| النظام | الدرجة /30 | موضع التفوق على Project Desk | موضع تفوق Project Desk | الحكم |
| --- | ---: | --- | --- | --- |
| OpenProject Community | 23 | Gantt، dependencies، time/cost، API ونضج التشغيل | العربية وسياق العميل وكراسة المتطلبات وبساطة التشغيل | أقوى بديل PM كلاسيكي ذاتي الاستضافة |
| GitLab Free Self-Managed | 20 | issue→commit/MR→pipeline→release وAPI/CI | العملاء والمحاضر والمتطلبات والحوكمة والبساطة | مكمّل هندسي، لا بديل تشغيلي مباشر |
| Redmine | 18 | custom fields/workflows/plugins/API/repositories/time | تجربة أحدث ومسار عربي متكامل جاهز | بديل منخفض الكلفة إذا كانت المرونة أهم من UX |
| Leantime OSS | 18 | goals/blueprints/My Work/time/retrospectives | كراسة الإصدارات والمحاضر والضوابط المحلية | مرجع جيد للعمل الشخصي وربط الهدف بالتنفيذ |
| Plane Community | 17 | cycles/modules/epics/dependencies/API وواجهة مطور حديثة | كل السطح المحلي في codebase واحدة وبلا open-core gating | أفضل benchmark بصري/برمجي؛ راقب حدود Community |
| Taiga self-hosted | 16 | Scrum/backlog/stories/sprints/WIP/API/importers | العميل والحوكمة والوثائق والجدول متعدد المشاريع | أفضل نموذج لإضافة Agile خفيف |
| Project Desk | 14 | الحوكمة والسياق العربي فقط ضمن هذه العدسة | — | متخصص؛ ليس منصة تطوير كاملة |

لا تعني الدرجة أن استبدال النظام بأعلى مجموع هو القرار الصحيح؛ عشرة الأبعاد متساوية عمداً لكشف النقص التقني. التقرير المفصل يبين حدود Community/Enterprise وتكلفة التشغيل: [مصفوفة الأنظمة المفتوحة](findings/opensource-matrix.md).

## 8. مصفوفة القدرات

### 8.1 أين يقف Project Desk اليوم؟

| البعد | الدرجة /5 | الحكم المختصر |
| --- | ---: | --- |
| العربية والسياق المحلي | 5.0 | رائد في العينة لسياق CloudTech؛ Redmine يدعم العربية أيضاً، لذلك التفوق هو الرحلة كاملة لا اللغة وحدها |
| حوكمة العميل والمتطلبات والوثائق | 5.0 | أقوى تمايز وظيفي حقيقي |
| دورة حياة التطوير | 0.5 | لا code/PR/CI/release traceability |
| Agile/backlog | 2.0 | قائمة وكانبان بلا sprint/epic/story/velocity |
| الجدولة والاعتماديات | 3.0 | تواريخ وجدول أسبوعي جيدان بلا dependency engine |
| التعاون والمعرفة | 2.5 | اجتماعات ومحاضر ونشاط، بلا comments/mentions/wiki عام |
| الوقت والموارد والسعة | 1.5 | لا time logs/timesheets/capacity حقيقية |
| المحافظ والتقارير | 3.5 | dashboard وصحة وأولوية ومرحلة قادمة، بلا programs/goals متقدمة |
| التخصيص والأتمتة | 2.5 | حالات وإعدادات محدودة، بلا custom fields/rules builder |
| التكامل وAPI | 1.0 | import/export فقط تقريباً، بلا public API/webhooks |
| الأمن والتدقيق | 4.0 | كود دفاعي قوي؛ أدلة البيئة الإنتاجية لم تكتمل |
| السيادة والتعافي | 4.5 | ملكية وحزمة مشفرة؛ خادم واحد وoff-host drill غير مثبت |
| UX والوصولية | 4.2 | عربي هادئ وresponsive/keyboard؛ لا pilot أو اعتماد AA ميداني |

النتيجتان الموزونتان 46.8/100 عالمياً و68.5/100 لملاءمة CloudTech **تقدير تحليلي اتجاهي** وليستا benchmark أداء أو قرار شراء. ملف الحساب القابل للمراجعة: [project-desk-scorecard.csv](project-desk-scorecard.csv). والمقارنة المختصرة لكل منصة من المنصات الـ26: [system-landscape.csv](system-landscape.csv).

## 9. مزايا Project Desk بالكامل

### 9.1 مزايا استراتيجية

1. **ملاءمة حقيقية لا تخصيص شكلي:** عربي RTL وهوية ومصطلحات CloudTech من الأصل.
2. **نموذج مجال جاهز:** العميل والمشروع والمتطلب والمهمة والاجتماع والمخاطرة والملف كيانات حقيقية، لا columns يبنيها كل فريق بطريقته.
3. **تركيز وبساطة:** لا ضوضاء all-in-one ولا quotas/credits وخطط سعرية تتحكم في كل قدرة.
4. **ملكية وسيادة:** الكود والبيانات والنشر تحت سيطرة الشركة، ولا اعتماد وظيفي على roadmap بائع أو رسم مقعد.
5. **توثيق غير اعتيادي لنظام داخلي:** SRS ومصفوفة 327 متطلباً، دليل مستخدم، وثائق تقنية واختبارات واسعة.

### 9.2 مزايا وظيفية مميزة

6. مساحة مشروع تجمع سياق العميل والتنفيذ والحوكمة والنشاط.
7. متطلبات بمعايير قبول وروابط متعددة مع المهام.
8. كراسة متطلبات بإصدارات وإصدار حالي محفوظ تاريخياً.
9. اجتماعات وحضور ومحاضر وقرارات وإجراءات ومرفق ضمن المصدر الزمني نفسه.
10. مخاطر ومشكلات وproject health/priority/next stage، لا مجرد قائمة مهام.
11. جدول أسبوعي متعدد المشاريع بأشرطة البداية والنهاية واجتماعات قابلة للفتح.
12. ملفات خاصة يمكن ربطها بالمشروع أو المهمة أو المتطلب مع retention.
13. قوالب فاتورة A4/PDF من دون تحويل المنتج إلى محاسبة.
14. بحث وتنبيهات دائمة ومركز بيانات واستيراد/تصدير وتقارير PDF.

### 9.3 مزايا هندسية وتشغيلية

15. Policies/scopes على الخادم، 2FA وPasskeys وحسابات داخلية فقط.
16. معاملات وoptimistic locking وحراسة تغييرات غير محفوظة وأرشفة بدل الحذف.
17. Activity/security audit وassignment history ومعرفات request/correlation.
18. استيراد ذري مع checksums وrecord-version protection.
19. حزمة `.pdesk` مشفرة تشمل SQLite والملفات، مع rollback آمن لـWAL/SHM.
20. 256 اختباراً في التشغيل الحالي؛ 254 نجح و2 تركا عمداً، 2,724 assertions.

### 9.4 مزايا UX

21. Responsive web، keyboard/focus/dialog guards، Bidi وreduced motion واختبارات أحجام متعددة.
22. تدفق موحد وهادئ مناسب لفريق صغير أكثر من الإدارة الثقيلة في Jira/Wrike؛ وهو تلخيص UX لمزيتَي الهوية والنطاق المركز المسجلتين في CSV.

القائمة القابلة للفرز مع الدليل والقيمة والتحفظ لكل نقطة: [project-desk-advantages.csv](project-desk-advantages.csv).

## 10. العيوب والفجوات بالكامل

تضم المصفوفة التفصيلية 55 صفاً: 9 «الآن»، 18 «التالي»، 23 «لاحقاً»، و5 «ليس الآن». لا ينبغي تحويل كل صف إلى feature backlog؛ التصنيف هو الذي يحدد ما يجب إصلاحه وما يجب تأجيله.

### 10.1 عيوب فعلية يجب إغلاقها الآن

| العيب | لماذا هو عيب وليس طلب ميزة؟ |
| --- | --- |
| Viewer قد يحصل على كتابة إذا عُيّن manager | عقد الصلاحية يقول قراءة فقط لكن التنفيذ قد يخالفه |
| week start/weekend محفوظان ولا يطبقان على الجدول | الواجهة تعد المستخدم بإعداد غير فعال |
| مصدران للtimezone | قد يختلف الأسبوع والتنبيه والتقرير بحسب المسار |
| لا Git SHA ثابت ووثيقة readiness متأخرة | لا يمكن ربط الاختبار بمرشح إصدار غير متغير |
| مخطط sales legacy مخفي | ليس عطل مستخدم، لكنه دين صيانة وترحيل يجب إدارته |

### 10.2 بوابات إنتاج حرجة

- تهيئة ماسح malware حقيقي fail-closed واختباره ومراقبته.
- نسخ off-host/immutable وتمرين استعادة فعلي مع RPO/RTO.
- إثبات TLS/cookies/secrets/scheduler/monitoring/log rotation/durable storage في staging/production.
- اختبار قارئ شاشة و200% وWCAG 2.2 AA، ثم قياس INP/search/SUS/نجاح الرحلات على pilot.
- بيان حدود خادم واحد وSQLite/local storage قبل زيادة الحمل أو تقديم HA.

### 10.3 الفجوات التي تمنعه من أن يكون نظام تطوير برمجيات ناضجاً

1. لا Git/branch/commit/PR/MR/build/test/deploy/release traceability.
2. لا backlog/epic/story/subtask/sprint/iteration أصيلة.
3. لا estimates/story points/velocity/burndown/cycle-time.
4. لا blocked-by/predecessor/dependency، critical path أو baseline.
5. لا taxonomy تطوير مثل bug/feature/incident/component/environment بصورة أصيلة.
6. لا triage أو request forms أو release entity.

### 10.4 فجوات التعاون والإنتاجية

7. لا comments/threads/@mentions/watchers/reactions.
8. لا approvals/proofing عام أو wiki/knowledge base تعاوني.
9. لا guest/client portal أو مشاركة خارجية محكومة.
10. لا time tracking/timesheets/effort/capacity أو توافر وإجازات.
11. لا saved views/My Work ناضج/عمليات bulk أو command palette شامل.
12. لا project templates/recurring tasks/onboarding تفاعلي.
13. لا تطبيق mobile/offline/push؛ responsive web فقط.

### 10.5 فجوات المنصة والتكامل

14. لا Public API أو Webhooks أو integration credentials/scopes.
15. لا rules/automation builder أو marketplace/connectors.
16. لا calendar/video/chat integration، ولا importers من Jira/Asana/Trello/GitHub.
17. لا custom fields/types/views/report builder للمستخدم الإداري.
18. لا SAML/OIDC/SCIM/LDAP ولا capability sets قابلة للضبط.
19. البحث LIKE محلي محدود، والتنبيهات مزامنة دورية داخل التطبيق.
20. import/export/PDF متزامنة؛ لا Jobs ذات progress/retry للأحجام الأكبر.

### 10.6 ما هو خارج النطاق لا عيب حالياً

- المحاسبة والمدفوعات والتحصيل والربحية؛ قرار استبعاد صحيح.
- multi-tenant SaaS؛ لا حاجة إليه ما دام النظام داخلياً لشركة واحدة.
- AI العام؛ ليس أولوية قبل code links/comments/dependencies/API/automation.
- الإنجليزية والوضع الداكن وnative apps؛ تؤجل حتى يثبت طلب مستخدم حقيقي.
- help desk/SLA وproduct discovery وtest management؛ الأفضل تكاملها قبل ابتلاعها داخل المنتج.

السجل الكامل ذو 55 صفاً، مع التصنيف والشدة والأثر والتوصية والمرحلة: [feature-gap-matrix.csv](feature-gap-matrix.csv).

## 11. ما الذي يجب نسخه وما الذي يجب رفضه؟

### أنماط تستحق الاقتباس

| المصدر المرجعي | ما يُقتبس | كيف يُكيّف للنظام |
| --- | --- | --- |
| GitHub/GitLab | ربط issue/branch/PR/MR/check/release | روابط ومزامنة حالة مشتقة؛ يبقى الكود في المنصة الأصلية |
| Linear/Taiga/Plane | backlog وcycle وWIP واختصارات سريعة | backlog خفيف وiteration اختيارية، بلا hierarchy عميقة منذ اليوم الأول |
| OpenProject/YouTrack | blocked-by/dependencies والوقت | علاقة اعتماد أولاً، ثم effort/time إذا أثبت pilot الحاجة |
| Basecamp | نقاش هادئ وclient mode | comments/mentions/watchers ثم بوابة عميل منفصلة لاحقاً |
| Asana/monday/ClickUp | forms/rules/templates/saved views | مجموعة صغيرة محكومة من القوالب والقواعد، لا no-code universe |
| Teamwork/Productive | capacity وMy Work للعمل مع العملاء | effort وتوافر وسعة، من دون ميزانية وربحية أو دفتر حسابات |
| Aha! | ربط الهدف بالإصدار | milestone/release خفيف مرتبط بالمتطلبات والمهام فقط |

### أنماط يجب رفضها الآن

- بناء مستودع Git أو code review أو CI runner داخل النظام.
- تقليد كل صفحة وحقل في Jira أو ClickUp؛ سيهدم بساطة المنتج.
- محاسبة ومدفوعات وتحصيل وربحية؛ طلب المالك صريح: قوالب فواتير فقط.
- AI عام قبل امتلاك بيانات متسقة وAPI وتعليقات واعتماديات.
- marketplace/plugins قبل تثبيت contracts والتحديث والأمن.
- multi-tenancy أو native mobile أو chat كامل دون دليل استعمال.
- custom fields غير محكومة تحول نموذج المجال إلى spreadsheet بلا معنى.

## 12. ترتيب الأولويات

### 0–30 يوماً: جعل الحقيقة متسقة وقابلة للإطلاق

1. حسم Viewer كقراءة فقط وتثبيت مصفوفة الصلاحيات.
2. توحيد timezone وتطبيق week start/weekend أو حذف الإعدادات المضللة.
3. تثبيت Git SHA واحد وتشغيل كل بوابات الاختبار عليه.
4. تهيئة malware scanner حقيقي وبيئة staging آمنة ومراقبة.
5. نسخة off-host/immutable وتمرين restore موثق مع RPO/RTO.
6. قارئ شاشة وzoom 200% وpilot صغير وقياس الرحلات.

### 31–90 يوماً: سد قلب العمل البرمجي بأقل اتساع

1. نموذج provider-neutral لـrepository وdevelopment links مع GitHub/GitLab.
2. comments و@mentions وwatchers على المهمة والمتطلب.
3. blocked-by/dependencies مع كشف الدورة ومؤشر «محجوب».
4. My Work وsaved views وعمليات bulk الأساسية.
5. backlog وiteration اختيارية، لا Scrum إلزامي.
6. أساس Public API/webhooks بصلاحيات وidempotency وسجل تسليم.

### 3–6 أشهر: التخطيط والقياس

- milestone/release خفيف، estimation/cycle time، effort/capacity وتوافر الفريق.
- project templates وrecurring tasks وintake forms.
- calendar integration وasync jobs للاستيراد/PDF والعمليات الثقيلة.
- تحسين البحث والتقارير حسب بيانات pilot، لا حسب catalogue المنافسين.

### 6–12 شهراً: التوسع المشروط فقط

- programs/initiatives وcustom reports/fields محدودة إذا ظهرت حاجة PMO.
- SSO/SCIM أو client portal أو PWA عند وجود مطلب تجاري/أمني مثبت.
- migration connectors لمنصة واحدة تستخدم فعلياً، لا عشرة connectors شكلية.

الخطة المفصلة بمعايير خروج ومخاطر وما لا يُبنى: [roadmap-12-months.md](roadmap-12-months.md).

## 13. القرار الاستراتيجي

### القرار: Hybrid مع حدود ملكية صريحة

| المجال | مصدر الحقيقة |
| --- | --- |
| العميل، جهة الاتصال، المتطلبات، كراسة الإصدارات، الاجتماع والمحضر، المخاطر، القرارات والوثائق | Project Desk |
| repository، branch، commit، PR/MR، build، test، deployment | GitHub أو GitLab أو Azure DevOps |
| حالة التسليم المشتركة | Project Desk يعرض روابط وحالة مشتقة من provider، بلا تحرير مزدوج |
| المحاسبة والتحصيل | خارج Project Desk |

**Build:** حافظ على المجالات المحلية المتمايزة، وأضف طبقة تكامل وتعاون واعتماديات خفيفة.

**Buy/integrate:** استخدم منصة كود وCI جاهزة، وخدمة SSO أو malware scanner عند الحاجة، ولا تحمل الفريق مسؤولية إعادة اختراعها.

**Replace فقط إذا:** أصبحت dependencies/Gantt/time/cost/portfolio أولوية أعلى من العربية والحوكمة المحلية، أو لم تعد الشركة مستعدة لامتلاك التشغيل والأمن والصيانة. عندها OpenProject/YouTrack/Teamwork مرشحون حسب النمط، بينما Jira/GitLab/Azure هم مرشحو lifecycle الهندسي.

**موضع المنتج المقترح:** «مكتب تسليم وحوكمة عربي لشركة برمجيات» وليس «نسخة Jira عربية» ولا «نظام ERP».

## 14. المصادر والتحديث

- فهرس المصادر: [sources.csv](sources.csv)
- ملاحظات المصدر: `sources/`
- الحقائق المتغيرة: [refresh_targets.md](refresh_targets.md)
- المصادر التفصيلية لإدارة العمل: [work-management-sources.csv](work-management-sources.csv)
- التدقيق الخصومي للنظام: [findings/project-desk-adversarial.md](findings/project-desk-adversarial.md)
- مصفوفة أدوات التطوير: [findings/dev-focused-matrix.md](findings/dev-focused-matrix.md)
- مصفوفة إدارة العمل: [findings/work-management-matrix.md](findings/work-management-matrix.md)
- مصفوفة الأنظمة المفتوحة: [findings/opensource-matrix.md](findings/opensource-matrix.md)

يجب تحديث التسعير والخطط وميزات AI والتعريب كل ثلاثة إلى ستة أشهر، وإعادة قياس Project Desk بعد كل موجة roadmap. لا تتغير الخلاصة الاستراتيجية بسبب ميزة تسويقية جديدة منفردة؛ تتغير فقط إذا تغير مصدر الحقيقة أو سياق الشركة أو عبء التشغيل.

# الجزء الثاني: أثر نسخة التطوير على المقارنة

# تحديث المقارنة التنافسية بعد Development v2

> يعتمد هذا التحديث على الدراسة العالمية المؤرخة 13 أغسطس 2026 ومصادرها الرسمية، ويعيد تقييم Project Desk فقط بعد تنفيذ v2. لا يعيد ادعاء تغير قدرات أو أسعار المنافسين.

## 1. ما الذي تغير في خط الأساس؟

| القدرة | قبل v2 | بعد v2 |
|---|---|---|
| Milestones | عناصر timeline عامة | مراحل موزونة، معالم داخل مرحلة، Gates، تنبيهات وصحة. |
| تقدم المشروع | نسبة مهام فقط | tasks أو weighted phases. |
| مشروع بدأ سابقاً | إدخال يدوي متفرق | Wizard ذري وSnapshot انتقال. |
| تنظيم المتطلبات | قائمة وربط مهام | فئات ومجموعات وأنواع وعلاقات ومصادر. |
| تبعيات المتطلبات | غير متاحة | graph محدود مع منع دورات depends_on. |
| تحليل الكراسة | يدوي | PDF/DOCX/OCR + Qwen3 محلي + مراجعة بشرية. |
| تغيير الكراسة | إصدارات بلا delta عميق | جديد/معدل/محذوف/تعارض/استبدال + أثر محتمل. |
| سيادة AI | غير موجود | محلي loopback بلا سحابة أو API key. |

## 2. المزايا الجديدة مقابل السوق

### 2.1 متطلبات عربية محلية أولاً

يملك Project Desk الآن مساراً متكاملاً من كراسة عربية مصورة أو نصية إلى Candidates ومصادر وصفحات وثقة واعتماد. هذه ليست ميزة AI عامة؛ هي Workflow محدد للحوكمة والتتبع، ويستفيد من التشغيل المحلي في البيئات الحساسة.

### 2.2 انتقال المشاريع القائمة

معظم أدوات السوق تسمح باستيراد CSV أو إنشاء مشروع، لكنها لا تقدم دائماً Snapshot انتقال مخصصاً يوثق الخطة والتقدم والمعتمد لحظة إدخال مشروع بدأ سابقاً. هذه ميزة مناسبة لشركات الخدمات التي تتبنى النظام أثناء تنفيذ العقود.

### 2.3 بساطة المرحلة الموزونة

v2 يسد فجوة بين قائمة المهام وPPM المعقد. المستخدم يملك مراحل وأوزاناً وبوابات من دون إدخال ميزانيات أو موارد أو Gantt ثقيل لا يحتاجه النطاق الحالي.

### 2.4 Human-in-the-loop حقيقي

لا ينشئ النموذج متطلباً تلقائياً. الفصل بين Candidate والمتطلب، مع معاملة اعتماد ومصدر وتدقيق، أقوى حوكمة من واجهات «اسأل AI» غير المرتبطة بسجل قرار.

## 3. ما زال المنافسون يتفوقون فيه

| المجال | Project Desk v2 | المنصات الناضجة |
|---|---|---|
| Backlog/Sprint | لا backlog هرمي أو sprint planning متكامل | Jira/Azure/YouTrack/Linear/GitLab أقوى. |
| كود وPR وCI | لا repository graph أو deployment | GitHub/GitLab/Azure تتفوق جذرياً. |
| Gantt/dependencies | تبعية متطلبات فقط، لا critical path | أدوات PPM والعمل المتقدم أوسع. |
| تعاون | لا comments/@mentions/chat | معظم أدوات السوق تقدمها. |
| تكاملات | لا Public API أو webhooks | المنافسون يملكون ecosystems كبيرة. |
| موارد ووقت | لا capacity/timesheets/workload | Productive/Teamwork/ClickUp/Wrike وغيرها أوسع. |
| Mobile | Web responsive فقط | معظم المنصات لها تطبيقات أصلية. |
| Automation | Queue مخصص للتحليل فقط | أدوات السوق تقدم builders وقواعد متعددة. |

## 4. الأثر على التقييم السابق

لا يصح مقارنة الدرجة الرقمية الجديدة بالقديمة دون إعادة تشغيل المصفوفات كلها. لكن اتجاه التغيير واضح:

- تحسن قوي: requirements management، milestone governance، project onboarding، document intelligence، traceability.
- تحسن متوسط: portfolio visibility والتخطيط المرحلي.
- بلا تغير جوهري: agile development، source control، CI/CD، collaboration، integrations، time/capacity.
- مخاطرة جديدة مضبوطة: اعتماد محرك محلي وتبعيات OCR وGPU، يقابلها عدم تكلفة API وسيادة بيانات أعلى.

## 5. التموضع المقترح بعد v2

الوصف الأدق:

> منصة عربية محلية لحوكمة وتسليم مشاريع البرمجيات من العميل والكراسة إلى المراحل والمتطلبات والمهام والقرارات، مع استخراج متطلبات محلي ومراجعة بشرية.

لا يوصى بتسويقه كبديل كامل لـJira/GitLab/Azure DevOps. التموضع الأقوى هو مصدر حقيقة للعميل والنطاق والحوكمة يتكامل لاحقاً مع مصدر حقيقة الكود.

## 6. SWOT المحدث

### نقاط القوة

- عربي/إنجليزي وRTL/LTR في رحلة واحدة.
- مشاريع عملاء وكراسة بإصدارات ومتطلبات ومصادر.
- مراحل موزونة وبوابات من دون تعقيد PPM.
- إدخال مشروع قائم وSnapshot.
- AI محلي بلا اشتراك أو خروج بيانات.
- ملفات خاصة وتدقيق ونسخ مشفرة.
- قوالب فواتير غير محاسبية تحافظ على حدود المنتج.

### نقاط الضعف

- لا تكامل مستودعات أو CI/CD.
- لا comments أو mentions أو API/webhooks.
- Queue محلي وعامل واحد يحدان throughput.
- جودة OCR والنموذج تحتاج مراجعة بشرية دائماً.
- التبعيات المحلية تزيد عبء التثبيت والدعم.
- لا mobile app أو offline mode.

### الفرص

- تكامل read-only مع GitHub/GitLab للربط بين requirement-task-PR-release.
- قوالب تصنيف حسب نوع المشروع.
- مكتبة prompts/versioned schemas داخلية مع تقييم جودة.
- تقارير تغطية requirements-to-tests لاحقاً دون بناء CI.
- سوق الشركات العربية ذات متطلبات سيادة بيانات.

### التهديدات

- منافسون يضيفون AI محلي/خاص أو دعماً عربياً أفضل.
- توقع المستخدم أن AI «دقيق تلقائياً» رغم عقد المراجعة.
- توسع غير منضبط نحو DevOps أو محاسبة يضعف بساطة المنتج.
- بطء 8B/OCR على أجهزة أضعف من بيئة المرجع.

## 7. الأولويات بعد v2

### أولوية 1 - تثبيت المنتج

- commit/SHA وخط إصدار واضح.
- اختبار أمني وrestore drill فعلي.
- تحسين telemetry منقح لزمن التحليل وجودته.
- benchmark على 300 صفحة و1000 متطلب بملفات حقيقية منقحة.

### أولوية 2 - إغلاق حلقة التطوير

- تكامل GitHub/GitLab read-only.
- حقول رابط issue/PR/commit/release على المهمة والمتطلب.
- حالات تحقق واختبار للمتطلب، من دون إعادة بناء CI.

### أولوية 3 - التعاون المحدود

- comments وmentions على المتطلب/المهمة/القرار.
- سجل قرار منظم وعلاقات بالمرحلة والمعلم.
- subscriptions وتفضيلات أدق.

### ما يجب تأجيله

- محاسبة ومدفوعات.
- repository hosting أو pipeline runner.
- automation builder عام.
- multi-tenant قبل وجود حاجة مثبتة.
- تطبيق mobile أصلي قبل قياس استخدام الويب على الهاتف.

## 8. القرار الاستراتيجي

يبقى قرار Hybrid هو الأفضل، لكنه أصبح أقوى بعد v2:

```text
Project Desk = العميل + الكراسة + المتطلبات + المراحل + القرارات + الحوكمة
GitHub/GitLab = الكود + PR/MR + CI/CD + release
التكامل المستقبلي = روابط وأحداث منقحة، لا ازدواج مصدر الحقيقة
```

Development v2 يرفع تميز Project Desk في بداية دورة المشروع وإدارة النطاق، لكنه لا يغير حقيقة أن نهاية دورة التطوير يجب أن تتكامل مع أدوات الكود المتخصصة.

---

**نهاية تحديث المقارنة التنافسية Development v2.**
