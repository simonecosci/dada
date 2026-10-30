<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Boxes,
    FolderGit2,
    KeyRound,
    LayoutGrid,
    Rocket,
    Server,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/Deployments/DeploymentController';
import ProjectController from '@/actions/App/Http/Controllers/Catalog/ProjectController';
import CredentialController from '@/actions/App/Http/Controllers/Admin/CredentialController';
import HostController from '@/actions/App/Http/Controllers/Admin/HostController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Catalog',
        href: ProjectController.index(),
        icon: Boxes,
    },
    {
        title: 'Deployment',
        href: DeploymentController.index(),
        icon: Rocket,
    },
];

const adminNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin
        ? [
              {
                  title: 'Users',
                  href: UserController.index(),
                  icon: Users,
              },
              {
                  title: 'Credentials',
                  href: CredentialController.index(),
                  icon: KeyRound,
              },
              {
                  title: 'Host',
                  href: HostController.index(),
                  icon: Server,
              },
          ]
        : [],
);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
