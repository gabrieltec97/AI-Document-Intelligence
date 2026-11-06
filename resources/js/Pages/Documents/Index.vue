<script setup>
import { onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const documents = ref([]);
const pagination = ref(null);
const loading = ref(true);
const uploading = ref(false);
const deletingId = ref(null);
const error = ref('');
const success = ref('');
const selectedDocument = ref(null);
const loadingDetails = ref(false);

const fileInput = ref(null);

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function headers() {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    };
}

async function loadDocuments(url = '/documents/data') {
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch(url, {
            headers: headers(),
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Não foi possível carregar os documentos.');
        }

        const result = await response.json();

        documents.value = result.data;
        pagination.value = result.meta;
    } catch (err) {
        error.value = err.message;
    } finally {
        loading.value = false;
    }
}

async function uploadDocument(event) {
    const file = event.target.files?.[0];

    if (!file) return;

    error.value = '';
    success.value = '';

    const formData = new FormData();
    formData.append('document', file);

    uploading.value = true;

    try {
        const response = await fetch('/documents', {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            body: formData,
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(
                result.message || 'Não foi possível enviar o documento.'
            );
        }

        success.value = 'Documento enviado com sucesso!';
        await loadDocuments();
    } catch (err) {
        error.value = err.message;
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}

async function deleteDocument(document) {
    if (!confirm(`Deseja realmente excluir "${document.original_name}"?`)) {
        return;
    }

    error.value = '';
    success.value = '';
    deletingId.value = document.id;

    try {
        const response = await fetch(`/documents/${document.id}`, {
            method: 'DELETE',
            headers: headers(),
            credentials: 'same-origin',
        });

        const result = await response.json();

        if (!response.ok) {
            throw new Error(
                result.message || 'Não foi possível excluir o documento.'
            );
        }

        success.value = 'Documento excluído com sucesso!';
        selectedDocument.value = null;

        await loadDocuments();
    } catch (err) {
        error.value = err.message;
    } finally {
        deletingId.value = null;
    }
}

async function viewDocument(document) {
    selectedDocument.value = null;
    loadingDetails.value = true;
    error.value = '';

    try {
        const response = await fetch(`/documents/${document.id}`, {
            headers: headers(),
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Não foi possível carregar os detalhes.');
        }

        const result = await response.json();
        selectedDocument.value = result.data;
    } catch (err) {
        error.value = err.message;
    } finally {
        loadingDetails.value = false;
    }
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function formatDate(date) {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(date));
}

function statusLabel(status) {
    const labels = {
        uploaded: 'Enviado',
        processing: 'Processando',
        processed: 'Concluído',
        failed: 'Falhou',
    };

    return labels[status] || status;
}

function statusClass(status) {
    const classes = {
        uploaded: 'bg-blue-500/10 text-blue-400',
        processing: 'bg-amber-500/10 text-amber-400',
        processed: 'bg-emerald-500/10 text-emerald-400',
        failed: 'bg-red-500/10 text-red-400',
    };

    return classes[status] || 'bg-slate-700 text-slate-300';
}

onMounted(() => loadDocuments());
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl space-y-6">

            <section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-white">
                        Meus documentos
                    </h1>

                    <p class="mt-1 text-sm text-slate-400">
                        Gerencie seus arquivos e acompanhe o processamento.
                    </p>
                </div>

                <label
                    class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-500"
                    :class="{ 'pointer-events-none opacity-50': uploading }"
                >
                    <span>＋</span>
                    {{ uploading ? 'Enviando...' : 'Enviar documento' }}

                    <input
                        ref="fileInput"
                        type="file"
                        accept=".pdf,.docx,.png,.jpg,.jpeg"
                        class="hidden"
                        :disabled="uploading"
                        @change="uploadDocument"
                    />
                </label>
            </section>

            <div
                v-if="error"
                class="rounded-lg border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300"
            >
                {{ error }}
            </div>

            <div
                v-if="success"
                class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-300"
            >
                {{ success }}
            </div>

            <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-white">Arquivos enviados</h2>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ pagination?.total ?? 0 }} documento(s)
                        </p>
                    </div>

                    <button
                        @click="loadDocuments()"
                        class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 hover:bg-slate-800"
                    >
                        Atualizar
                    </button>
                </div>

                <div v-if="loading" class="p-10 text-center text-sm text-slate-400">
                    Carregando documentos...
                </div>

                <div
                    v-else-if="documents.length === 0"
                    class="p-12 text-center"
                >
                    <div class="text-4xl">📂</div>

                    <h3 class="mt-4 font-semibold text-white">
                        Nenhum documento encontrado
                    </h3>

                    <p class="mt-2 text-sm text-slate-400">
                        Envie seu primeiro arquivo para começar.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-800/50 text-xs uppercase text-slate-400">
                        <tr>
                            <th class="px-5 py-4">Documento</th>
                            <th class="px-5 py-4">Tamanho</th>
                            <th class="px-5 py-4">Status</th>
                            <th class="px-5 py-4">Data</th>
                            <th class="px-5 py-4 text-right">Ações</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                        <tr
                            v-for="document in documents"
                            :key="document.id"
                            class="transition hover:bg-slate-800/30"
                        >
                            <td class="max-w-xs px-5 py-4">
                                <p class="truncate font-medium text-white">
                                    {{ document.original_name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ document.mime_type }}
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-slate-400">
                                {{ formatSize(document.file_size) }}
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-medium"
                                        :class="statusClass(document.status)"
                                    >
                                        {{ statusLabel(document.status) }}
                                    </span>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-slate-400">
                                {{ formatDate(document.created_at) }}
                            </td>

                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                <button
                                    @click="viewDocument(document)"
                                    class="mr-3 text-indigo-400 hover:text-indigo-300"
                                >
                                    Ver
                                </button>

                                <button
                                    @click="deleteDocument(document)"
                                    :disabled="deletingId === document.id || document.status === 'processing'"
                                    class="text-red-400 hover:text-red-300 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {{ deletingId === document.id ? 'Excluindo...' : 'Excluir' }}
                                </button>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="!loading && pagination && pagination.last_page > 1"
                    class="flex items-center justify-between border-t border-slate-800 px-5 py-4"
                >
                    <span class="text-xs text-slate-400">
                        Página {{ pagination.current_page }} de {{ pagination.last_page }}
                    </span>

                    <div class="flex gap-2">
                        <button
                            :disabled="!pagination.prev_page_url"
                            @click="loadDocuments(pagination.prev_page_url)"
                            class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 disabled:opacity-30"
                        >
                            Anterior
                        </button>

                        <button
                            :disabled="!pagination.next_page_url"
                            @click="loadDocuments(pagination.next_page_url)"
                            class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300 disabled:opacity-30"
                        >
                            Próxima
                        </button>
                    </div>
                </div>
            </section>

            <section
                v-if="selectedDocument || loadingDetails"
                class="rounded-xl border border-slate-800 bg-slate-900 p-6"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold text-white">
                        Conteúdo extraído
                    </h2>

                    <button
                        @click="selectedDocument = null"
                        class="text-sm text-slate-400 hover:text-white"
                    >
                        Fechar
                    </button>
                </div>

                <p v-if="loadingDetails" class="mt-5 text-sm text-slate-400">
                    Carregando conteúdo...
                </p>

                <template v-else-if="selectedDocument">
                    <h3 class="mt-4 font-medium text-indigo-300">
                        {{ selectedDocument.original_name }}
                    </h3>

                    <div
                        v-if="selectedDocument.status !== 'processed'"
                        class="mt-4 rounded-lg bg-slate-800 p-4 text-sm text-slate-300"
                    >
                        O documento ainda não possui conteúdo extraído.
                        Status: {{ statusLabel(selectedDocument.status) }}.
                    </div>

                    <pre
                        v-else
                        class="mt-4 max-h-[500px] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-5 text-sm leading-relaxed text-slate-300"
                    >{{ selectedDocument.extracted_text || 'Nenhum texto foi extraído.' }}</pre>
                </template>
            </section>

            <p class="text-xs text-slate-500">
                Formatos aceitos: PDF, DOCX, PNG e JPG. Tamanho máximo: 20 MB por arquivo.
            </p>
        </div>
    </AppLayout>
</template>
