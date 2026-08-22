const protectedContentKeys = new Set([
    'address',
    'agenda',
    'assignee',
    'body',
    'client',
    'client_name',
    'code',
    'company_name',
    'contact_name',
    'content',
    'created_by',
    'description',
    'display_name',
    'email',
    'file_name',
    'filename',
    'job_title',
    'legal_name',
    'location',
    'name',
    'note',
    'notes',
    'original_name',
    'phone',
    'project',
    'project_code',
    'project_name',
    'reference',
    'registration_number',
    'subject',
    'summary',
    'tax_number',
    'title',
    'updated_by',
    'uploader',
    'website',
]);

function normalizedKey(key: string): string {
    return key
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .replace(/[^A-Za-z0-9]+/g, '_')
        .toLowerCase();
}

export function collectProtectedContent(root: unknown): ReadonlySet<string> {
    const protectedValues = new Set<string>();
    const visited = new WeakSet<object>();
    const pending: Array<{ key: string | null; value: unknown }> = [
        { key: null, value: root },
    ];

    while (pending.length > 0) {
        const item = pending.pop();

        if (!item) {
            continue;
        }

        if (typeof item.value === 'string') {
            if (item.key && protectedContentKeys.has(normalizedKey(item.key))) {
                const normalizedValue = item.value.trim();

                if (normalizedValue) {
                    protectedValues.add(normalizedValue);
                }
            }

            continue;
        }

        if (typeof item.value !== 'object' || item.value === null) {
            continue;
        }

        if (visited.has(item.value)) {
            continue;
        }

        visited.add(item.value);

        if (Array.isArray(item.value)) {
            item.value.forEach((value) =>
                pending.push({ key: item.key, value }),
            );

            continue;
        }

        Object.entries(item.value).forEach(([key, value]) =>
            pending.push({ key, value }),
        );
    }

    return protectedValues;
}
