<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ref } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import LiveLog from '@/components/dada/LiveLog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import {
    formatDate,
    statusLabels,
    typeLabels,
    type LogLine,
    type RunStatus,
    type RunSummary,
} from '@/lib/runs';

type HostRow = {
    deployment_host_id: number;
    name: string;
    status: RunStatus;
    error: string | null;
};

const props = defineProps<{
    run: RunSummary & {
        error: string | null;
        deployment: { id: number; name: string };
    };
    hosts: HostRow[];
    logs: LogLine[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

const status = ref<RunStatus>(props.run.status);
const hostRows = ref<HostRow[]>(props.hosts.map((h) => ({ ...h })));

useEcho<{
    id: number;
    status: RunStatus;
    hosts: {
        deployment_host_id: number;
        status: RunStatus;
        error: string | null;
    }[];
}>(`deploy-runs.${props.run.id}`, '.run.updated', (payload) => {
    status.value = payload.status;

    for (const update of payload.hosts) {
        const row = hostRows.value.find(
            (h) => h.deployment_host_id === update.deployment_host_id,
        );

        if (row) {
            row.status = update.status;
            row.error = update.error;
        }
    }

    if (payload.status === 'succeeded' || payload.status === 'failed') {
        router.reload({ only: ['run'] });
    }
});
</script>

<template>
    <Head :title="`Esecuzione #${run.id}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="`${typeLabels[run.type]} #${run.id}`"
                :description="`${run.deployment.name} · ${run.user ?? '-'} · ${formatDate(run.started_at)}`"
            />
            <Badge
                :variant="status === 'failed' ? 'destructive' : 'secondary'"
                data-test="run-status"
            >
                {{ statusLabels[status] }}
            </Badge>
        </div>

        <p v-if="run.error" class="text-sm text-red-600">{{ run.error }}</p>

        <ul class="flex flex-wrap gap-3 text-sm" data-test="run-hosts">
            <li
                v-for="h in hostRows"
                :key="h.deployment_host_id"
                class="flex items-center gap-2 rounded-md border px-3 py-1"
            >
                {{ h.name }}
                <Badge
                    :variant="
                        h.status === 'failed' ? 'destructive' : 'secondary'
                    "
                >
                    {{ statusLabels[h.status] }}
                </Badge>
                <span v-if="h.error" class="text-red-600">{{ h.error }}</span>
            </li>
        </ul>

        <LiveLog :run-id="run.id" :initial-logs="logs" :hosts="hostRows" />

        <div>
            <Link
                :href="DeploymentController.show(run.deployment.id)"
                class="text-sm underline"
            >
                Torna al deployment
            </Link>
        </div>
    </div>
</template>
