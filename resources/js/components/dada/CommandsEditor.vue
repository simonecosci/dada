<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type CommandRow = {
    name: string;
    description?: string | null;
    target: string;
    command: string;
    dangerous: boolean;
};

const props = withDefaults(
    defineProps<{
        modelValue: CommandRow[];
        services: string[];
        readonly?: boolean;
    }>(),
    { readonly: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: CommandRow[]] }>();

const targets = computed(() => [
    'host',
    ...props.services.map((s) => `service:${s}`),
]);

function update(index: number, patch: Partial<CommandRow>) {
    emit(
        'update:modelValue',
        props.modelValue.map((row, i) =>
            i === index ? { ...row, ...patch } : row,
        ),
    );
}

function add() {
    emit('update:modelValue', [
        ...props.modelValue,
        {
            name: '',
            description: null,
            target: 'host',
            command: '',
            dangerous: false,
        },
    ]);
}

function remove(index: number) {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}
</script>

<template>
    <div class="grid gap-3" data-test="commands-editor">
        <div
            v-for="(row, i) in modelValue"
            :key="i"
            class="grid gap-3 rounded-lg border p-3 md:grid-cols-2"
        >
            <div class="grid gap-1">
                <label class="text-xs font-medium">Name</label>
                <Input
                    :model-value="row.name"
                    :disabled="readonly"
                    autocomplete="off"
                    @update:model-value="update(i, { name: String($event) })"
                />
            </div>
            <div class="grid gap-1">
                <label class="text-xs font-medium">Target</label>
                <select
                    :value="row.target"
                    :disabled="readonly"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    @change="
                        update(i, {
                            target: ($event.target as HTMLSelectElement).value,
                        })
                    "
                >
                    <option v-for="t in targets" :key="t" :value="t">
                        {{ t }}
                    </option>
                    <option
                        v-if="!targets.includes(row.target)"
                        :value="row.target"
                    >
                        {{ row.target }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1 md:col-span-2">
                <label class="text-xs font-medium">Description</label>
                <Input
                    :model-value="row.description ?? ''"
                    :disabled="readonly"
                    autocomplete="off"
                    @update:model-value="
                        update(i, { description: String($event) || null })
                    "
                />
            </div>
            <div class="grid gap-1 md:col-span-2">
                <label class="text-xs font-medium">Command</label>
                <Input
                    :model-value="row.command"
                    :disabled="readonly"
                    class="font-mono"
                    autocomplete="off"
                    @update:model-value="update(i, { command: String($event) })"
                />
            </div>
            <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input
                    type="checkbox"
                    :checked="row.dangerous"
                    :disabled="readonly"
                    @change="
                        update(i, {
                            dangerous: ($event.target as HTMLInputElement)
                                .checked,
                        })
                    "
                />
                Dangerous (requires confirmation)
            </label>
            <div v-if="!readonly" class="md:col-span-2">
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

        <div v-if="!readonly">
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="add-command"
                @click="add"
            >
                Add command
            </Button>
        </div>
    </div>
</template>
