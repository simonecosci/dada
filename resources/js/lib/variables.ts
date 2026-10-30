export type VariableScope = 'container' | 'compose' | 'host-global';

/** Output of VariableData::forClient, with editable default/value. */
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
 * Builds the payload for VariableSync. For untouched secrets
 * (null value) the default/value keys are omitted: the server keeps
 * the stored secret only if the key is absent.
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
