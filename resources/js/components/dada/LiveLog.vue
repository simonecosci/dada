<script setup lang="ts">
import { useEcho } from '@laravel/echo-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { LogLine } from '@/lib/runs';

const props = defineProps<{
    runId: number;
    initialLogs: LogLine[];
    hosts?: { deployment_host_id: number; name: string }[];
}>();

const lines = ref<LogLine[]>([...props.initialLogs]);
const hostFilter = ref<'all' | number>('all');
const container = ref<HTMLElement | null>(null);
const follow = ref(true);

const known = new Set(lines.value.map((l) => l.id));

useEcho<LogLine>(`deploy-runs.${props.runId}`, '.log', (line) => {
    if (known.has(line.id)) {
        return;
    }

    known.add(line.id);
    lines.value.push(line);
});

const visible = computed(() =>
    hostFilter.value === 'all'
        ? lines.value
        : lines.value.filter(
              (l) =>
                  l.deployment_host_id === hostFilter.value ||
                  l.deployment_host_id === null,
          ),
);

function scrollToEnd() {
    if (follow.value && container.value) {
        container.value.scrollTop = container.value.scrollHeight;
    }
}

function onScroll() {
    const el = container.value;

    if (el) {
        follow.value = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
    }
}

watch(
    () => visible.value.length,
    () => nextTick(scrollToEnd),
);
onMounted(scrollToEnd);

const levelClass: Record<LogLine['level'], string> = {
    info: 'text-muted-foreground',
    step: 'font-semibold text-sky-600 dark:text-sky-400',
    output: '',
    error: 'text-red-600 dark:text-red-400',
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="hosts && hosts.length > 1" class="flex items-center gap-2">
            <label class="text-sm font-medium" for="log-host">Host</label>
            <select
                id="log-host"
                v-model="hostFilter"
                class="rounded-md border border-input bg-background px-2 py-1 text-sm"
                data-test="log-host-filter"
            >
                <option value="all">Tutti</option>
                <option
                    v-for="h in hosts"
                    :key="h.deployment_host_id"
                    :value="h.deployment_host_id"
                >
                    {{ h.name }}
                </option>
            </select>
        </div>
        <div
            ref="container"
            class="h-[28rem] overflow-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs"
            data-test="live-log"
            @scroll="onScroll"
        >
            <div
                v-for="l in visible"
                :key="l.id"
                class="break-all whitespace-pre-wrap"
                :class="levelClass[l.level]"
            >
                {{ l.message }}
            </div>
            <p v-if="visible.length === 0" class="text-muted-foreground">
                Nessun log.
            </p>
        </div>
    </div>
</template>
