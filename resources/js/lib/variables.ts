export type VariableScope = 'container' | 'compose' | 'host-global';

/** Output di VariableData::forClient, con default/value modificabili. */
export type VariableRow = {
    id?: number | null;
    key: string;
    description: string | null;
    required: boolean;
    secret: boolean;
    scope: VariableScope;
    use_default: boolean;
    default: string | null;
    value: string | null;
    has_default?: boolean;
    has_value?: boolean;
};

export function newVariableRow(
    scope: VariableScope = 'container',
): VariableRow {
    return {
        id: null,
        key: '',
        description: null,
        required: false,
        secret: false,
        scope,
        use_default: true,
        default: null,
        value: null,
    };
}

/**
 * Costruisce il payload per VariableSync. Per i segreti non toccati
 * (valore null) le chiavi default/value vengono omesse: il server mantiene
 * il segreto memorizzato solo se la chiave è assente.
 */
export function variablesPayload(
    rows: VariableRow[],
): Record<string, unknown>[] {
    return rows.map((row) => {
        const payload: Record<string, unknown> = {
            key: row.key,
            description: row.description,
            required: row.required,
            secret: row.secret,
            scope: row.scope,
            use_default: row.use_default,
        };

        if (row.id) {
            payload.id = row.id;
        }

        for (const field of ['default', 'value'] as const) {
            if (row.secret && row[field] === null) {
                continue;
            }

            payload[field] = row[field];
        }

        return payload;
    });
}
