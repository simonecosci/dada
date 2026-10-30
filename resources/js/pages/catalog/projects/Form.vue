<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Catalog/ProjectController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CatalogProject = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
};

const props = defineProps<{
    project: CatalogProject | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Catalogo', href: ProjectController.index() }],
    },
});

const isEdit = computed(() => props.project !== null);
const formProps = computed(() =>
    props.project
        ? ProjectController.update.form(props.project.id)
        : ProjectController.store.form(),
);
</script>

<template>
    <Head :title="isEdit ? 'Modifica progetto' : 'Nuovo progetto'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading :title="isEdit ? 'Modifica progetto' : 'Nuovo progetto'" />

        <Form
            v-bind="formProps"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="project?.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Slug</Label>
                <Input
                    id="slug"
                    name="slug"
                    :default-value="project?.slug"
                    placeholder="wordpress"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="description">Descrizione</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    :value="project?.description ?? ''"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-project">
                    Salva
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="ProjectController.index()">Annulla</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
