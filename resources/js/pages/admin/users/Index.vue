<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_maintainer: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Utenti',
                href: UserController.index(),
            },
        ],
    },
});

defineProps<{
    users: AdminUser[];
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);
const deleteError = computed(() => {
    const error = page.props.errors?.user;

    return typeof error === 'string' ? error : undefined;
});

function destroy(user: AdminUser) {
    if (confirm(`Cancellare l'utente ${user.email}?`)) {
        router.delete(UserController.destroy(user.id).url);
    }
}
</script>

<template>
    <Head title="Utenti" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Utenti"
                description="Gestione di admin e maintainer"
            />
            <Button as-child>
                <Link :href="UserController.create()" data-test="create-user">
                    Nuovo utente
                </Link>
            </Button>
        </div>

        <InputError :message="deleteError" />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nome</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Ruoli</th>
                        <th class="px-4 py-2 text-right font-medium">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id" class="border-t">
                        <td class="px-4 py-2">{{ user.name }}</td>
                        <td class="px-4 py-2">{{ user.email }}</td>
                        <td class="flex gap-2 px-4 py-2">
                            <Badge v-if="user.is_admin">Admin</Badge>
                            <Badge
                                v-if="user.is_maintainer"
                                variant="secondary"
                            >
                                Maintainer
                            </Badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="UserController.edit(user.id)">
                                        Modifica
                                    </Link>
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    :disabled="user.id === currentUserId"
                                    @click="destroy(user)"
                                >
                                    Cancella
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
