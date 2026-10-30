<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_maintainer: boolean;
};

const props = defineProps<{
    user: AdminUser | null;
}>();

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

const isEdit = computed(() => props.user !== null);
const formProps = computed(() =>
    props.user
        ? UserController.update.form(props.user.id)
        : UserController.store.form(),
);

const isAdmin = ref(props.user?.is_admin ?? false);
const isMaintainer = ref(props.user?.is_maintainer ?? false);
</script>

<template>
    <Head :title="isEdit ? 'Modifica utente' : 'Nuovo utente'" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Modifica utente' : 'Nuovo utente'"
            description="Nome, email, password e ruoli"
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
                    :default-value="user?.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="user?.email"
                    required
                    autocomplete="off"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">
                    Password
                    <span v-if="isEdit" class="text-muted-foreground">
                        (lascia vuoto per non cambiarla)
                    </span>
                </Label>
                <Input
                    id="password"
                    type="password"
                    name="password"
                    :required="!isEdit"
                    minlength="12"
                    autocomplete="new-password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-3">
                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_admin"
                        name="is_admin"
                        value="1"
                        v-model="isAdmin"
                    />
                    <Label for="is_admin">Admin</Label>
                </div>
                <InputError :message="errors.is_admin" />

                <div class="flex items-center gap-3">
                    <Checkbox
                        id="is_maintainer"
                        name="is_maintainer"
                        value="1"
                        v-model="isMaintainer"
                    />
                    <Label for="is_maintainer">Maintainer</Label>
                </div>
                <InputError :message="errors.is_maintainer" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="save-user">
                    Salva
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="UserController.index()">Annulla</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
