<script setup>
import { computed, nextTick, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const messages = ref([]);
const question = ref('');
const sending = ref(false);
const error = ref('');
const messagesContainer = ref(null);

const canSend = computed(() => {
    return question.value.trim().length >= 3 && !sending.value;
});

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function headers() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    };
}

async function scrollToBottom() {
    await nextTick();

    if (messagesContainer.value) {
        messagesContainer.value.scrollTop =
            messagesContainer.value.scrollHeight;
    }
}

async function sendMessage() {
    const query = question.value.trim();

    if (query.length < 3 || sending.value) {
        return;
    }

    error.value = '';
    question.value = '';
    sending.value = true;

    messages.value.push({
        id: crypto.randomUUID(),
        role: 'user',
        content: query,
    });

    await scrollToBottom();

    try {
        const response = await fetch('/api/ask', {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers(),
            body: JSON.stringify({
                query,
                limit: 5,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (response.status === 401) {
            throw new Error('Sua sessão expirou. Entre novamente para continuar.');
        }

        if (response.status === 419) {
            throw new Error('Sua sessão de segurança expirou. Atualize a página e tente novamente.');
        }

        if (response.status === 422) {
            throw new Error(
                data.message || 'A pergunta enviada não passou pela validação.'
            );
        }

        if (!response.ok) {
            throw new Error(
                data.message || 'Não foi possível obter uma resposta.'
            );
        }

        messages.value.push({
            id: crypto.randomUUID(),
            role: 'assistant',
            content: data.answer,
            sources: data.sources || [],
            totalSources: data.total_sources || 0,
        });
    } catch (exception) {
        error.value = exception.message || 'Ocorreu um erro inesperado.';
    } finally {
        sending.value = false;
        await scrollToBottom();
    }
}

function useSuggestion(value) {
    question.value = value;
}

function clearConversation() {
    messages.value = [];
    error.value = '';
}

function formatSimilarity(value) {
    return `${(Number(value) * 100).toFixed(1)}%`;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex h-[calc(100vh-9rem)] max-w-6xl flex-col">

            <!-- Cabeçalho do chat -->
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white">
                        Assistente documental
                    </h1>

                    <p class="mt-1 text-sm text-slate-400">
                        Faça perguntas e encontre respostas nos seus documentos.
                    </p>
                </div>

                <button
                    v-if="messages.length"
                    type="button"
                    @click="clearConversation"
                    class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-300 transition hover:border-red-500 hover:text-red-400"
                >
                    Limpar conversa
                </button>
            </div>

            <!-- Área de mensagens -->
            <div
                ref="messagesContainer"
                class="min-h-0 flex-1 overflow-y-auto rounded-2xl border border-slate-800 bg-slate-900/60 p-4 sm:p-6"
            >

                <!-- Estado inicial -->
                <div
                    v-if="messages.length === 0"
                    class="flex h-full flex-col items-center justify-center text-center"
                >
                    <div class="mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-600/15 text-3xl text-indigo-400">
                        ✦
                    </div>

                    <h2 class="text-xl font-semibold text-white">
                        Como posso ajudar?
                    </h2>

                    <p class="mt-2 max-w-md text-sm leading-6 text-slate-400">
                        Pergunte sobre contratos, relatórios, manuais ou qualquer informação presente nos documentos que você enviou.
                    </p>

                    <div class="mt-8 grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            @click="useSuggestion('Faça um resumo dos meus documentos.')"
                            class="rounded-xl border border-slate-700 bg-slate-800/50 p-4 text-left text-sm text-slate-300 transition hover:border-indigo-500 hover:bg-slate-800"
                        >
                            <span class="mb-2 block text-indigo-400">✦ Resumo</span>
                            Faça um resumo dos meus documentos.
                        </button>

                        <button
                            type="button"
                            @click="useSuggestion('Quais são os principais pontos dos documentos?')"
                            class="rounded-xl border border-slate-700 bg-slate-800/50 p-4 text-left text-sm text-slate-300 transition hover:border-indigo-500 hover:bg-slate-800"
                        >
                            <span class="mb-2 block text-indigo-400">⌕ Análise</span>
                            Identifique os principais pontos.
                        </button>

                        <button
                            type="button"
                            @click="useSuggestion('Existem datas ou prazos importantes nos documentos?')"
                            class="rounded-xl border border-slate-700 bg-slate-800/50 p-4 text-left text-sm text-slate-300 transition hover:border-indigo-500 hover:bg-slate-800"
                        >
                            <span class="mb-2 block text-indigo-400">◷ Datas</span>
                            Encontre datas e prazos importantes.
                        </button>

                        <button
                            type="button"
                            @click="useSuggestion('Quais documentos falam sobre pagamentos?')"
                            class="rounded-xl border border-slate-700 bg-slate-800/50 p-4 text-left text-sm text-slate-300 transition hover:border-indigo-500 hover:bg-slate-800"
                        >
                            <span class="mb-2 block text-indigo-400">▤ Pesquisa</span>
                            Pesquise um assunto específico.
                        </button>
                    </div>
                </div>

                <!-- Histórico -->
                <div v-else class="space-y-6">
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="flex gap-3"
                        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            v-if="message.role === 'assistant'"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600/20 font-bold text-indigo-400"
                        >
                            AI
                        </div>

                        <div
                            class="max-w-[90%] rounded-2xl p-4 sm:max-w-[80%]"
                            :class="message.role === 'user'
                                ? 'bg-indigo-600 text-white'
                                : 'border border-slate-700 bg-slate-800/80 text-slate-200'"
                        >
                            <p class="whitespace-pre-wrap text-sm leading-7">{{ message.content }}</p>

                            <!-- Fontes utilizadas -->
                            <div
                                v-if="message.role === 'assistant' && message.sources?.length"
                                class="mt-5 border-t border-slate-700 pt-4"
                            >
                                <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    Fontes consultadas ({{ message.totalSources }})
                                </p>

                                <div class="space-y-2">
                                    <div
                                        v-for="(source, index) in message.sources"
                                        :key="source.chunk_id"
                                        class="flex items-start gap-3 rounded-lg bg-slate-900/70 p-3"
                                    >
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-600/20 text-xs font-bold text-indigo-400">
                                            {{ index + 1 }}
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <p class="break-words text-sm font-medium text-slate-200">
                                                {{ source.document_name }}
                                            </p>

                                            <p class="mt-1 text-xs text-slate-500">
                                                Trecho {{ source.chunk_index + 1 }}
                                                · Similaridade {{ formatSimilarity(source.similarity) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="message.role === 'assistant' && message.sources?.length === 0"
                                class="mt-4 border-t border-slate-700 pt-3 text-xs text-slate-500"
                            >
                                Nenhuma fonte documental foi encontrada para esta resposta.
                            </p>
                        </div>

                        <div
                            v-if="message.role === 'user'"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-700 text-sm font-bold text-white"
                        >
                            U
                        </div>
                    </div>

                    <!-- Carregamento -->
                    <div v-if="sending" class="flex items-start gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600/20 font-bold text-indigo-400">
                            AI
                        </div>

                        <div class="rounded-2xl border border-slate-700 bg-slate-800/80 px-4 py-3">
                            <div class="flex items-center gap-2 text-sm text-slate-400">
                                <span class="h-2 w-2 animate-pulse rounded-full bg-indigo-400"></span>
                                Analisando documentos e gerando resposta...
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Erros -->
            <div
                v-if="error"
                class="mt-3 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300"
            >
                {{ error }}
            </div>

            <!-- Campo de pergunta -->
            <form
                class="mt-4 rounded-2xl border border-slate-800 bg-slate-900 p-3"
                @submit.prevent="sendMessage"
            >
                <div class="flex items-end gap-3">
                    <textarea
                        v-model="question"
                        rows="2"
                        maxlength="2000"
                        :disabled="sending"
                        placeholder="Pergunte algo sobre seus documentos..."
                        class="max-h-40 min-h-12 flex-1 resize-y bg-transparent px-3 py-2 text-sm text-white outline-none placeholder:text-slate-500 disabled:opacity-50"
                        @keydown.enter.exact.prevent="sendMessage"
                    ></textarea>

                    <button
                        type="submit"
                        :disabled="!canSend"
                        class="flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <span>{{ sending ? 'Aguarde' : 'Enviar' }}</span>
                        <span v-if="!sending">➤</span>
                    </button>
                </div>

                <div class="flex justify-between px-3 pb-1 pt-2 text-xs text-slate-500">
                    <span>As respostas são baseadas nos seus documentos processados.</span>
                    <span>{{ question.length }}/2000</span>
                </div>
            </form>

        </div>
    </AppLayout>
</template>
