<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import DeploymentController from "@/actions/App/Http/Controllers/Deployments/DeploymentController";
import DeploymentHostController from "@/actions/App/Http/Controllers/Deployments/DeploymentHostController";
import InstallationController from "@/actions/App/Http/Controllers/Deployments/InstallationController";
import CodeEditor from "@/components/dada/CodeEditor.vue";
import DeployDialog from "@/components/dada/DeployDialog.vue";
import RunsTable from "@/components/dada/RunsTable.vue";
import type { RunSummary } from "@/lib/runs";
import VariablesEditor from "@/components/dada/VariablesEditor.vue";
import Heading from "@/components/Heading.vue";
import InputError from "@/components/InputError.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { variablesPayload, type VariableRow } from "@/lib/variables";

type InstallationRow = {
    id: number;
    slug: string;
    order: number;
    project: string;
    version: string;
};
type RegistryOption = { id: number; name: string; registry_url: string | null };

const props = defineProps<{
    deployment: { id: number; name: string; slug: string };
    deploymentHost: {
        id: number;
        path: string;
        orchestrator: string;
        stack_name: string;
        host: { id: number; name: string; hostname: string };
    };
    installations: InstallationRow[];
    variables: VariableRow[];
    unused: string[];
    registries: number[];
    availableRegistries: RegistryOption[];
    compose_template: string;
    canOperate: boolean;
    canReveal: boolean;
    runs: RunSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Deployment", href: DeploymentController.index() },
        ],
    },
});

const tabs = [
    { key: "installations", label: "Installazioni" },
    { key: "variables", label: "Variabili host" },
    { key: "registries", label: "Registry" },
    { key: "template", label: "Template compose" },
    { key: "files", label: "File" },
    { key: "runs", label: "Esecuzioni" },
] as const;
const tab = ref<(typeof tabs)[number]["key"]>("installations");

const hostParams = computed(
    () => [props.deployment.id, props.deploymentHost.id] as [number, number],
);

// Installazioni: ordinamento con frecce
const order = ref<number[]>(props.installations.map((i) => i.id));
const sorted = computed(() =>
    order.value
        .map((id) => props.installations.find((i) => i.id === id))
        .filter((i): i is InstallationRow => i !== undefined),
);

function move(index: number, delta: -1 | 1) {
    const target = index + delta;

    if (target < 0 || target >= order.value.length) {
        return;
    }

    const next = [...order.value];
    [next[index], next[target]] = [next[target], next[index]];
    order.value = next;
    router.put(
        InstallationController.reorder(props.deploymentHost.id).url,
        { ids: next },
        { preserveScroll: true },
    );
}

// Variabili host-global
const variablesForm = useForm({
    variables: props.variables as VariableRow[],
});
const variableErrors = computed(
    () => variablesForm.errors as Record<string, string>,
);

function saveVariables() {
    variablesForm
        .transform((data) => ({ variables: variablesPayload(data.variables) }))
        .put(DeploymentHostController.variables(hostParams.value).url, {
            preserveScroll: true,
            onSuccess: () => {
                variablesForm.variables = props.variables.map((v) => ({
                    ...v,
                }));
                variablesForm.defaults({ variables: variablesForm.variables });
                variablesForm.clearErrors();
            },
        });
}

// Registry
const registryForm = useForm({
    credential_ids: [...props.registries] as number[],
});

function saveRegistries() {
    registryForm.put(
        DeploymentHostController.registries(hostParams.value).url,
        {
            preserveScroll: true,
        },
    );
}

// Template compose
const templateForm = useForm({
    path: props.deploymentHost.path,
    orchestrator: props.deploymentHost.orchestrator,
    stack_name: props.deploymentHost.stack_name,
    compose_template: props.compose_template,
});
const templateErrors = computed(() =>
    templateForm.errors.compose_template
        ? templateForm.errors.compose_template.split("\n")
        : [],
);

function saveTemplate() {
    templateForm.put(DeploymentHostController.update(hostParams.value).url, {
        preserveScroll: true,
    });
}

// File generati (anteprima)
type Preview = {
    files: Record<string, string>;
    errors: string[];
    warnings: string[];
    missingRequired: string[];
};
const preview = ref<Preview | null>(null);
const previewLoading = ref(false);
const previewFailed = ref(false);
const revealed = ref(false);
const selectedFile = ref<string | null>(null);

