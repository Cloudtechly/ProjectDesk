export function scopedXlsxUrl(
    resource: 'clients' | 'projects' | 'tasks',
    filters?: object | null,
) {
    const query = new URLSearchParams();

    Object.entries(filters ?? {}).forEach(([key, value]) => {
        if (
            !['view', 'per_page'].includes(key) &&
            value !== undefined &&
            value !== null &&
            value !== ''
        ) {
            query.set(key, String(value));
        }
    });

    const serialized = query.toString();

    return `/exports/xlsx/${resource}${serialized ? `?${serialized}` : ''}`;
}
