<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    variableId: number;
    field: 'default' | 'value';
}>();

const revealed = ref<string | null>(null);
const error = ref<string>();
const loading = ref(false);

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function reveal() {
    loading.value = true;
    error.value = undefined;

    try {
        const response = await fetch(`/variables/${props.variableId}/reveal`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ field: props.field }),
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        revealed.value = (await response.json()).value ?? '';
    } catch {
        error.value = 'Impossibile mostrare il valore.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="flex items-center gap-2">
        <code
            v-if="revealed !== null"
            class="rounded bg-muted px-2 py-1 text-xs break-all"
            data-test="secret-value"
        >
            {{ revealed }}
        </code>
        <span v-else class="text-sm text-muted-foreground">••••••••</span>
        <Button
            v-if="revealed === null"
            type="button"
            variant="outline"
            size="sm"
            :disabled="loading"
            @click="reveal"
        >
            Mostra
        </Button>
        <Button
            v-else
            type="button"
            variant="outline"
            size="sm"
            @click="revealed = null"
        >
            Nascondi
        </Button>
        <InputError :message="error" />
    </div>
</template>