async function loadPreview(reveal = false) {
    previewLoading.value = true;
    previewFailed.value = false;

    try {
        const response = await fetch(
            `/deployment-hosts/${props.deploymentHost.id}/preview${reveal ? "?reveal=1" : ""}`,
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        preview.value = (await response.json()) as Preview;
        revealed.value = reveal;

        if (
            selectedFile.value === null ||
            !(selectedFile.value in preview.value.files)
        ) {
            selectedFile.value = Object.keys(preview.value.files)[0] ?? null;
        }
    } catch {
        previewFailed.value = true;
    } finally {
        previewLoading.value = false;
    }
}

watch(tab, (value) => {
    if (value === "files") {
        loadPreview();
    }
});

const fileNames = computed(() => Object.keys(preview.value?.files ?? {}));
const fileContents = computed(() =>
    selectedFile.value === null
        ? ""
        : (preview.value?.files[selectedFile.value] ?? ""),
);
const fileLanguage = computed<"yaml" | "shell" | "plaintext">(() => {
    if (selectedFile.value?.endsWith(".yml")) {
        return "yaml";
    }

    return selectedFile.value?.endsWith(".sh") ? "shell" : "plaintext";
});

function destroy() {
    if (
        confirm(
            `Rimuovere l'host ${props.deploymentHost.host.name} dal deployment? Verranno eliminate anche le installazioni.`,
        )
    ) {
        router.delete(DeploymentHostController.destroy(hostParams.value).url);
    }
}
</script>

<template>
    <Head :title="`${deployment.name} · ${deploymentHost.host.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="deploymentHost.host.name"
                :description="`${deploymentHost.host.hostname} · ${deploymentHost.path} · ${deploymentHost.orchestrator} · stack ${deploymentHost.stack_name}`"
            />
            <div class="flex gap-2">
                <DeployDialog
                    v-if="canOperate"
                    :deployment-id="deployment.id"
                    :hosts="[
                        { id: deploymentHost.id, name: deploymentHost.host.name },
                    ]"
                    :fixed-host-id="deploymentHost.id"
                />
                <Button v-if="canOperate" variant="outline" as-child>
                    <Link :href="DeploymentHostController.edit(hostParams)">
                        Impostazioni
                    </Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="DeploymentController.show(deployment.id)">
                        Torna al deployment
                    </Link>
                </Button>
                <Button v-if="canOperate" variant="destructive" @click="destroy"
                    >Rimuovi</Button
                >
            </div>
        </div>

        <div class="flex gap-2 border-b" role="tablist">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="tab === t.key"
                :data-test="`tab-${t.key}`"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                :class="
                    tab === t.key
                        ? 'border-primary'
                        : 'border-transparent text-muted-foreground'
                "
                @click="tab = t.key"
            >
                {{ t.label }}
            </button>
        </div>

        <div v-if="tab === 'installations'" class="flex flex-col gap-4">
            <InputError
                :message="($page.props.errors as Record<string, string>).ids"
            />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Slug</th>
                            <th class="px-4 py-2 font-medium">Progetto</th>
                            <th class="px-4 py-2 font-medium">Versione</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Azioni
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(i, idx) in sorted"
                            :key="i.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">{{ i.slug }}</td>
                            <td class="px-4 py-2">{{ i.project }}</td>
                            <td class="px-4 py-2">
                                <Badge variant="secondary">{{
                                    i.version
                                }}</Badge>
                            </td>
                            <td class="flex justify-end gap-2 px-4 py-2">
                                <Button
                                    v-if="canOperate"
                                    variant="outline"
                                    size="sm"
                                    :disabled="idx === 0"
                                    :aria-label="`Sposta ${i.slug} in alto`"
                                    @click="move(idx, -1)"
                                >
                                    ↑
                                </Button>
                                <Button
                                    v-if="canOperate"
                                    variant="outline"
                                    size="sm"
                                    :disabled="idx === sorted.length - 1"
                                    :aria-label="`Sposta ${i.slug} in basso`"
                                    @click="move(idx, 1)"
                                >
                                    ↓
                                </Button>
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="
                                            InstallationController.edit(i.id)
                                        "
                                    >
                                        Modifica
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="sorted.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                Nessuna installazione.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="canOperate">
                <Button as-child data-test="install-version-link">
                    <Link
                        :href="InstallationController.create(deploymentHost.id)"
                    >
                        Installa versione
                    </Link>
                </Button>
            </div>
        </div>

        <div
            v-else-if="tab === 'variables'"
            class="flex max-w-4xl flex-col gap-4"
        >
            <p
                v-if="unused.length > 0"
                class="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                data-test="unused-variables"
            >
                Variabili non più dichiarate da nessuna versione installata:
                <strong>{{ unused.join(", ") }}</strong
                >. Puoi rimuoverle se non servono più.
            </p>
            <VariablesEditor
                v-model="variablesForm.variables"
                mode="host"
                :can-reveal="canReveal"
                :readonly="!canOperate"
                :errors="variableErrors"
            />
            <div v-if="canOperate">
                <Button
                    :disabled="variablesForm.processing"
                    data-test="save-host-variables"
                    @click="saveVariables"
                >
                    Salva variabili
                </Button>
            </div>
        </div>

        <div
            v-else-if="tab === 'registries'"
            class="flex max-w-xl flex-col gap-4"
        >
            <p class="text-sm text-muted-foreground">
                Credenziali registry usate per il login Docker su questo host.
            </p>
            <label
                v-for="r in availableRegistries"
                :key="r.id"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    v-model="registryForm.credential_ids"
                    type="checkbox"
                    :value="r.id"
                    :disabled="!canOperate"
                />
                {{ r.name }}
                <span v-if="r.registry_url" class="text-muted-foreground">
                    ({{ r.registry_url }})
                </span>
            </label>
            <p
                v-if="availableRegistries.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nessuna credenziale di tipo registry disponibile.
            </p>
            <InputError
                :message="
                    (registryForm.errors as Record<string, string>)[
                        'credential_ids.0'
                    ] ?? registryForm.errors.credential_ids
                "
            />
            <div v-if="canOperate">
                <Button
                    :disabled="registryForm.processing"
                    data-test="save-registries"
                    @click="saveRegistries"
                >
                    Salva registry
                </Button>
            </div>
        </div>

        <div
            v-else-if="tab === 'template'"
            class="flex max-w-4xl flex-col gap-4"
        >
            <p class="text-sm text-muted-foreground">
                Sono ammesse solo le chiavi <code>volumes</code> e
                <code>networks</code>.
            </p>
            <CodeEditor
                v-model="templateForm.compose_template"
                language="yaml"
                :readonly="!canOperate"
                :errors="templateErrors"
            />
            <div v-if="canOperate">
                <Button
                    :disabled="templateForm.processing"
                    data-test="save-template"
                    @click="saveTemplate"
                >
                    Salva template
                </Button>
            </div>
        </div>

        <div v-else-if="tab === 'files'" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="canReveal"
                    variant="outline"
                    size="sm"
                    :disabled="previewLoading || revealed"
                    data-test="reveal-preview"
                    @click="loadPreview(true)"
                >
                    Mostra segreti
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="previewLoading"
                    data-test="reload-preview"
                    @click="loadPreview(revealed)"
                >
                    Ricarica
                </Button>
            </div>

            <p v-if="previewFailed" class="text-sm text-red-600">
                Impossibile caricare l'anteprima.
            </p>
            <p
                v-else-if="previewLoading && preview === null"
                class="text-sm text-muted-foreground"
            >
                Caricamento…
            </p>

            <template v-if="preview">
                <ul
                    v-if="preview.errors.length"
                    class="list-disc rounded-md border border-red-500/50 bg-red-500/10 p-3 pl-7 text-sm text-red-700 dark:text-red-400"
                    data-test="preview-errors"
                >
                    <li v-for="e in preview.errors" :key="e">{{ e }}</li>
                </ul>
                <ul
                    v-if="preview.missingRequired.length"
                    class="list-disc rounded-md border border-orange-500/50 bg-orange-500/10 p-3 pl-7 text-sm text-orange-700 dark:text-orange-400"
                    data-test="preview-missing"
                >
                    <li v-for="m in preview.missingRequired" :key="m">
                        Variabile obbligatoria mancante: {{ m }}
                    </li>
                </ul>
                <ul
                    v-if="preview.warnings.length"
                    class="list-disc rounded-md border border-yellow-500/50 bg-yellow-500/10 p-3 pl-7 text-sm text-yellow-700 dark:text-yellow-400"
                    data-test="preview-warnings"
                >
                    <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
                </ul>

                <p
                    v-if="fileNames.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nessun file generato.
                </p>
                <div v-else class="grid gap-4 md:grid-cols-[14rem_1fr]">
                    <ul class="flex flex-col gap-1" data-test="preview-files">
                        <li v-for="name in fileNames" :key="name">
                            <button
                                type="button"
                                class="w-full rounded-md px-3 py-1.5 text-left font-mono text-sm hover:bg-muted"
                                :class="{ 'bg-muted': name === selectedFile }"
                                @click="selectedFile = name"
                            >
                                {{ name }}
                            </button>
                        </li>
                    </ul>
                    <CodeEditor
                        :key="`${selectedFile}-${revealed}`"
                        :model-value="fileContents"
                        :language="fileLanguage"
                        readonly
                    />
                </div>
            </template>
        </div>

        <RunsTable v-else :deployment-id="deployment.id" :runs="runs" />
    </div>
</template>
