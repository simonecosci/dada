<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/Catalog/ProjectController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type CatalogProject = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    versions_count: number;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Catalogo', href: ProjectController.index() }],
    },
});

defineProps<{
    projects: CatalogProject[];
    canManage: boolean;
}>();
</script>

<template>
    <Head title="Catalogo" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Catalogo"
                description="Progetti e versioni disponibili per il deploy"
            />
            <Button v-if="canManage" as-child>
                <Link
                    :href="ProjectController.create()"
                    data-test="create-project"
                >
                    Nuovo progetto
                </Link>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nome</th>
                        <th class="px-4 py-2 font-medium">Slug</th>
                        <th class="px-4 py-2 font-medium">Versioni</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in projects" :key="p.id" class="border-t">
                        <td class="px-4 py-2">
                            <Link
                                :href="ProjectController.show(p.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ p.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">{{ p.slug }}</td>
                        <td class="px-4 py-2">{{ p.versions_count }}</td>
                    </tr>
                    <tr v-if="projects.length === 0">
                        <td
                            colspan="3"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            Nessun progetto nel catalogo.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
