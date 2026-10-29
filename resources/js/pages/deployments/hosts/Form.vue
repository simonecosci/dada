<script setup lang="ts">
import { Form, Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import DeploymentController from "@/actions/App/Http/Controllers/Deployments/DeploymentController";
import DeploymentHostController from "@/actions/App/Http/Controllers/Deployments/DeploymentHostController";
import Heading from "@/components/Heading.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

type HostOption = { id: number; name: string; hostname: string };

const props = defineProps<{
    deployment: { id: number; name: string; slug: string };
    deploymentHost: {
        id: number;
        path: string;
        orchestrator: string;
        stack_name: string;
        host: HostOption;
    } | null;
    hosts: HostOption[];
    orchestrators: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Deployment", href: DeploymentController.index() },
        ],
    },
});

const isEdit = computed(() => props.deploymentHost !== null);
const formProps = computed(() =>
    props.deploymentHost
        ? DeploymentHostController.update.form([
              props.deployment.id,
              props.deploymentHost.id,
          ])
        : DeploymentHostController.store.form(props.deployment.id),
);
const selectClass =
    "h-9 rounded-md border border-input bg-background px-3 text-sm";
</script>

<template>
    <Head :title="isEdit ? 'Modifica host' : 'Aggiungi host'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Modifica host' : 'Aggiungi host'"
            :description="deployment.name"
        />

        <Form
            v-bind="formProps"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div v-if="!isEdit" class="grid gap-2">
                <Label for="host_id">Host</Label>
                <select
                    id="host_id"
                    name="host_id"
                    required
                    :class="selectClass"
                >
                    <option value="" disabled selected>
                        Seleziona un host
                    </option>
                    <option v-for="h in hosts" :key="h.id" :value="h.id">
                        {{ h.name }} ({{ h.hostname }})
                    </option>
                </select>
                <InputError :message="errors.host_id" />
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Host: {{ deploymentHost?.host.name }} ({{
                    deploymentHost?.host.hostname
                }})
            </p>

            <div class="grid gap-2">
                <Label for="path">Percorso sull'host</Label>
                <Input
                    id="path"
                    name="path"
                    :default-value="deploymentHost?.path"
                    placeholder="/srv/cliente-x"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.path" />
            </div>

            <div class="grid gap-2">
                <Label for="orchestrator">Orchestratore</Label>
                <select
                    id="orchestrator"
                    name="orchestrator"
                    :class="selectClass"
                    :value="deploymentHost?.orchestrator ?? 'compose'"
                >
                    <option v-for="o in orchestrators" :key="o" :value="o">
                        {{ o }}
                    </option>
                </select>
                <InputError :message="errors.orchestrator" />
            </div>

            <div class="grid gap-2">
                <Label for="stack_name">Nome dello stack</Label>
                <Input
                    id="stack_name"
                    name="stack_name"
                    :default-value="deploymentHost?.stack_name"
                    :placeholder="deployment.slug"
                    :required="isEdit"
                    autocomplete="off"
                />
                <InputError :message="errors.stack_name" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-host">
                    Salva
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="DeploymentController.show(deployment.id)">
                        Annulla
                    </Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
