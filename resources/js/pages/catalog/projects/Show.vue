<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/Catalog/ProjectController';
import ProjectVersionController from '@/actions/App/Http/Controllers/Catalog/ProjectVersionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type CatalogProject = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
};

type VersionSummary = {
    id: number;
    version: string;
    status: 'draft' | 'published';
    published_at: string | null;
};

const props = defineProps<{
    project: CatalogProject;
    versions: VersionSummary[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Catalogo', href: ProjectController.index() }],
    },
});

const newVersion = useForm({ version: '' });

function createVersion() {
    newVersion.post(ProjectVersionController.store(props.project.id).url);
}

function destroy() {
    if (confirm(`Cancellare il progetto ${props.project.name}?`)) {
        router.delete(ProjectController.destroy(props.project.id).url);
    }
}
</script>

<template>
    <Head :title="project.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="project.name"
                :description="project.description ?? project.slug"
            />
            <div v-if="canManage" class="flex gap-2">
                <Button variant="outline" as-child>
                    <Link :href="ProjectController.edit(project.id)">
                        Modifica
                    </Link>
                </Button>
                <Button variant="destructive" @click="destroy">
                    Cancella
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Versione</th>
                        <th class="px-4 py-2 font-medium">Stato</th>
                        <th class="px-4 py-2 font-medium">Pubblicata il</th>
                        <th class="px-4 py-2 text-right font-medium">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in versions" :key="v.id" class="border-t">
                        <td class="px-4 py-2">{{ v.version }}</td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    v.status === 'published'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    v.status === 'published'
                                        ? 'Pubblicata'
                                        : 'Bozza'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            {{
                                v.published_at
                                    ? new Date(v.published_at).toLocaleString(
                                          'it-IT',
                                      )
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button variant="outline" size="sm" as-child>
                                <Link
                                    :href="ProjectVersionController.edit(v.id)"
                                >
                                    {{
                                        v.status === 'published' || !canManage
                                            ? 'Apri'
                                            : 'Modifica'
                                    }}
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="versions.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            Nessuna versione.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form
            v-if="canManage"
            class="flex max-w-md flex-col gap-2"
            @submit.prevent="createVersion"
        >
            <label class="text-sm font-medium" for="new-version">
                Nuova versione (bozza vuota)
            </label>
            <div class="flex gap-2">
                <Input
                    id="new-version"
                    v-model="newVersion.version"
                    placeholder="1.0.0"
                    autocomplete="off"
                />
                <Button
                    :disabled="newVersion.processing"
                    data-test="create-version"
                >
                    Crea
                </Button>
            </div>
            <InputError :message="newVersion.errors.version" />
        </form>
    </div>
</template>
