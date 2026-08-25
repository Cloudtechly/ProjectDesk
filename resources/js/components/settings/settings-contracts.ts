import type { LucideIcon } from 'lucide-react';

export type SettingsData = {
    general: {
        company_name: string | null;
        timezone: string;
    };
    company: {
        display_name: string | null;
        legal_name: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        website: string | null;
        tax_number: string | null;
        registration_number: string | null;
        logo_asset: '/brand/cloudtech-logo.svg';
        invoice_prefix: string;
        number_padding: number;
    };
    notifications: {
        enabled: boolean;
        overdue_tasks: boolean;
        upcoming_tasks: boolean;
        meetings: boolean;
        milestones: boolean;
        lead_hours: number;
    };
    automatic_backup: {
        enabled: boolean;
        frequency: 'daily' | 'weekly';
        time: string;
        retention_count: number;
    };
    calendar: {
        week_start: number;
        weekend_days: number[];
    };
    local_ai: {
        enabled: boolean;
        auto_analyze: boolean;
        model: string;
        context_size: number;
        max_pages: number;
    };
};

export type EditableGroup = keyof SettingsData;
export type Section = EditableGroup | 'workflows';
export type WorkflowEntity = 'project' | 'task' | 'requirement';

export type WorkflowStatus = {
    id: number;
    code: string;
    label: string;
    semantic: string;
    color: string;
    position: number;
    is_active: boolean;
    usage_count: number;
};

export type LocalEngineStatus = {
    ollama?: { available: boolean; models: string[] };
    model_installed?: boolean;
    gpu?: {
        available: boolean;
        name?: string;
        memory_total_mb?: number;
        memory_free_mb?: number;
    };
    extractors?: {
        poppler?: boolean;
        pdf_images?: boolean;
        tesseract?: boolean;
        ocr_languages?: { ara?: boolean; eng?: boolean };
    };
    privacy?: { endpoint: string; cloud_enabled: boolean };
};

export type SettingsSection = {
    id: Section;
    label: string;
    description: string;
    icon: LucideIcon;
};

export const fallbackSettings: SettingsData = {
    general: { company_name: null, timezone: 'Africa/Tripoli' },
    company: {
        display_name: null,
        legal_name: null,
        email: null,
        phone: null,
        address: null,
        website: 'https://cloudtech.ly',
        tax_number: null,
        registration_number: null,
        logo_asset: '/brand/cloudtech-logo.svg',
        invoice_prefix: 'CT-INV',
        number_padding: 3,
    },
    notifications: {
        enabled: true,
        overdue_tasks: true,
        upcoming_tasks: true,
        meetings: true,
        milestones: true,
        lead_hours: 24,
    },
    automatic_backup: {
        enabled: false,
        frequency: 'daily',
        time: '02:00',
        retention_count: 30,
    },
    calendar: { week_start: 0, weekend_days: [5, 6] },
    local_ai: {
        enabled: false,
        auto_analyze: false,
        model: 'qwen3:8b-q4_K_M',
        context_size: 8192,
        max_pages: 300,
    },
};
