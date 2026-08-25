import {
    createLocaleDateTimeFormatter,
    createLocaleNumberFormatter,
} from '@/i18n/formatters';
import type { Project } from './project-show-types';

export const numberFormatter = createLocaleNumberFormatter();
const dateFormatter = createLocaleDateTimeFormatter({
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

export const priorityLabels: Record<string, string> = {
    low: 'منخفضة',
    medium: 'متوسطة',
    high: 'عالية',
    critical: 'حرجة',
};

const healthLabels: Record<string, string> = {
    healthy: 'مستقر',
    attention: 'يحتاج انتباهاً',
    danger: 'مهدد',
};

export function healthLabel(value?: string | null) {
    return value ? (healthLabels[value] ?? value) : 'غير محسوبة';
}

export function formatDate(value?: string | null) {
    if (!value) {
        return 'غير محدد';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

export function formatMetric(value?: number | null, suffix = '') {
    return typeof value === 'number'
        ? `${numberFormatter.format(value)}${suffix}`
        : '—';
}

export function clientName(client: Project['client']) {
    if (!client) {
        return 'دون عميل';
    }

    return typeof client === 'string' ? client : client.name || 'دون عميل';
}

export function formatFileSize(value: number) {
    return value < 1024 * 1024
        ? `${Math.ceil(value / 1024)} KB`
        : `${(value / 1024 / 1024).toFixed(1)} MB`;
}
