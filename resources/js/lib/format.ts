const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

export function formatCount(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return compact.format(value);
}

export function formatMonth(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const [year, month] = value.split('-').map(Number);

    if (!year || !month) {
        return value;
    }

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('en', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * "Jan – Jun 2026", or "Nov 2025 – Feb 2026" across years.
 */
export function formatMonthRange(
    from: string | null | undefined,
    to: string | null | undefined,
): string {
    if (!from) {
        return formatMonth(to);
    }

    if (!to || to === from) {
        return formatMonth(from);
    }

    if (from.slice(0, 4) === to.slice(0, 4)) {
        const [year, month] = from.split('-').map(Number);
        const short = new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString(
            'en',
            { month: 'short', timeZone: 'UTC' },
        );

        return `${short} – ${formatMonth(to)}`;
    }

    return `${formatMonth(from)} – ${formatMonth(to)}`;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString('en', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function formatHour(hour: number | null | undefined): string {
    if (hour === null || hour === undefined) {
        return '—';
    }

    const suffix = hour >= 12 ? 'pm' : 'am';
    const h = hour % 12 === 0 ? 12 : hour % 12;

    return `${h}${suffix}`;
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

export function plural(
    count: number,
    word: string,
    pluralWord?: string,
): string {
    return `${count} ${count === 1 ? word : (pluralWord ?? `${word}s`)}`;
}
