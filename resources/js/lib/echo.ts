import { configureEcho } from '@laravel/echo-vue';

export type ReverbClientConfig = {
    key: string | null;
    host: string | null;
    port: number | null;
    scheme: string | null;
};

/**
 * Configura Echo con i valori runtime condivisi dal server (prop `reverb`).
 * Ordine: prop del server, poi VITE_REVERB_* di build, poi l'origine della pagina.
 */
export function setupEcho(reverb?: ReverbClientConfig | null): void {
    const env = import.meta.env;
    const str = (value: unknown): string | undefined =>
        typeof value === 'string' && value !== '' ? value : undefined;

    const scheme =
        reverb?.scheme ??
        str(env.VITE_REVERB_SCHEME) ??
        window.location.protocol.replace(':', '');
    const host =
        reverb?.host ?? str(env.VITE_REVERB_HOST) ?? window.location.hostname;
    const port = Number(
        reverb?.port ??
            str(env.VITE_REVERB_PORT) ??
            (window.location.port || (scheme === 'https' ? 443 : 80)),
    );

    configureEcho({
        broadcaster: 'reverb',
        key: reverb?.key ?? str(env.VITE_REVERB_APP_KEY) ?? '',
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
