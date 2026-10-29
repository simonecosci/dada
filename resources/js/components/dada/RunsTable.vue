<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ref, watch } from 'vue';
import RunController from '@/actions/App/Http/Controllers/Deployments/RunController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDate,
    statusLabels,
    typeLabels,
    type RunStatus,
    type RunSummary,
} from '@/lib/runs';

const props = defineProps<{
    deploymentId: number;
    runs: RunSummary[];
}>();

const rows = ref<RunSummary[]>([...props.runs]);

watch(
    () => props.runs,
    (runs) => {
        rows.value = [...runs];
    },
);

useEcho<{ id: number; status: RunStatus }>(
    `deployments.${props.deploymentId}`,
    '.run.updated',
    (payload) => {
        const row = rows.value.find((r) => r.id === payload.id);

        if (row) {
            row.status = payload.status;
        } else {
            router.reload({ only: ['runs'] });
        }
    },
);

function variant(status: RunStatus) {
    return status === 'failed'
        ? 'destructive'
        : status === 'succeeded'
          ? 'default'
          : 'secondary';
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg border" data-test="runs-table">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-4 py-2 font-medium">#</th>
                    <th class="px-4 py-2 font-medium">Tipo</th>
                    <th class="px-4 py-2 font-medium">Stato</th>
                    <th class="px-4 py-2 font-medium">Utente</th>
                    <th class="px-4 py-2 font-medium">Avvio</th>
                    <th class="px-4 py-2 font-medium">Fine</th>
                    <th class="px-4 py-2 text-right font-medium">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" class="border-t">
                    <td class="px-4 py-2">{{ r.id }}</td>
                    <td class="px-4 py-2">{{ typeLabels[r.type] }}</td>
                    <td class="px-4 py-2">
                        <Badge :variant="variant(r.status)">
                            {{ statusLabels[r.status] }}
                        </Badge>
                    </td>
                    <td class="px-4 py-2">{{ r.user ?? '-' }}</td>
                    <td class="px-4 py-2">{{ formatDate(r.started_at) }}</td>
                    <td class="px-4 py-2">{{ formatDate(r.finished_at) }}</td>
                    <td class="px-4 py-2 text-right">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="RunController.show(r.id)">Apri</Link>
                        </Button>
                    </td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td
                        colspan="7"
                        class="px-4 py-6 text-center text-muted-foreground"
                    >
                        Nessuna esecuzione.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
