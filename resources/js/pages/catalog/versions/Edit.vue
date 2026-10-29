<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Catalog/ProjectController';
import ProjectVersionController from '@/actions/App/Http/Controllers/Catalog/ProjectVersionController';
import CodeEditor from '@/components/dada/CodeEditor.vue';
import CommandsEditor, {
    type CommandRow,
} from '@/components/dada/CommandsEditor.vue';
import VariablesEditor from '@/components/dada/VariablesEditor.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { variablesPayload, type VariableRow } from '@/lib/variables';

const props = defineProps<{
    project: { id: number; name: string; slug: string };
    version: {
        id: number;
        version: string;
        notes: string | null;
        status: 'draft' | 'published';
    };
    compose_yaml: string;
    commands: CommandRow[];
    variables: VariableRow[];
    canEdit: boolean;
    canManage: boolean;
    canReveal: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Catalogo', href: ProjectController.index() }],
    },
});

const form = useForm({
    version: props.version.version,
    notes: props.version.notes ?? '',
    compose_yaml: props.compose_yaml,
    commands: props.commands as CommandRow[],
    variables: props.variables as VariableRow[],
});

const published = computed(() => props.version.status === 'published');

// Nomi dei servizi dal YAML corrente (chiavi di secondo livello sotto "services:").
const services = computed(() => {
    const names: string[] = [];
    let inServices = false;

    for (const line of form.compose_yaml.split('\n')) {
        if (/^services:\s*$/.test(line)) {
            inServices = true;
        } else if (/^\S/.test(line)) {
            inServices = false;
        } else if (inServices) {
            const match = line.match(
                /^ {1,4}([A-Za-z0-9][A-Za-z0-9_.-]*):\s*$/,
            );

            if (match && !names.includes(match[1])) {
                names.push(match[1]);
            }
        }
    }

    return names;
});

const composeErrors = computed(() =>
    form.errors.compose_yaml ? form.errors.compose_yaml.split('\n') : [],
);
const commandsErrors = computed(() =>
    form.errors.commands ? form.errors.commands.split('\n') : [],
);
const errorMap = computed(() => form.errors as Record<string, string>);

function save() {
    form.transform((data) => ({
        ...data,
        variables: variablesPayload(data.variables),
    })).put(ProjectVersionController.update(props.version.id).url, {
        preserveScroll: true,
    });
}

function publish() {
    if (confirm('Pubblicare la versione? Dopo non sarà più modificabile.')) {
        router.post(
            ProjectVersionController.publish(props.version.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

const cloneVersion = ref('');
const cloneError = ref<string>();

function clone() {
    router.post(
        ProjectVersionController.clone(props.version.id).url,
        { version: cloneVersion.value },
        {
            onError: (errors) => (cloneError.value = errors.version),
        },
    );
}

function destroy() {
    if (confirm('Cancellare questa bozza?')) {
        router.delete(ProjectVersionController.destroy(props.version.id).url);
    }
}
</script>

<template>
    <Head :title="`${project.name} ${version.version}`" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="`${project.name} ${version.version}`"
                :description="
                    published
                        ? 'Versione pubblicata: sola lettura'
                        : 'Bozza modificabile'
                "
            />
            <Badge :variant="published ? 'default' : 'secondary'">
                {{ published ? 'Pubblicata' : 'Bozza' }}
            </Badge>
        </div>

        <InputError :message="form.errors.version" />

        <div class="grid gap-2">
            <Label for="version">Versione</Label>
            <Input
                id="version"
                v-model="form.version"
                :disabled="!canEdit"
                autocomplete="off"
            />
        </div>

        <div class="grid gap-2">
            <Label for="notes">Note</Label>
            <textarea
                id="notes"
                v-model="form.notes"
                :disabled="!canEdit"
                rows="3"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
            <InputError :message="form.errors.notes" />
        </div>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Compose</h2>
            <CodeEditor
                v-model="form.compose_yaml"
                language="yaml"
                :readonly="!canEdit"
                :errors="composeErrors"
            />
        </section>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Comandi</h2>
            <CommandsEditor
                v-model="form.commands"
                :services="services"
                :readonly="!canEdit"
            />
            <InputError
                v-for="(e, i) in commandsErrors"
                :key="i"
                :message="e"
            />
        </section>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Variabili</h2>
            <VariablesEditor
                v-model="form.variables"
                mode="catalog"
                :can-reveal="canReveal"
                :readonly="!canEdit"
                :errors="errorMap"
            />
        </section>

        <div v-if="canEdit" class="flex items-center gap-3">
            <Button
                :disabled="form.processing"
                data-test="save-version"
                @click="save"
            >
                Salva
            </Button>
            <Button
                variant="outline"
                data-test="publish-version"
                @click="publish"
            >
                Pubblica
            </Button>
            <Button variant="destructive" @click="destroy"
                >Cancella bozza</Button
            >
        </div>

        <div v-if="canManage" class="grid max-w-md gap-2">
            <Label for="clone-version">Clona come nuova bozza</Label>
            <div class="flex gap-2">
                <Input
                    id="clone-version"
                    v-model="cloneVersion"
                    placeholder="Nuova versione"
                    autocomplete="off"
                />
                <Button
                    variant="outline"
                    data-test="clone-version"
                    @click="clone"
                >
                    Clona
                </Button>
            </div>
            <InputError :message="cloneError" />
        </div>
    </div>
</template>
