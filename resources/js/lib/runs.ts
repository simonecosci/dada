export type RunStatus = 'queued' | 'running' | 'succeeded' | 'failed';

export type RunSummary = {
    id: number;
    type: 'deploy' | 'restart';
    status: RunStatus;
    user: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type LogLine = {
    id: number;
    deployment_host_id: number | null;
    level: 'info' | 'step' | 'output' | 'error';
    message: string;
    created_at: string | null;
};

export const statusLabels: Record<RunStatus, string> = {
    queued: 'In coda',
    running: 'In corso',
    succeeded: 'Riuscita',
    failed: 'Fallita',
};

export const typeLabels: Record<RunSummary['type'], string> = {
    deploy: 'Deploy',
    restart: 'Restart',
};

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString('it-IT') : '-';
}
