<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { direction: rtl; color: #173b49; font-family: dejavusans, sans-serif; font-size: 10pt; line-height: 1.55; }
        .header { border-bottom: 3px solid #15a7b5; padding-bottom: 10px; }
        .brand { color: #123b4a; font-size: 22pt; font-weight: bold; }
        .brand span { color: #15a7b5; }
        .code { direction: ltr; text-align: left; color: #657b84; }
        h1 { margin: 18px 0 2px; color: #123b4a; font-size: 21pt; }
        h2 { margin: 16px 0 7px; color: #123b4a; font-size: 13pt; }
        .muted { color: #657b84; font-size: 8.5pt; }
        .meta, .kpis, .list { width: 100%; border-collapse: collapse; }
        .meta td { width: 33.33%; padding: 8px; border: 1px solid #dce7ea; vertical-align: top; }
        .meta small, .kpis small { display: block; color: #70858d; font-size: 8pt; }
        .kpis { margin-top: 12px; }
        .kpis td { width: 20%; padding: 10px; text-align: center; background: #f3f8f9; border: 3px solid white; }
        .kpis strong { color: #087783; font-size: 19pt; }
        .list th { padding: 7px; color: #fff; background: #123b4a; font-size: 8.5pt; }
        .list td { padding: 7px; border-bottom: 1px solid #dce7ea; vertical-align: top; }
        .ltr { direction: ltr; text-align: left; }
        .description { white-space: pre-line; }
        .footer { position: fixed; bottom: -7px; right: 0; left: 0; border-top: 1px solid #dce7ea; padding-top: 6px; color: #657b84; text-align: center; font-size: 7.5pt; }
    </style>
</head>
<body>
<div class="header"><table width="100%"><tr>
    <td><div class="brand">Cloud<span>Tech</span></div><div class="muted">Project Desk · ملخص حالة المشروع</div></td>
    <td class="code"><strong>{{ $project->code }}</strong><br>{{ now()->timezone(config('project-desk.business_timezone'))->format('Y-m-d H:i') }}</td>
</tr></table></div>

<h1>{{ $project->name }}</h1>
@if($project->description)<p class="description">{{ $project->description }}</p>@endif

<table class="meta">
    <tr>
        <td><small>الحالة</small><strong>{{ $project->status->label }}</strong></td>
        <td><small>الأولوية</small><strong>{{ $project->priority }}</strong></td>
        <td><small>المدير</small><strong>{{ $project->manager?->name ?? 'غير محدد' }}</strong></td>
    </tr>
    <tr>
        <td><small>العميل</small><strong>{{ $project->client?->name ?? 'غير محدد' }}</strong></td>
        <td><small>البداية</small><span dir="ltr">{{ $project->start_date?->format('Y-m-d') ?? '—' }}</span></td>
        <td><small>النهاية المستهدفة</small><span dir="ltr">{{ $project->end_date?->format('Y-m-d') ?? '—' }}</span></td>
    </tr>
    <tr>
        <td><small>الصحة المشتقة</small><strong>{{ ['danger' => 'تحتاج تدخلاً', 'attention' => 'تحتاج متابعة', 'healthy' => 'مستقرة'][$metrics['health']] }}</strong></td>
        <td><small>المرحلة الحالية</small><strong>{{ $metrics['current_phase']['title'] ?? 'غير محددة' }}</strong></td>
        <td><small>المعلم القادم</small><strong>{{ $metrics['next_milestone']['title'] ?? $metrics['next_stage']['title'] ?? 'لا يوجد' }}</strong></td>
    </tr>
</table>

<table class="kpis"><tr>
    <td><strong>{{ $metrics['progress'] }}%</strong><small>التقدم المحسوب</small></td>
    <td><strong>{{ $metrics['total_tasks'] }}</strong><small>المهام المحتسبة</small></td>
    <td><strong>{{ $metrics['open_tasks'] }}</strong><small>مهام مفتوحة</small></td>
    <td><strong>{{ $metrics['overdue_tasks'] }}</strong><small>مهام متأخرة</small></td>
    <td><strong>{{ $metrics['requirements'] }}</strong><small>المتطلبات</small></td>
</tr></table>

@if(!empty($metrics['phases']))
<h2>خطة المراحل الموزونة</h2>
<table class="list">
    <thead><tr><th>المرحلة</th><th>الوزن</th><th>التقدم</th><th>الصحة</th><th>المعالم</th></tr></thead>
    <tbody>@foreach($metrics['phases'] as $phase)<tr>
        <td><strong>{{ $phase['title'] }}</strong>@if($phase['awaiting_approval'])<br><span class="muted">بانتظار الاعتماد</span>@endif</td>
        <td>{{ $phase['weight_percent'] }}%</td>
        <td>{{ $phase['progress'] }}%</td>
        <td>{{ ['on_track' => 'في المسار', 'attention' => 'تحتاج متابعة', 'overdue' => 'متأخرة', 'completed' => 'مكتملة'][$phase['health']] ?? $phase['health'] }}</td>
        <td>{{ count($phase['milestones']) }}</td>
    </tr>@endforeach</tbody>
</table>
@endif

<h2>المهام الحالية</h2>
@if($activeTasks->isEmpty())
    <p class="muted">لا توجد مهام مفتوحة حالياً.</p>
@else
<table class="list">
    <thead><tr><th>المهمة</th><th>الحالة</th><th>المسؤول</th><th>النهاية</th></tr></thead>
    <tbody>@foreach($activeTasks as $task)<tr>
        <td><strong dir="ltr">{{ $task->code }}</strong><br>{{ $task->title }}</td>
        <td>{{ $task->status->label }}</td>
        <td>{{ $task->assignee?->name ?? 'غير مسندة' }}</td>
        <td class="ltr">{{ $task->due_at->timezone(config('project-desk.business_timezone'))->format('Y-m-d') }}</td>
    </tr>@endforeach</tbody>
</table>
@endif

<h2>المتطلبات</h2>
@if($project->requirements->isEmpty())
    <p class="muted">لا توجد متطلبات مسجلة.</p>
@else
<table class="list">
    <thead><tr><th>الرمز</th><th>المتطلب</th><th>الحالة</th><th>الأولوية</th></tr></thead>
    <tbody>@foreach($project->requirements as $requirement)<tr>
        <td class="ltr">{{ $requirement->code }}</td>
        <td>{{ $requirement->title }}</td>
        <td>{{ $requirement->status->label }}</td>
        <td>{{ $requirement->priority }}</td>
    </tr>@endforeach</tbody>
</table>
@endif

<div class="footer">CloudTech · Project Desk · نسخة خاصة للاستخدام المصرح</div>
</body>
</html>
