<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import RestartController from "@/actions/App/Http/Controllers/Deployments/RestartController";
import { Button } from "@/components/ui/button";

type Action = "start" | "stop" | "restart";

const props = defineProps<{
    deploymentHostId: number;
    installationSlug?: string;
    service?: string;
}>();

const processing = ref(false);

const labels: Record<Action, string> = {
    start: "Avvia",
    stop: "Ferma",
    restart: "Riavvia",
};

function target(): string {
    if (props.service) {
        return `il servizio ${props.service}`;
    }

    return props.installationSlug
        ? `l'installazione ${props.installationSlug}`
        : "tutto l'host";
}

function run(action: Action) {
    if (action === "stop" && !confirm(`Fermare ${target()}?`)) {
        return;
    }

    router.post(
        RestartController.url(props.deploymentHostId),
        {
            action,
            installation_slug: props.installationSlug,
            service: props.service,
        },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <div class="flex gap-2">
        <Button
            v-for="action in ['restart', 'start', 'stop'] as Action[]"
            :key="action"
            size="sm"
            :variant="action === 'stop' ? 'destructive' : 'outline'"
            :disabled="processing"
            :data-test="`${action}-${service ?? installationSlug ?? 'host'}`"
            @click="run(action)"
        >
            {{ labels[action] }}
        </Button>
    </div>
</template>
