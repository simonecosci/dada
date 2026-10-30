<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ref, watch } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import RunController from '@/actions/App/Http/Controllers/Deployments/RunController';
import LiveLog from '@/components/dada/LiveLog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    can: { fail: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

const status = ref<RunStatus>(props.run.status);
const failing = ref(false);

function markFailed() {
    if (
        !confirm(
            'Mark the run as failed? Use this only if the worker is no longer running it.',
        )
    ) {
        return;
    }

    router.post(
        RunController.fail.url(props.run.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (failing.value = true),
            onFinish: () => (failing.value = false),
            onSuccess: () =>
                router.reload({ only: ['run', 'hosts', 'logs', 'can'] }),
        },
    );
}
const hostRows = ref<HostRow[]>(props.hosts.map((h) => ({ ...h })));

// After a partial reload the server data is authoritative.
watch(
    () => props.run.status,
    (value) => (status.value = value),
);
watch(
    () => props.hosts,
    (hosts) => (hostRows.value = hosts.map((h) => ({ ...h }))),
);

const { channel } = useEcho<{
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
        router.reload({ only: ['run', 'hosts', 'logs'] });
    }
});

// Fetches the lines emitted between render and subscription (and after a reconnection);
// LiveLog discards duplicates by id.
channel().subscribed(() => {
    router.reload({ only: ['run', 'hosts', 'logs'] });
});
</script>

<template>
    <Head :title="`Run #${run.id}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="`${typeLabels[run.type]} #${run.id}`"
                :description="`${run.deployment.name} · ${run.user ?? '-'} · ${formatDate(run.started_at)}`"
            />
            <div class="flex items-center gap-2">
                <Button
                    v-if="
                        can.fail &&
                        (status === 'queued' || status === 'running')
                    "
                    size="sm"
                    variant="destructive"
                    :disabled="failing"
                    data-test="run-fail"
                    @click="markFailed"
                >
                    Mark as failed
                </Button>
                <Badge
                    :variant="status === 'failed' ? 'destructive' : 'secondary'"
                    data-test="run-status"
                >
                    {{ statusLabels[status] }}
                </Badge>
            </div>
        </div>

        <p v-if="run.error" class="text-sm text-red-600">{{ run.error }}</p>
        <p
            v-if="$page.props.errors?.run"
            class="text-sm text-red-600"
            data-test="run-fail-error"
        >
            {{ $page.props.errors.run }}
        </p>

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
                Back to deployment
            </Link>
        </div>
    </div>
</template>
