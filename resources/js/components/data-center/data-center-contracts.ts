export type DataCenterTab = 'import' | 'export' | 'backup';
export type ImportResource = 'clients' | 'tasks';
export type ExportResource = ImportResource | 'projects';
export type ImportFormat = 'xlsx' | 'csv';

export type ImportErrorItem = {
    id?: number;
    sheet?: string | null;
    row_number?: number | null;
    field?: string | null;
    code?: string;
    message: string;
};

export type FileObject = {
    id: number;
    original_name: string;
    size_bytes: number;
    checksum_sha256: string;
};

export type DataJob = {
    id: number;
    type: string;
    resource_type?: string | null;
    format?: string | null;
    status: string;
    file_object_id?: number | null;
    file_object?: FileObject | null;
    summary?: {
        checksum_sha256?: string;
        row_count?: number;
        valid_count?: number;
        error_count?: number;
        committed_count?: number;
        can_commit?: boolean;
        contains_current_admin?: boolean;
        quick_check?: string;
        encrypted?: boolean;
        cipher?: string;
        key_id?: string;
        files_complete?: boolean;
        files_count?: number;
        files_size_bytes?: number;
        files_restored?: number;
        restore_nonce?: string;
        actions?: { create?: number; update?: number };
        preview?: Array<Record<string, unknown>>;
    } | null;
    import_errors?: ImportErrorItem[];
    error_message?: string | null;
    created_at?: string;
    completed_at?: string | null;
};

export type BackupValidationResult = {
    encrypted?: boolean;
    cipher?: string;
    key_id?: string;
    files_complete?: boolean;
    files_count?: number;
    files_size_bytes?: number;
    restore_nonce: string;
};

export type BackupRestoreResult = {
    files_restored?: number;
};
