<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { toast } from "vue-sonner";
import DeployController from "@/actions/App/Http/Controllers/Deployments/DeployController";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";

type HostOption = { id: number; name: string };
type PlannedRemoval = {
    deployment_host_id: number;
    slug: string;
    services: string[];
};

const props = defineProps<{
    deploymentId: number;
    hosts: HostOption[];
    /** Se impostato, il deploy riguarda solo questo host e la scelta è nascosta. */
    fixedHostId?: number;
}>();

const open = ref(false);
const pull = ref(true);
const restart = ref(true);
const target = ref<number | "all">(props.fixedHostId ?? "all");
const removals = ref<PlannedRemoval[] | null>(null);
const error = ref<string>();
const loading = ref(false);

const hostName = (id: number) =>
    props.hosts.find((h) => h.id === id)?.name ?? `#${id}`;

const removalLines = computed(() =>
    (removals.value ?? []).map(
        (r) =>
            `${hostName(r.deployment_host_id)} · ${r.slug}` +
            (r.services.length ? `: ${r.services.join(", ")}` : ""),
    ),
);

function reset() {
    removals.value = null;
    error.value = undefined;
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : "";
}

async function submit(confirmRemovals = false) {
    loading.value = true;
    error.value = undefined;

    try {
        const response = await fetch(DeployController.url(props.deploymentId), {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-XSRF-TOKEN": csrfToken(),
            },
            credentials: "same-origin",
            body: JSON.stringify({
                deployment_host_id:
                    target.value === "all" ? null : target.value,
                pull: pull.value,
                restart: restart.value,
                confirm_removals: confirmRemovals,
            }),
        });

        // Successo: il server risponde con un redirect alla pagina del run, seguito da fetch.
        if (response.ok && response.redirected) {
            open.value = false;
            router.visit(response.url);

            return;
        }

        const body = await response.json().catch(() => ({}));

        if (response.status === 409) {
            open.value = false;
            toast.error(body.message ?? "Esecuzione già in corso");
        } else if (response.status === 422 && Array.isArray(body.removals)) {
            removals.value = body.removals as PlannedRemoval[];
        } else if (response.status === 422) {
            const errors = Object.values(body.errors ?? {}).flat() as string[];
            error.value = errors[0] ?? body.message ?? "Richiesta non valida.";
        } else {
            error.value = "Impossibile avviare il deploy.";
        }
    } catch {
        error.value = "Impossibile avviare il deploy.";
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open" @update:open="reset">
        <DialogTrigger as-child>
            <Button data-test="deploy-open">Deploy</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>Deploy</DialogTitle>
            <DialogDescription>
                Genera i file, li carica sugli host e riavvia i servizi.
            </DialogDescription>

            <div v-if="removals === null" class="flex flex-col gap-3 text-sm">
                <label class="flex items-center gap-2">
                    <input
                        v-model="pull"
                        type="checkbox"
                        data-test="deploy-pull"
                    />
                    Aggiorna le immagini (pull)
                </label>
                <label class="flex items-center gap-2">
                    <input
                        v-model="restart"
                        type="checkbox"
                        data-test="deploy-restart"
                    />
                    Riavvia i servizi
                </label>
                <label
                    v-if="fixedHostId === undefined"
                    class="flex flex-col gap-1"
                >
                    <span class="font-medium">Host</span>
                    <select
                        v-model="target"
                        class="rounded-md border border-input bg-background px-3 py-2"
                        data-test="deploy-target"
                    >
                        <option value="all">Tutti gli host</option>
                        <option v-for="h in hosts" :key="h.id" :value="h.id">
                            {{ h.name }}
                        </option>
                    </select>
                </label>
            </div>

            <div
                v-else
                class="flex flex-col gap-2 text-sm"
                data-test="deploy-removals"
            >
                <p>Verranno fermati e rimossi i servizi:</p>
                <ul class="list-disc pl-6">
                    <li v-for="line in removalLines" :key="line">{{ line }}</li>
                </ul>
                <p>Confermi?</p>
            </div>

            <InputError :message="error" />

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="outline">Annulla</Button>
                </DialogClose>
                <Button
                    v-if="removals === null"
                    :disabled="loading"
                    data-test="deploy-submit"
                    @click="submit(false)"
                >
                    Avvia deploy
                </Button>
                <Button
                    v-else
                    variant="destructive"
                    :disabled="loading"
                    data-test="deploy-confirm-removals"
                    @click="submit(true)"
                >
                    Conferma e avvia
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
