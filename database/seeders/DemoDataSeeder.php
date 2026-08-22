<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Risk;
use App\Models\SalesDocument;
use App\Models\Task;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(['email' => 'admin@projectdesk.local'], [
            'name' => 'مدير النظام',
            'phone' => '+218 91 000 0001',
            'job_title' => 'مدير العمليات',
            'global_role' => 'admin',
            'status' => 'active',
            'email_verified_at' => Date::now(),
            'password' => Hash::make('password'),
        ]);
        $manager = User::query()->updateOrCreate(['email' => 'manager@projectdesk.local'], [
            'name' => 'سارة المنصوري',
            'phone' => '+218 92 000 0002',
            'job_title' => 'مديرة مشاريع',
            'global_role' => 'project_manager',
            'status' => 'active',
            'email_verified_at' => Date::now(),
            'password' => Hash::make('password'),
        ]);
        $member = User::query()->updateOrCreate(['email' => 'member@projectdesk.local'], [
            'name' => 'أحمد الترهوني',
            'phone' => '+218 94 000 0003',
            'job_title' => 'مهندس برمجيات',
            'global_role' => 'member',
            'status' => 'active',
            'email_verified_at' => Date::now(),
            'password' => Hash::make('password'),
        ]);

        $client = Client::query()->updateOrCreate(['code' => 'CL-001'], [
            'name' => 'شركة المدار التجريبية',
            'email' => 'info@almadar.example',
            'phone' => '+218 21 000 0000',
            'address' => 'طرابلس، ليبيا',
            'status' => 'active',
        ]);
        $contact = Contact::query()->updateOrCreate(['client_id' => $client->id, 'email' => 'omar@almadar.example'], [
            'name' => 'عمر السنوسي',
            'role' => 'مدير التحول الرقمي',
            'phone' => '+218 91 000 0010',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $projectStatus = WorkflowStatus::query()->where('entity_type', 'project')->where('code', 'active')->firstOrFail();
        $taskNew = WorkflowStatus::query()->where('entity_type', 'task')->where('code', 'new')->firstOrFail();
        $taskProgress = WorkflowStatus::query()->where('entity_type', 'task')->where('code', 'in_progress')->firstOrFail();
        $taskDone = WorkflowStatus::query()->where('entity_type', 'task')->where('code', 'completed')->firstOrFail();
        $requirementStatus = WorkflowStatus::query()->where('entity_type', 'requirement')->where('code', 'approved')->firstOrFail();

        $project = Project::query()->updateOrCreate(['code' => 'PRJ-001'], [
            'name' => 'تطوير بوابة العملاء',
            'description' => 'بيانات محلية تجريبية للتحقق من تدفقات النظام.',
            'client_id' => $client->id,
            'primary_contact_id' => $contact->id,
            'manager_id' => $manager->id,
            'status_id' => $projectStatus->id,
            'priority' => 'high',
            'start_date' => Date::today()->subDays(10),
            'end_date' => Date::today()->addDays(35),
        ]);
        $project->members()->sync([
            $admin->id => ['project_role' => 'manager', 'status' => 'active'],
            $manager->id => ['project_role' => 'manager', 'status' => 'active'],
            $member->id => ['project_role' => 'member', 'status' => 'active'],
        ]);

        $requirement = Requirement::query()->updateOrCreate(['project_id' => $project->id, 'code' => 'REQ-001'], [
            'title' => 'تسجيل دخول العميل',
            'description' => 'تسجيل دخول آمن إلى البوابة.',
            'acceptance_criteria' => 'يستطيع العميل الدخول والخروج واستعادة كلمة المرور.',
            'priority' => 'high',
            'status_id' => $requirementStatus->id,
            'owner_id' => $manager->id,
        ]);

        $tasks = [
            ['TSK-00001', 'تصميم تدفق تسجيل الدخول', $taskDone->id, Date::now()->subDays(8), Date::now()->subDays(5), Date::now()->subDays(5)],
            ['TSK-00002', 'برمجة واجهة تسجيل الدخول', $taskProgress->id, Date::now()->subDays(2), Date::now()->addDays(2), null],
            ['TSK-00003', 'اختبار استعادة كلمة المرور', $taskNew->id, Date::now()->addDays(1), Date::now()->addDays(4), null],
        ];

        foreach ($tasks as [$code, $title, $statusId, $startAt, $dueAt, $completedAt]) {
            $task = Task::query()->updateOrCreate(['project_id' => $project->id, 'code' => $code], [
                'title' => $title,
                'status_id' => $statusId,
                'priority' => 'high',
                'assignee_id' => $member->id,
                'assigned_at' => Date::now()->subDays(9),
                'start_at' => $startAt,
                'due_at' => $dueAt,
                'completed_at' => $completedAt,
            ]);
            $task->requirements()->sync([$requirement->id]);
        }

        Risk::query()->updateOrCreate(['project_id' => $project->id, 'title' => 'تأخر اعتماد نصوص الدخول'], [
            'description' => 'قد يؤثر التأخر في موعد الاختبار.',
            'probability' => 4,
            'impact' => 4,
            'status' => 'open',
            'owner_id' => $manager->id,
            'mitigation' => 'جلسة اعتماد مركزة مع العميل.',
            'due_at' => Date::now()->addDays(2),
        ]);

        $timeline = TimelineEntry::query()->updateOrCreate(['project_id' => $project->id, 'title' => 'اجتماع اعتماد تدفق التسجيل'], [
            'kind' => 'meeting',
            'starts_at' => Date::now()->addDays(1)->setTime(10, 0),
            'ends_at' => Date::now()->addDays(1)->setTime(11, 0),
            'status' => 'planned',
            'owner_id' => $manager->id,
            'note' => 'اجتماع تجريبي.',
        ]);
        $meeting = Meeting::query()->updateOrCreate(['timeline_entry_id' => $timeline->id], [
            'organizer_id' => $manager->id,
            'location' => 'قاعة الاجتماعات',
            'agenda' => 'مراجعة التدفق والقرارات المفتوحة.',
        ]);
        $meeting->attendees()->sync([$manager->id, $member->id]);
        MeetingMinutes::query()->updateOrCreate(['meeting_id' => $meeting->id], [
            'summary' => 'محضر تجريبي لتوضيح الارتباط فقط.',
            'decisions' => 'اعتماد التدفق المبدئي.',
            'action_items' => 'استكمال شاشة الاستعادة.',
            'recorded_by' => $manager->id,
            'recorded_at' => Date::now(),
        ]);

        $proposal = SalesDocument::query()->updateOrCreate(['number' => 'CT-PROP-2026-001'], [
            'type' => 'proposal',
            'title' => 'عرض تطوير بوابة العملاء',
            'status' => 'sent',
            'client_id' => $client->id,
            'project_id' => $project->id,
            'issue_date' => Date::today(),
            'due_date' => Date::today()->addDays(14),
            'currency' => 'LYD',
            'discount_rate' => 10,
            'tax_rate' => 15,
            'created_by' => $admin->id,
        ]);
        $proposal->lineItems()->delete();
        $proposal->lineItems()->createMany([
            ['name' => 'تحليل', 'quantity' => 2, 'unit' => 'مرحلة', 'unit_price' => 1000, 'position' => 1],
            ['name' => 'تطوير', 'quantity' => 3, 'unit' => 'مرحلة', 'unit_price' => 500, 'position' => 2],
        ]);
    }
}
