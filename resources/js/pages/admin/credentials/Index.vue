<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CredentialController from '@/actions/App/Http/Controllers/Admin/CredentialController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type AdminCredential = {
    id: number;
    name: string;
    type: 'password' | 'ssh-key' | 'registry';
    username: string | null;
    registry_url: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Credentials',
                href: CredentialController.index(),
            },
        ],
    },
});

defineProps<{
    credentials: AdminCredential[];
}>();

const page = usePage();
const deleteError = computed(() => {
    const error = page.props.errors?.credential;

    return typeof error === 'string' ? error : undefined;
});

function destroy(credential: AdminCredential) {
    if (confirm(`Delete credential ${credential.name}?`)) {
        router.delete(CredentialController.destroy(credential.id).url);
    }
}
</script>

<template>
    <Head title="Credentials" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Credentials"
                description="Passwords, SSH keys and registry access"
            />
            <Button as-child>
                <Link
                    :href="CredentialController.create()"
                    data-test="create-credential"
                >
                    New credential
                </Link>
            </Button>
        </div>

        <InputError :message="deleteError" />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">User</th>
                        <th class="px-4 py-2 font-medium">Registry</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="credential in credentials"
                        :key="credential.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ credential.name }}</td>
                        <td class="px-4 py-2">
                            <Badge variant="secondary">
                                {{ credential.type }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">{{ credential.username }}</td>
                        <td class="px-4 py-2">{{ credential.registry_url }}</td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link
                                        :href="
                                            CredentialController.edit(
                                                credential.id,
                                            )
                                        "
                                    >
                                        Edit
                                    </Link>
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    @click="destroy(credential)"
                                >
                                    Delete
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
