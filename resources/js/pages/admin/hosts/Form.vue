<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import HostController from '@/actions/App/Http/Controllers/Admin/HostController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AdminHost = {
    id: number;
    name: string;
    hostname: string;
    port: number;
    username: string;
    credential_id: number;
    jump_host_id: number | null;
    privilege_mode: string;
    sudo_credential_id: number | null;
};

const props = defineProps<{
    host: AdminHost | null;
    credentials: { id: number; name: string; type: string }[];
    jumpHosts: { id: number; name: string }[];
    privilegeModes: { value: string; label: string }[];
    sudoCredentials: { id: number; name: string }[];
}>();

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

const isEdit = computed(() => props.host !== null);
const formProps = computed(() =>
    props.host
        ? HostController.update.form(props.host.id)
        : HostController.store.form(),
);

const privilegeMode = ref(props.host?.privilege_mode ?? 'none');

const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';
</script>

<template>
    <Head :title="isEdit ? 'Edit host' : 'New host'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Edit host' : 'New host'"
            description="SSH connection, credential and optional jump host"
        />

        <Form
            v-bind="formProps"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="host?.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="hostname">Hostname</Label>
                <Input
                    id="hostname"
                    name="hostname"
                    :default-value="host?.hostname"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.hostname" />
            </div>

            <div class="grid gap-2">
                <Label for="port">Port</Label>
                <Input
                    id="port"
                    type="number"
                    name="port"
                    :default-value="host?.port ?? 22"
                    min="1"
                    max="65535"
                    required
                />
                <InputError :message="errors.port" />
            </div>

            <div class="grid gap-2">
                <Label for="username">SSH user</Label>
                <Input
                    id="username"
                    name="username"
                    :default-value="host?.username"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="credential_id">Credential</Label>
                <select
                    id="credential_id"
                    name="credential_id"
                    :class="selectClass"
                    :value="host?.credential_id ?? ''"
                    required
                >
                    <option value="" disabled>Select…</option>
                    <option v-for="c in credentials" :key="c.id" :value="c.id">
                        {{ c.name }} ({{ c.type }})
                    </option>
                </select>
                <InputError :message="errors.credential_id" />
            </div>

            <div class="grid gap-2">
                <Label for="jump_host_id">Jump host</Label>
                <select
                    id="jump_host_id"
                    name="jump_host_id"
                    :class="selectClass"
                    :value="host?.jump_host_id ?? ''"
                >
                    <option value="">None</option>
                    <option v-for="j in jumpHosts" :key="j.id" :value="j.id">
                        {{ j.name }}
                    </option>
                </select>
                <InputError :message="errors.jump_host_id" />
            </div>

            <div class="grid gap-2">
                <Label for="privilege_mode">Privileges</Label>
                <select
                    id="privilege_mode"
                    name="privilege_mode"
                    v-model="privilegeMode"
                    :class="selectClass"
                    required
                >
                    <option
                        v-for="m in privilegeModes"
                        :key="m.value"
                        :value="m.value"
                    >
                        {{ m.label }}
                    </option>
                </select>
                <InputError :message="errors.privilege_mode" />
            </div>

            <div v-if="privilegeMode === 'sudo_password'" class="grid gap-2">
                <Label for="sudo_credential_id">Sudo password credential</Label>
                <select
                    id="sudo_credential_id"
                    name="sudo_credential_id"
                    :class="selectClass"
                    :value="host?.sudo_credential_id ?? ''"
                    required
                >
                    <option value="" disabled>Select…</option>
                    <option
                        v-for="c in sudoCredentials"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.name }}
                    </option>
                </select>
                <InputError :message="errors.sudo_credential_id" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-host">
                    Save
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="HostController.index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
