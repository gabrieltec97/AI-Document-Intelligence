<?php

namespace App\Services\Search;

use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Collection;

class SemanticSearchService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private CosineSimilarity $cosineSimilarity
    ) {
    }

    /**
     * Busca os chunks mais relevantes para uma pergunta.
     */
    public function search(
        User $user,
        string $query,
        int $limit = 5
    ): Collection {
        $chunks = DocumentChunk::query()
            ->with('document:id,original_name')
            ->whereHas('document', function ($documentQuery) use ($user) {
                $documentQuery
                    ->where('user_id', $user->id)
                    ->where('status', 'processed');
            })
            ->whereNotNull('embedding')
            ->whereNotNull('embedded_at')
            ->get();

        // Evita chamar o Ollama quando não existem chunks disponíveis.
        if ($chunks->isEmpty()) {
            return collect();
        }

        // Identifica empresas mencionadas explicitamente na pergunta.
        $matchingCompanies = $chunks
            ->pluck('company')
            ->filter()
            ->unique()
            ->filter(
                fn (string $company) => mb_stripos($query, $company) !== false
            )
            ->values();

        // Quando uma empresa é identificada, restringe a busca
        // às experiências profissionais correspondentes.
        if ($matchingCompanies->isNotEmpty()) {
            $chunks = $chunks
                ->filter(
                    fn (DocumentChunk $chunk) => $matchingCompanies->contains(
                        $chunk->company
                    )
                )
                ->values();
        }

        // Gera o embedding da pergunta.
        $queryEmbedding = $this->embeddingService->embed([$query])[0];

        // Calcula a similaridade e retorna os chunks mais relevantes.
        return $chunks
            ->map(function (DocumentChunk $chunk) use ($queryEmbedding) {
                $chunk->similarity = $this->cosineSimilarity->calculate(
                    $queryEmbedding,
                    $chunk->embedding
                );

                return $chunk;
            })
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }
}
