<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import DeploymentHostController from '@/actions/App/Http/Controllers/Deployments/DeploymentHostController';
import InstallationController from '@/actions/App/Http/Controllers/Deployments/InstallationController';
import CodeEditor from '@/components/dada/CodeEditor.vue';
import CommandsEditor, {
    type CommandRow,
} from '@/components/dada/CommandsEditor.vue';
import RestartControls from '@/components/dada/RestartControls.vue';
import VariablesEditor from '@/components/dada/VariablesEditor.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { variablesPayload, type VariableRow } from '@/lib/variables';

const props = defineProps<{
    deployment: { id: number; name: string; slug: string };
    deploymentHost: { id: number; path: string };
    installation: {
        id: number;
        slug: string;
        order: number;
        project: string;
        version: string;
    };
    services: string[];
    compose_yaml: string;
    commands: CommandRow[];
    variables: VariableRow[];
    canOperate: boolean;
    canReveal: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

const form = useForm({
    slug: props.installation.slug,
    order: props.installation.order,
    compose_yaml: props.compose_yaml,
    commands: props.commands as CommandRow[],
    variables: props.variables as VariableRow[],
});

// Service names from the current YAML (second-level keys under "services:").
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
    })).put(InstallationController.update(props.installation.id).url, {
        preserveScroll: true,
        // Realign the rows with the updated props (secrets go back to "untouched").
        onSuccess: () => {
            form.variables = props.variables.map((v) => ({ ...v }));
            form.defaults({
                slug: form.slug,
                order: form.order,
                compose_yaml: form.compose_yaml,
                commands: form.commands,
                variables: form.variables,
            });
            form.clearErrors();
        },
    });
}

function destroy() {
    if (confirm(`Remove installation ${props.installation.slug}?`)) {
        router.delete(
            InstallationController.destroy(props.installation.id).url,
        );
    }
}
</script>

<template>
    <Head :title="`Installation ${installation.slug}`" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <Heading
            :title="`${installation.project} ${installation.version}`"
            :description="`${deployment.name} · ${deploymentHost.path}`"
        />

        <section v-if="canOperate" class="flex flex-col gap-3">
            <h2 class="text-sm font-medium">Services</h2>
            <div class="flex items-center gap-3">
                <span class="w-40 text-sm text-muted-foreground">
                    Whole installation
                </span>
                <RestartControls
                    :deployment-host-id="deploymentHost.id"
                    :installation-slug="installation.slug"
                />
            </div>
            <div
                v-for="service in services"
                :key="service"
                class="flex items-center gap-3"
            >
                <span class="w-40 truncate text-sm">{{ service }}</span>
                <RestartControls
                    :deployment-host-id="deploymentHost.id"
                    :installation-slug="installation.slug"
                    :service="service"
                />
            </div>
        </section>

        <div class="grid max-w-md gap-4 md:grid-cols-2">
            <div class="grid gap-2">
                <Label for="slug">Slug</Label>
                <Input
                    id="slug"
                    v-model="form.slug"
                    :disabled="!canOperate"
                    autocomplete="off"
                />
                <InputError :message="form.errors.slug" />
            </div>
            <div class="grid gap-2">
                <Label for="order">Order</Label>
                <Input
                    id="order"
                    v-model.number="form.order"
                    type="number"
                    :disabled="!canOperate"
                    min="0"
                />
                <InputError :message="form.errors.order" />
            </div>
        </div>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Compose</h2>
            <CodeEditor
                v-model="form.compose_yaml"
                language="yaml"
                :readonly="!canOperate"
                :errors="composeErrors"
            />
        </section>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Commands</h2>
            <CommandsEditor
                v-model="form.commands"
                :services="services"
                :readonly="!canOperate"
            />
            <InputError
                v-for="(e, i) in commandsErrors"
                :key="i"
                :message="e"
            />
        </section>

        <section class="grid gap-2">
            <h2 class="text-lg font-medium">Variables</h2>
            <VariablesEditor
                v-model="form.variables"
                mode="installation"
                :can-reveal="canReveal"
                :readonly="!canOperate"
                :errors="errorMap"
            />
        </section>

        <div class="flex items-center gap-3">
            <Button
                v-if="canOperate"
                :disabled="form.processing"
                data-test="save-installation"
                @click="save"
            >
                Save
            </Button>
            <Button v-if="canOperate" variant="destructive" @click="destroy"
                >Remove</Button
            >
            <Button variant="outline" as-child>
                <Link
                    :href="
                        DeploymentHostController.show([
                            deployment.id,
                            deploymentHost.id,
                        ])
                    "
                >
                    Back to host
                </Link>
            </Button>
        </div>
    </div>
</template>
