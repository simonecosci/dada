<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/api-tokens';
import { ui as apiDocs } from '@/routes/scramble/docs';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

type CreatedToken = { name: string; plain_text: string };

defineProps<{
    tokens: Token[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API tokens',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
// Flashed once by the server right after creation: it is not part of the page props and never comes back.
const created = computed(() => page.flash.token as CreatedToken | undefined);

const { copy, copied } = useClipboard();

const form = useForm<{ name: string; ability: 'read' | 'deploy' }>({
    name: '',
    ability: 'read',
});

function create() {
    form.post(ApiTokenController.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revoke(token: Token) {
    if (
        confirm(
            `Revoke the token "${token.name}"? Clients using it will stop working.`,
        )
    ) {
        router.delete(ApiTokenController.destroy(token.id).url, {
            preserveScroll: true,
        });
    }
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Never';
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Personal access tokens for the REST API, e.g. from a CI pipeline. A token acts with your permissions."
        />

        <p class="text-sm text-muted-foreground">
            Send the token as
            <code class="rounded bg-muted px-1 py-0.5 text-xs"
                >Authorization: Bearer &lt;token&gt;</code
            >. See the
            <a
                :href="apiDocs().url"
                target="_blank"
                class="text-foreground underline underline-offset-4"
                data-test="api-docs-link"
                >API documentation</a
            >.
        </p>

        <div
            v-if="created"
            class="space-y-3 rounded-xl border border-green-600/40 bg-green-600/5 p-4"
            data-test="created-token"
        >
            <p class="text-sm font-medium">
                Token "{{ created.name }}" created. Copy it now: it will not be
                shown again.
            </p>
            <div
                class="flex w-full items-stretch overflow-hidden rounded-md border border-border"
            >
                <input
                    type="text"
                    readonly
                    :value="created.plain_text"
                    aria-label="New token"
                    class="w-full bg-background p-2 font-mono text-sm text-foreground"
                />
                <button
                    type="button"
                    class="border-l border-border px-3 hover:bg-muted"
                    @click="copy(created.plain_text)"
                >
                    <Check v-if="copied" class="w-4 text-green-500" />
                    <Copy v-else class="w-4" />
                    <span class="sr-only">Copy</span>
                </button>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="create">
            <div class="grid gap-2">
                <Label for="token-name">Name</Label>
                <Input
                    id="token-name"
                    v-model="form.name"
                    required
                    maxlength="255"
                    placeholder="e.g. GitLab CI"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="token-ability">Permissions</Label>
                <select
                    id="token-ability"
                    v-model="form.ability"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                    <option value="read">Read only</option>
                    <option value="deploy">Read and deploy</option>
                </select>
                <InputError :message="form.errors.ability" />
            </div>

            <Button :disabled="form.processing" data-test="create-token">
                Create token
            </Button>
        </form>

        <div class="rounded-xl border">
            <p
                v-if="tokens.length === 0"
                class="p-4 text-sm text-muted-foreground"
            >
                No tokens yet.
            </p>
            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between border-b p-4 last:border-b-0"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                    >
                        <KeyRound class="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <p class="font-medium tracking-tight">
                                {{ token.name }}
                            </p>
                            <Badge
                                v-for="ability in token.abilities"
                                :key="ability"
                                variant="secondary"
                            >
                                {{ ability }}
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Created {{ formatDate(token.created_at) }}
                            <span class="mx-1 text-muted-foreground/50">/</span>
                            Last used {{ formatDate(token.last_used_at) }}
                        </p>
                    </div>
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    :data-test="`revoke-${token.id}`"
                    @click="revoke(token)"
                >
                    <Trash2 class="h-4 w-4" />
                    <span class="sr-only">Revoke</span>
                </Button>
            </div>
        </div>
    </div>
</template>
