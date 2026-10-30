<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type DeploymentSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    environment: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

defineProps<{
    deployments: DeploymentSummary[];
    canCreate: boolean;
}>();
</script>

<template>
    <Head title="Deployment" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Deployment"
                description="Target environments you have access to"
            />
            <Button v-if="canCreate" as-child>
                <Link
                    :href="DeploymentController.create()"
                    data-test="create-deployment"
                >
                    New deployment
                </Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Slug</th>
                        <th class="px-4 py-2 font-medium">Environment</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in deployments" :key="d.id" class="border-t">
                        <td class="px-4 py-2">
                            <Link
                                :href="DeploymentController.show(d.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ d.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">{{ d.slug }}</td>
                        <td class="px-4 py-2">{{ d.environment ?? '—' }}</td>
                    </tr>
                    <tr v-if="deployments.length === 0">
                        <td
                            colspan="3"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No deployments.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
