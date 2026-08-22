export const navigationEnglish: Record<string, string> = {
    '+٩٩': '99+',
    '، ': ', ',
    'ابحث في المشاريع والمهام والمتطلبات والعملاء والفريق والمستندات':
        'Search projects, tasks, requirements, clients, team members, and documents',
    'ابحث في العمل…': 'Search the workspace…',
    'التنبيهات متوقفة': 'Notifications are off',
    'التنبيهات متوقفة حسب إعدادات حسابك أو النظام.':
        'Notifications are off based on your account or system settings.',
    'التنبيهات، لا توجد عناصر جديدة': 'Notifications, no new items',
    'التنقل الرئيسي': 'Main navigation',
    'التنقل بين أقسام Project Desk.': 'Navigate between Project Desk sections.',
    'القائمة الجانبية': 'Sidebar',
    'إغلاق مركز التنبيهات': 'Close notification center',
    'تبديل القائمة الجانبية': 'Toggle sidebar',
    'تعذر إكمال البحث. حاول مرة أخرى.':
        'Search could not be completed. Try again.',
    'جارٍ البحث': 'Searching',
    'جارٍ البحث…': 'Searching…',
    'غرفة القيادة': 'Command center',
    'فتح البحث العام': 'Open global search',
    'كل شيء تحت السيطرة': 'Everything is under control',
    'لا توجد نتائج مطابقة.': 'No matching results.',
    'لا توجد مهام متأخرة أو مواعيد قريبة ضمن المهلة الحالية.':
        'There are no overdue tasks or upcoming deadlines within the current window.',
    'مراجعة التفضيلات': 'Review preferences',
    'مركز التنبيهات': 'Notification center',
    'نتائج البحث العام': 'Global search results',
    'نعرض أحدث': 'Showing the latest',
    'راجع تفضيلات حسابك؛ وقد تكون سياسة النظام العامة معطّلة أيضاً.':
        'Review your account preferences; the system-wide policy may also be disabled.',
};

export const navigationPatterns: Array<{
    source: RegExp;
    target: string;
}> = [
    {
        source: /^التنبيهات، (.+) عناصر تحتاج الانتباه$/u,
        target: 'Notifications, $1 items need attention',
    },
    {
        source: /^المواعيد خلال (.+) ساعة بتوقيت طرابلس$/u,
        target: 'Deadlines within $1 hours, Tripoli time',
    },
    {
        source: /^مهمة متأخرة: (.+)، (.+)$/u,
        target: 'Overdue task: $1, $2',
    },
    {
        source: /^موعد مهمة قريب: (.+)، (.+)$/u,
        target: 'Upcoming task deadline: $1, $2',
    },
    {
        source: /^اجتماع قريب: (.+)، (.+)$/u,
        target: 'Upcoming meeting: $1, $2',
    },
    {
        source: /^(?!.*(?: من |بالمئة|مهام مفتوحة،))(.+): (.+)، (.+)$/u,
        target: '$1: $2, $3',
    },
    {
        source: /^لديك تغييرات غير محفوظة في (?!(?:المتطلب|المخاطرة|المشكلة|بند الجدول الزمني|الاجتماع|محضر الاجتماع)\.)(.+)\. هل تريد تجاهلها؟$/u,
        target: 'You have unsaved changes to $1. Do you want to discard them?',
    },
    {
        source: /^اكتملت الاستعادة([^ ].*)?\. سيعاد تحميل النظام للتأكد من الجلسة والبيانات\.$/u,
        target: 'Restore completed$1. The system will reload to verify the session and data.',
    },
    {
        source: /^نسخة #(.+)$/u,
        target: 'Backup #$1',
    },
    {
        source: /^صفحات (?!(?:نظرة عامة|المتطلبات|المهام|الجدول الزمني|الاجتماعات والمحاضر|المخاطر|المشكلات|الفريق|الوثائق|العميل|النشاط)$)(.+)$/u,
        target: '$1 pages',
    },
];
