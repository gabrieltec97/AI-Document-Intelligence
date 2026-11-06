<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login');
}
</script>

<template>
    <Head title="Entrar" />

    <main class="flex min-h-screen items-center justify-center bg-slate-950 px-4 text-white">
        <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
            <h1 class="text-3xl font-bold">AI Document Intelligence</h1>
            <p class="mt-2 text-sm text-slate-400">Entre na sua conta para continuar.</p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div>
                    <label class="mb-2 block text-sm text-slate-300">E-mail</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-3 outline-none focus:border-indigo-500"
                    />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-400">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label class="mb-2 block text-sm text-slate-300">Senha</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-3 outline-none focus:border-indigo-500"
                    />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-400">
                        {{ form.errors.password }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input v-model="form.remember" type="checkbox" class="accent-indigo-500" />
                    Manter conectado
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-indigo-600 py-3 font-semibold transition hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ form.processing ? 'Entrando...' : 'Entrar' }}
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-400">
                Ainda não tem conta?
                <Link href="/register" class="text-indigo-400 hover:text-indigo-300">
                    Criar conta
                </Link>
            </p>
        </div>
    </main>
</template>
