<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import DeploymentController from "@/actions/App/Http/Controllers/Deployments/DeploymentController";
import DeploymentHostController from "@/actions/App/Http/Controllers/Deployments/DeploymentHostController";
import InstallationController from "@/actions/App/Http/Controllers/Deployments/InstallationController";
import Heading from "@/components/Heading.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

type ProjectOption = {
    id: number;
    name: string;
    slug: string;
    versions: { id: number; version: string }[];
};

const props = defineProps<{
    deployment: { id: number; name: string; slug: string };
    deploymentHost: { id: number; path: string };
    projects: ProjectOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Deployment", href: DeploymentController.index() },
        ],
    },
});

const form = useForm<{
    project_id: number | "";
    project_version_id: number | "";
    slug: string;
}>({ project_id: "", project_version_id: "", slug: "" });

const project = computed(() =>
    props.projects.find((p) => p.id === form.project_id),
);

function submit() {
    form.transform(({ project_version_id, slug }) => ({
        project_version_id,
        slug: slug || null,
    })).post(InstallationController.store(props.deploymentHost.id).url);
}

const selectClass =
    "h-9 rounded-md border border-input bg-background px-3 text-sm";
</script>

<template>
    <Head title="Installa versione" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            title="Installa versione"
            :description="`${deployment.name} · ${deploymentHost.path}`"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="project">Progetto</Label>
                <select
                    id="project"
                    v-model="form.project_id"
                    :class="selectClass"
                    @change="form.project_version_id = ''"
                >
                    <option value="" disabled>Seleziona un progetto</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">
                        {{ p.name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="version">Versione pubblicata</Label>
                <select
                    id="version"
                    v-model="form.project_version_id"
                    :class="selectClass"
                    :disabled="!project"
                >
                    <option value="" disabled>Seleziona una versione</option>
                    <option
                        v-for="v in project?.versions ?? []"
                        :key="v.id"
                        :value="v.id"
                    >
                        {{ v.version }}
                    </option>
                </select>
                <InputError :message="form.errors.project_version_id" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Slug (opzionale)</Label>
                <Input
                    id="slug"
                    v-model="form.slug"
                    :placeholder="project?.slug ?? 'slug-del-progetto'"
                    autocomplete="off"
                />
                <InputError :message="form.errors.slug" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="
                        form.processing || form.project_version_id === ''
                    "
                    data-test="install-version"
                >
                    Installa
                </Button>
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            DeploymentHostController.show([
                                deployment.id,
                                deploymentHost.id,
                            ])
                        "
                    >
                        Annulla
                    </Link>
                </Button>
            </div>
        </form>
    </div>
</template>
