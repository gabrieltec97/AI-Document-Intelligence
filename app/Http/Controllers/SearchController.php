<?php

namespace App\Http\Controllers;

use App\Services\Search\SemanticSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(
        Request $request,
        SemanticSearchService $searchService
    ): JsonResponse {
        $validated = $request->validate([
            'query' => [
                'required',
                'string',
                'min:3',
                'max:2000',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:10',
            ],
        ]);

        $results = $searchService->search(
            $request->user(),
            $validated['query'],
            $validated['limit'] ?? 5
        );

        return response()->json([
            'query' => $validated['query'],
            'total' => $results->count(),
            'results' => $results->map(function ($chunk) {
                return [
                    'document_id' => $chunk->document_id,
                    'document_name' => $chunk->document->original_name,
                    'chunk_id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                    'content' => $chunk->content,
                    'similarity' => $chunk->similarity,
                ];
            }),
        ]);
    }
}
