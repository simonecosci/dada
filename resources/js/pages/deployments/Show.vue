<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import MemberController from '@/actions/App/Http/Controllers/Deployments/MemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Role = 'operator' | 'viewer';

type DeploymentData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    environment: string | null;
};

type Member = { id: number; name: string; email: string; role: Role };
type Candidate = { id: number; name: string; email: string };

const props = defineProps<{
    deployment: DeploymentData;
    members: Member[];
    candidates: Candidate[];
    canUpdate: boolean;
    canDelete: boolean;
    canManageMembers: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Deployment', href: DeploymentController.index() },
        ],
    },
});

const tabs = [
    { key: 'hosts', label: 'Host' },
    { key: 'members', label: 'Membri' },
    { key: 'runs', label: 'Esecuzioni' },
] as const;
const tab = ref<(typeof tabs)[number]['key']>('members');

const roleLabels: Record<Role, string> = {
    operator: 'Operatore',
    viewer: 'Osservatore',
};

const addForm = useForm<{ user_id: number | ''; role: Role }>({
    user_id: '',
    role: 'viewer',
});

function addMember() {
    addForm.post(MemberController.store(props.deployment.id).url, {
        preserveScroll: true,
        onSuccess: () => addForm.reset('user_id'),
    });
}

function changeRole(member: Member, role: Role) {
    router.put(
        MemberController.update([props.deployment.id, member.id]).url,
        { role },
        { preserveScroll: true },
    );
}

function removeMember(member: Member) {
    if (confirm(`Rimuovere ${member.name} dal deployment?`)) {
        router.delete(
            MemberController.destroy([props.deployment.id, member.id]).url,
            { preserveScroll: true },
        );
    }
}

function destroy() {
    if (confirm(`Cancellare il deployment ${props.deployment.name}?`)) {
        router.delete(DeploymentController.destroy(props.deployment.id).url);
    }
}
</script>

<template>
    <Head :title="deployment.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="deployment.name"
                :description="
                    [deployment.environment, deployment.description]
                        .filter(Boolean)
                        .join(' · ') || deployment.slug
                "
            />
            <div class="flex gap-2">
                <Button v-if="canUpdate" variant="outline" as-child>
                    <Link :href="DeploymentController.edit(deployment.id)">
                        Modifica
                    </Link>
                </Button>
                <Button v-if="canDelete" variant="destructive" @click="destroy">
                    Cancella
                </Button>
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

        <p v-if="tab === 'hosts'" class="text-sm text-muted-foreground">
            Disponibile a breve
        </p>

        <div v-else-if="tab === 'members'" class="flex flex-col gap-4">
            <InputError :message="($page.props.errors as Record<string, string>).member" />

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Utente</th>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th class="px-4 py-2 font-medium">Ruolo</th>
                            <th
                                v-if="canManageMembers"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Azioni
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in members" :key="m.id" class="border-t">
                            <td class="px-4 py-2">{{ m.name }}</td>
                            <td class="px-4 py-2">{{ m.email }}</td>
                            <td class="px-4 py-2">
                                <select
                                    v-if="canManageMembers"
                                    :value="m.role"
                                    class="rounded-md border border-input bg-background px-2 py-1 text-sm"
                                    :data-test="`role-${m.id}`"
                                    @change="
                                        changeRole(
                                            m,
                                            ($event.target as HTMLSelectElement)
                                                .value as Role,
                                        )
                                    "
                                >
                                    <option value="operator">
                                        {{ roleLabels.operator }}
                                    </option>
                                    <option value="viewer">
                                        {{ roleLabels.viewer }}
                                    </option>
                                </select>
                                <Badge v-else variant="secondary">
                                    {{ roleLabels[m.role] }}
                                </Badge>
                            </td>
                            <td
                                v-if="canManageMembers"
                                class="px-4 py-2 text-right"
                            >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="removeMember(m)"
                                >
                                    Rimuovi
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="members.length === 0">
                            <td
                                :colspan="canManageMembers ? 4 : 3"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                Nessun membro.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form
                v-if="canManageMembers"
                class="flex max-w-xl flex-col gap-2"
                @submit.prevent="addMember"
            >
                <label class="text-sm font-medium" for="member-user">
                    Aggiungi membro
                </label>
                <div class="flex gap-2">
                    <select
                        id="member-user"
                        v-model="addForm.user_id"
                        class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Seleziona un utente</option>
                        <option v-for="c in candidates" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.email }})
                        </option>
                    </select>
                    <select
                        v-model="addForm.role"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        aria-label="Ruolo"
                    >
                        <option value="operator">{{ roleLabels.operator }}</option>
                        <option value="viewer">{{ roleLabels.viewer }}</option>
                    </select>
                    <Button
                        :disabled="addForm.processing || addForm.user_id === ''"
                        data-test="add-member"
                    >
                        Aggiungi
                    </Button>
                </div>
                <InputError :message="addForm.errors.user_id" />
                <InputError :message="addForm.errors.role" />
            </form>
        </div>

        <p v-else class="text-sm text-muted-foreground">Disponibile a breve</p>
    </div>
</template>
