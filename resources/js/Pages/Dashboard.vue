<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

const page = usePage();

const user = computed(() => page.props.auth?.user);
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl space-y-8">

            <!-- Boas-vindas -->
            <section>
                <h1 class="text-2xl font-bold text-white sm:text-3xl">
                    Olá, {{ user?.name }}!
                </h1>

                <p class="mt-2 text-slate-400">
                    Bem-vindo ao seu ambiente de inteligência documental.
                </p>
            </section>

            <!-- Introdução -->
            <section class="overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-indigo-950 to-slate-900 p-6 sm:p-8">
                <div class="max-w-2xl">
                    <span class="text-xs font-semibold uppercase tracking-widest text-indigo-400">
                        AI Document Intelligence
                    </span>

                    <h2 class="mt-3 text-2xl font-bold text-white sm:text-3xl">
                        Seus documentos, mais inteligentes.
                    </h2>

                    <p class="mt-3 leading-relaxed text-slate-300">
                        Organize documentos, extraia informações e utilize
                        inteligência artificial para transformar arquivos
                        em conhecimento.
                    </p>
                </div>
            </section>

            <!-- Módulos -->
            <section>
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-white">
                        Módulos da plataforma
                    </h2>

                    <p class="mt-1 text-sm text-slate-400">
                        Conheça os recursos do seu ambiente.
                    </p>
                </div>

                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">

                    <article class="rounded-xl border border-slate-800 bg-slate-900 p-6">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-500/10 text-2xl">
                            📄
                        </div>

                        <h3 class="mt-5 font-semibold text-white">
                            Gestão de documentos
                        </h3>

                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            Envie, organize e acompanhe o processamento
                            dos seus arquivos.
                        </p>

                        <span class="mt-5 inline-flex rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-400">
                            Interface em desenvolvimento
                        </span>
                    </article>

                    <article class="rounded-xl border border-slate-800 bg-slate-900 p-6">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-purple-500/10 text-2xl">
                            🧠
                        </div>

                        <h3 class="mt-5 font-semibold text-white">
                            Análise inteligente
                        </h3>

                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            Extraia informações, identifique conteúdos
                            importantes e gere resumos.
                        </p>

                        <span class="mt-5 inline-flex rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-400">
                            Próxima etapa
                        </span>
                    </article>

                    <article class="rounded-xl border border-slate-800 bg-slate-900 p-6">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10 text-2xl">
                            💬
                        </div>

                        <h3 class="mt-5 font-semibold text-white">
                            Chat com documentos
                        </h3>

                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            Faça perguntas e encontre respostas utilizando
                            o conteúdo dos seus arquivos.
                        </p>

                        <span class="mt-5 inline-flex rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-400">
                            Próxima etapa
                        </span>
                    </article>

                </div>
            </section>

        </div>
    </AppLayout>
</template>
