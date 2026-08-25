function csrfToken() {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export async function projectApi<T>(
    url: string,
    init?: RequestInit,
): Promise<T> {
    const isFormData = init?.body instanceof FormData;
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            'X-CSRF-TOKEN': csrfToken(),
            ...init?.headers,
        },
    });
    const payload = (await response.json().catch(() => null)) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!response.ok) {
        throw new Error(
            (payload?.errors && Object.values(payload.errors).flat()[0]) ||
                payload?.message ||
                'تعذر إتمام العملية.',
        );
    }

    return (payload?.data ?? payload) as T;
}
