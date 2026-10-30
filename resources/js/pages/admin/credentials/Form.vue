<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CredentialController from '@/actions/App/Http/Controllers/Admin/CredentialController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AdminCredential = {
    id: number;
    name: string;
    type: string;
    username: string | null;
    registry_url: string | null;
};

const props = defineProps<{
    credential: AdminCredential | null;
    types: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credenziali',
                href: CredentialController.index(),
            },
        ],
    },
});

const isEdit = computed(() => props.credential !== null);
const formProps = computed(() =>
    props.credential
        ? CredentialController.update.form(props.credential.id)
        : CredentialController.store.form(),
);

const type = ref(props.credential?.type ?? 'ssh-key');
const secretLabel = computed(() =>
    type.value === 'ssh-key'
        ? 'Chiave privata (PEM)'
        : type.value === 'registry'
          ? 'Token'
          : 'Password',
);
</script>

<template>
    <Head :title="isEdit ? 'Modifica credenziale' : 'Nuova credenziale'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Modifica credenziale' : 'Nuova credenziale'"
            description="Il segreto è cifrato e non viene mai mostrato"
        />

        <Form
            v-bind="formProps"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="credential?.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="type">Tipo</Label>
                <select
                    id="type"
                    name="type"
                    v-model="type"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <InputError :message="errors.type" />
            </div>

            <div v-if="type === 'registry'" class="grid gap-2">
                <Label for="username">Utente</Label>
                <Input
                    id="username"
                    name="username"
                    :default-value="credential?.username ?? ''"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.username" />
            </div>

            <div v-if="type === 'registry'" class="grid gap-2">
                <Label for="registry_url">URL registry</Label>
                <Input
                    id="registry_url"
                    name="registry_url"
                    :default-value="credential?.registry_url ?? ''"
                    placeholder="ghcr.io"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.registry_url" />
            </div>

            <div class="grid gap-2">
                <Label for="secret">
                    {{ secretLabel }}
                    <span v-if="isEdit" class="text-muted-foreground">
                        (lascia vuoto per non cambiarlo)
                    </span>
                </Label>
                <textarea
                    v-if="type === 'ssh-key'"
                    id="secret"
                    name="secret"
                    rows="8"
                    class="rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
                    autocomplete="off"
                />
                <Input
                    v-else
                    id="secret"
                    type="password"
                    name="secret"
                    autocomplete="new-password"
                />
                <InputError :message="errors.secret" />
            </div>

            <div v-if="type === 'ssh-key'" class="grid gap-2">
                <Label for="passphrase">Passphrase</Label>
                <Input
                    id="passphrase"
                    type="password"
                    name="passphrase"
                    autocomplete="new-password"
                />
                <p class="text-xs text-muted-foreground">
                    Le chiavi con passphrase non sono supportate in questa
                    versione.
                </p>
                <InputError :message="errors.passphrase" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-credential">
                    Salva
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="CredentialController.index()">Annulla</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
