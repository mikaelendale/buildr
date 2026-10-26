function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Small JSON helper for the few non-Inertia endpoints (claim checks,
 * onboarding progress, slug checks). Sends Laravel's XSRF token.
 */
export async function requestJson<T>(
    url: string,
    method: 'GET' | 'POST' = 'GET',
    body?: unknown,
): Promise<{ ok: boolean; status: number; data: T }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    let data = {} as T;

    try {
        data = (await response.json()) as T;
    } catch {
        // Empty or non-JSON body (e.g. 429 page).
    }

    return { ok: response.ok, status: response.status, data };
}
