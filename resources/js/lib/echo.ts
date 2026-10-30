import { configureEcho } from '@laravel/echo-vue';

export type ReverbClientConfig = {
    key: string | null;
    host: string | null;
    port: number | null;
    scheme: string | null;
};

/**
 * Configures Echo with the runtime values shared by the server (`reverb` prop).
 * Order: server prop, then build-time VITE_REVERB_*, then the page origin.
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
