<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import HostController from '@/actions/App/Http/Controllers/Admin/HostController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type ShownHost = {
    id: number;
    name: string;
    hostname: string;
    port: number;
    username: string;
    sudo: string;
    reachable: boolean | null;
    facts: Record<string, unknown> | null;
    checked_at: string | null;
    has_host_key: boolean;
    host_key_fingerprints: string[];
    credential: { id: number; name: string; type: string } | null;
    jump_host: { id: number; name: string } | null;
};

const props = defineProps<{
    host: ShownHost;
}>();

function check() {
    router.post(
        HostController.check(props.host.id).url,
        {},
        { preserveScroll: true },
    );
}

function resetKey() {
    if (
        confirm(
            `Azzerare la chiave host di "${props.host.name}"? Verrà accettata la chiave presentata al prossimo collegamento.`,
        )
    ) {
        router.post(
            HostController.resetKey(props.host.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Host',
                href: HostController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="host.name" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="host.name"
                :description="`${host.username}@${host.hostname}:${host.port}`"
            />
            <Button variant="outline" as-child>
                <Link :href="HostController.edit(host.id)">Modifica</Link>
            </Button>
        </div>

        <dl class="grid grid-cols-[10rem_1fr] gap-2 text-sm">
            <dt class="text-muted-foreground">Credenziale</dt>
            <dd>{{ host.credential?.name }} ({{ host.credential?.type }})</dd>

            <dt class="text-muted-foreground">Jump host</dt>
            <dd>{{ host.jump_host?.name ?? 'Nessuno' }}</dd>

            <dt class="text-muted-foreground">Prefisso sudo</dt>
            <dd>{{ host.sudo || 'Nessuno' }}</dd>

            <dt class="text-muted-foreground">Raggiungibilità</dt>
            <dd>
                <Badge v-if="host.reachable === true">Raggiungibile</Badge>
                <Badge
                    v-else-if="host.reachable === false"
                    variant="destructive"
                >
                    Non raggiungibile
                </Badge>
                <Badge v-else variant="secondary">Non verificato</Badge>
            </dd>

            <dt class="text-muted-foreground">Ultima verifica</dt>
            <dd>{{ host.checked_at ?? 'Mai' }}</dd>

            <dt class="text-muted-foreground">Chiave host</dt>
            <dd>
                <template v-if="host.has_host_key">
                    Registrata
                    <code
                        v-for="fingerprint in host.host_key_fingerprints"
                        :key="fingerprint"
                        class="block font-mono text-xs break-all text-muted-foreground"
                        >{{ fingerprint }}</code
                    >
                </template>
                <template v-else>Non registrata</template>
            </dd>
        </dl>

        <div>
            <h2 class="mb-2 text-sm font-medium">Facts</h2>
            <pre
                v-if="host.facts"
                class="overflow-x-auto rounded-lg bg-muted p-3 text-xs"
                >{{ JSON.stringify(host.facts, null, 2) }}</pre>
            <p v-else class="text-sm text-muted-foreground">
                Nessun dato raccolto.
            </p>
        </div>

        <div class="flex gap-2">
            <Button @click="check">Verifica</Button>
            <Button
                v-if="host.has_host_key"
                variant="outline"
                @click="resetKey"
            >
                Reset chiave host
            </Button>
        </div>
    </div>
</template>
