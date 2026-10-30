<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import HostController from '@/actions/App/Http/Controllers/Admin/HostController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type AdminHost = {
    id: number;
    name: string;
    hostname: string;
    port: number;
    username: string;
    reachable: boolean | null;
    credential: { id: number; name: string; type: string } | null;
    jump_host: { id: number; name: string } | null;
};

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

defineProps<{
    hosts: AdminHost[];
}>();

const page = usePage();
const deleteError = computed(() => {
    const error = page.props.errors?.host;

    return typeof error === 'string' ? error : undefined;
});

function destroy(host: AdminHost) {
    if (confirm(`Delete host ${host.name}?`)) {
        router.delete(HostController.destroy(host.id).url);
    }
}
</script>

<template>
    <Head title="Host" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Host"
                description="Servers reachable over SSH, optionally through a jump host"
            />
            <Button as-child>
                <Link :href="HostController.create()" data-test="create-host">
                    New host
                </Link>
            </Button>
        </div>

        <InputError :message="deleteError" />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Address</th>
                        <th class="px-4 py-2 font-medium">Credential</th>
                        <th class="px-4 py-2 font-medium">Jump host</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="host in hosts" :key="host.id" class="border-t">
                        <td class="px-4 py-2">
                            <Link
                                :href="HostController.show(host.id)"
                                class="underline"
                            >
                                {{ host.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">
                            {{ host.username }}@{{ host.hostname }}:{{
                                host.port
                            }}
                        </td>
                        <td class="px-4 py-2">{{ host.credential?.name }}</td>
                        <td class="px-4 py-2">{{ host.jump_host?.name }}</td>
                        <td class="px-4 py-2">
                            <Badge v-if="host.reachable === true">
                                Reachable
                            </Badge>
                            <Badge
                                v-else-if="host.reachable === false"
                                variant="destructive"
                            >
                                Unreachable
                            </Badge>
                            <Badge v-else variant="secondary">
                                Not verified
                            </Badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="HostController.edit(host.id)">
                                        Edit
                                    </Link>
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    @click="destroy(host)"
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
