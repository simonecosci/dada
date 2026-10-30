<script setup lang="ts">
import SecretValue from '@/components/dada/SecretValue.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    newVariableRow,
    type VariableRow,
    type VariableScope,
} from '@/lib/variables';

const props = withDefaults(
    defineProps<{
        modelValue: VariableRow[];
        mode: 'catalog' | 'installation' | 'host';
        canReveal: boolean;
        readonly?: boolean;
        errors?: Record<string, string>;
    }>(),
    { readonly: false, errors: () => ({}) },
);

const emit = defineEmits<{ 'update:modelValue': [value: VariableRow[]] }>();

const scopes: VariableScope[] = ['container', 'compose', 'host-global'];
const structural = props.mode !== 'installation';

function update(index: number, patch: Partial<VariableRow>) {
    emit(
        'update:modelValue',
        props.modelValue.map((row, i) =>
            i === index ? { ...row, ...patch } : row,
        ),
    );
}

/** An empty field means "untouched" (null): for an existing secret the value is left unchanged. */
function setField(index: number, field: 'default' | 'value', raw: string) {
    update(index, { [field]: raw === '' ? null : raw });
}

function add() {
    emit('update:modelValue', [
        ...props.modelValue,
        newVariableRow(props.mode === 'host' ? 'host-global' : 'container'),
    ]);
}

function remove(index: number) {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

function placeholder(row: VariableRow, field: 'default' | 'value') {
    const has = field === 'default' ? row.has_default : row.has_value;

    return row.secret && row.id && has ? 'Leave empty to keep unchanged' : '';
}

function error(index: number, field: string) {
    return props.errors[`variables.${index}.${field}`];
}
</script>

<template>
    <div class="grid gap-3" data-test="variables-editor">
        <div
            v-for="(row, i) in modelValue"
            :key="row.id ?? `new-${i}`"
            class="grid gap-3 rounded-lg border p-3 md:grid-cols-2"
        >
            <div class="grid gap-1">
                <label class="text-xs font-medium">Key</label>
                <Input
                    :model-value="row.key"
                    :disabled="readonly || !structural"
                    placeholder="VARIABLE_NAME"
                    autocomplete="off"
                    @update:model-value="update(i, { key: String($event) })"
                />
                <p v-if="error(i, 'key')" class="text-sm text-red-600">
                    {{ error(i, 'key') }}
                </p>
            </div>

            <div v-if="mode !== 'host'" class="grid gap-1">
                <label class="text-xs font-medium">Scope</label>
                <select
                    :value="row.scope"
                    :disabled="readonly || !structural"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    @change="
                        update(i, {
                            scope: ($event.target as HTMLSelectElement)
                                .value as VariableScope,
                        })
                    "
                >
                    <option v-for="s in scopes" :key="s" :value="s">
                        {{ s }}
                    </option>
                </select>
                <p v-if="error(i, 'scope')" class="text-sm text-red-600">
                    {{ error(i, 'scope') }}
                </p>
            </div>

            <div class="grid gap-1 md:col-span-2">
                <label class="text-xs font-medium">Description</label>
                <Input
                    :model-value="row.description ?? ''"
                    :disabled="readonly || !structural"
                    autocomplete="off"
                    @update:model-value="
                        update(i, { description: String($event) || null })
                    "
                />
            </div>

            <div class="flex flex-wrap items-center gap-4 md:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="row.secret"
                        :disabled="readonly || !structural"
                        @change="
                            update(i, {
                                secret: ($event.target as HTMLInputElement)
                                    .checked,
                            })
                        "
                    />
                    Secret
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="row.required"
                        :disabled="readonly || !structural"
                        @change="
                            update(i, {
                                required: ($event.target as HTMLInputElement)
                                    .checked,
                            })
                        "
                    />
                    Required
                </label>
            </div>

            <!-- Editable default (catalog, host) or read-only default + value (installation) -->
            <div
                v-if="mode === 'catalog' || mode === 'host'"
                class="grid gap-1 md:col-span-2"
            >
                <label class="text-xs font-medium">Default</label>
                <Input
                    :model-value="row.default ?? ''"
                    :type="row.secret ? 'password' : 'text'"
                    :disabled="readonly"
                    :placeholder="placeholder(row, 'default')"
                    autocomplete="new-password"
                    @update:model-value="setField(i, 'default', String($event))"
                />
                <SecretValue
                    v-if="canReveal && row.secret && row.id && row.has_default"
                    :variable-id="row.id"
                    field="default"
                />
            </div>

            <template v-else>
                <div class="grid gap-1">
                    <label class="text-xs font-medium">Default</label>
                    <SecretValue
                        v-if="
                            canReveal && row.secret && row.id && row.has_default
                        "
                        :variable-id="row.id"
                        field="default"
                    />
                    <code
                        v-else
                        class="rounded bg-muted px-2 py-1 text-xs break-all"
                    >
                        {{
                            row.secret
                                ? row.has_default
                                    ? '••••••••'
                                    : '—'
                                : (row.default ?? '—')
                        }}
                    </code>
                </div>

                <div class="grid gap-1">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            :checked="row.use_default"
                            :disabled="readonly"
                            @change="
                                update(i, {
                                    use_default: (
                                        $event.target as HTMLInputElement
                                    ).checked,
                                })
                            "
                        />
                        Use the default
                    </label>
                </div>

                <div v-if="!row.use_default" class="grid gap-1 md:col-span-2">
                    <label class="text-xs font-medium">Value</label>
                    <Input
                        :model-value="row.value ?? ''"
                        :type="row.secret ? 'password' : 'text'"
                        :disabled="readonly"
                        :placeholder="placeholder(row, 'value')"
                        autocomplete="new-password"
                        @update:model-value="
                            setField(i, 'value', String($event))
                        "
                    />
                    <SecretValue
                        v-if="
                            canReveal && row.secret && row.id && row.has_value
                        "
                        :variable-id="row.id"
                        field="value"
                    />
                </div>
            </template>

            <div v-if="structural && !readonly" class="md:col-span-2">
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    @click="remove(i)"
                >
                    Remove
                </Button>
            </div>
        </div>

        <div v-if="structural && !readonly">
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="add-variable"
                @click="add"
            >
                Add variable
            </Button>
        </div>
    </div>
</template>
