<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        language: 'yaml' | 'shell' | 'plaintext';
        readonly?: boolean;
        errors?: string[];
    }>(),
    { readonly: false, errors: () => [] },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const container = ref<HTMLDivElement | null>(null);
const loading = ref(true);

type Monaco = typeof import('monaco-editor/editor/editor.api');
let monaco: Monaco | null = null;
let editor: ReturnType<Monaco['editor']['create']> | null = null;
let disposed = false;

async function loadMonaco(): Promise<Monaco> {
    const [api, , , worker] = await Promise.all([
        import('monaco-editor/editor/editor.api'),
        import('monaco-editor/languages/definitions/yaml/register'),
        import('monaco-editor/languages/definitions/shell/register'),
        import('monaco-editor/editor/editor.worker?worker'),
    ]);

    (self as unknown as { MonacoEnvironment: unknown }).MonacoEnvironment = {
        getWorker: () => new worker.default(),
    };

    return api;
}

onMounted(async () => {
    const loaded = await loadMonaco();

    if (disposed || !container.value) {
        return;
    }

    monaco = loaded;
    editor = monaco.editor.create(container.value, {
        value: props.modelValue,
        language: props.language,
        readOnly: props.readonly,
        automaticLayout: true,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        tabSize: 2,
        theme: document.documentElement.classList.contains('dark')
            ? 'vs-dark'
            : 'vs',
    });
    const instance = editor;
    instance.onDidChangeModelContent(() => {
        const value = instance.getValue();

        if (value !== props.modelValue) {
            emit('update:modelValue', value);
        }
    });
    loading.value = false;
});

watch(
    () => props.modelValue,
    (value) => {
        if (editor && editor.getValue() !== value) {
            editor.setValue(value);
        }
    },
);

watch(
    () => props.readonly,
    (readOnly) => editor?.updateOptions({ readOnly }),
);

onBeforeUnmount(() => {
    disposed = true;
    editor?.dispose();
});
</script>

<template>
    <div class="grid gap-2">
        <div
            ref="container"
            class="h-96 w-full overflow-hidden rounded-md border"
            data-test="code-editor"
        >
            <p v-if="loading" class="p-3 text-sm text-muted-foreground">
                Loading editor...
            </p>
        </div>
        <InputError
            v-for="(error, i) in errors"
            :key="i"
            :message="error"
            class="whitespace-pre-line"
        />
    </div>
</template>
