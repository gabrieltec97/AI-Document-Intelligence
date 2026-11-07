<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();

const pageTitle = computed(() => {
    if (page.url.startsWith('/chat')) {
        return 'Chat com documentos';
    }

    if (page.url.startsWith('/documents')) {
        return 'Documentos';
    }

    return 'Visão geral';
});

const user = computed(() => page.props.auth?.user);

const userInitials = computed(() => {
    return (user.value?.name || 'U')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0].toUpperCase())
        .join('');
});

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 lg:flex">

        <!-- Sidebar -->
        <aside class="flex w-full flex-col border-b border-slate-800 bg-slate-900 lg:min-h-screen lg:w-64 lg:border-b-0 lg:border-r">

            <div class="flex h-20 items-center gap-3 border-b border-slate-800 px-6">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold">
                    AI
                </div>

                <div>
                    <h1 class="font-bold tracking-wide">Document AI</h1>
                    <p class="text-xs text-slate-400">Intelligent platform</p>
                </div>
            </div>


            <nav class="flex-1 space-y-2 p-4">
                <p class="mb-3 px-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Menu principal
                </p>

                <Link
                    href="/dashboard"
                    :class="$page.url === '/dashboard'
            ? 'bg-indigo-600/15 text-indigo-400'
            : 'text-slate-400 hover:bg-slate-800 hover:text-white'"
                    class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition"
                >
                    <span>▦</span>
                    Dashboard
                </Link>

                <Link
                    href="/documents"
                    :class="$page.url.startsWith('/documents')
            ? 'bg-indigo-600/15 text-indigo-400'
            : 'text-slate-400 hover:bg-slate-800 hover:text-white'"
                    class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition"
                >
                    <span>📄</span>
                    Documentos
                </Link>

                <Link
                    href="/chat"
                    :class="page.url.startsWith('/chat')
        ? 'bg-indigo-600/15 text-indigo-400'
        : 'text-slate-400 hover:bg-slate-800 hover:text-white'"
                    class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition"
                >
                    <span>✦</span>
                    Chat com documentos
                </Link>
            </nav>


            <div class="border-t border-slate-800 p-4">
                <div class="rounded-lg bg-slate-800/60 p-3">
                    <p class="text-xs font-medium text-slate-300">AI Document Intelligence</p>
                    <p class="mt-1 text-xs text-slate-500">Sua inteligência documental.</p>
                </div>
            </div>
        </aside>

        <!-- Conteúdo principal -->
        <div class="flex min-w-0 flex-1 flex-col">

            <header class="flex min-h-20 items-center justify-between border-b border-slate-800 bg-slate-900/50 px-5 py-4 sm:px-8">
                <div>
                    <p class="text-sm text-slate-400">Área de trabalho</p>
                    <h2 class="text-lg font-semibold">{{ pageTitle }}</h2>
                </div>

                <div class="flex items-center gap-4">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium text-white">
                            {{ user?.name }}
                        </p>
                        <p class="text-xs text-slate-400">
                            {{ user?.email }}
                        </p>
                    </div>

                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold">
                        {{ userInitials }}
                    </div>

                    <button
                        type="button"
                        @click="logout"
                        class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 transition hover:border-red-500 hover:text-red-400"
                    >
                        Sair
                    </button>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-8">
                <slot />
            </main>

        </div>
    </div>
</template>
