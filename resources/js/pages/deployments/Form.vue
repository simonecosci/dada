<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DeploymentData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    environment: string | null;
};

const props = defineProps<{
    deployment: DeploymentData | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

const isEdit = computed(() => props.deployment !== null);
const formProps = computed(() =>
    props.deployment
        ? DeploymentController.update.form(props.deployment.id)
        : DeploymentController.store.form(),
);
</script>

<template>
    <Head :title="isEdit ? 'Modifica deployment' : 'Nuovo deployment'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading :title="isEdit ? 'Modifica deployment' : 'Nuovo deployment'" />

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
                    :default-value="deployment?.name"
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
                    :default-value="deployment?.slug"
                    placeholder="cliente-x"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="environment">Ambiente</Label>
                <Input
                    id="environment"
                    name="environment"
                    :default-value="deployment?.environment ?? ''"
                    placeholder="produzione"
                    autocomplete="off"
                />
                <InputError :message="errors.environment" />
            </div>

            <div class="grid gap-2">
                <Label for="description">Descrizione</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    :value="deployment?.description ?? ''"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-deployment">
                    Salva
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="DeploymentController.index()">Annulla</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
