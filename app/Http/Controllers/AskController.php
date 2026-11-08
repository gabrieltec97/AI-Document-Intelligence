<?php

namespace App\Http\Controllers;

use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AskController extends Controller
{
    public function __invoke(
        Request $request,
        RagService $ragService
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

        $result = $ragService->ask(
            $request->user(),
            $validated['query'],
            $validated['limit'] ?? 5
        );

        return response()->json([
            'query' => $validated['query'],
            'answer' => $result['answer'],
            'total_sources' => count($result['sources']),
            'sources' => $result['sources'],
            'citation_validation' => $result['citation_validation'],
        ]);
    }
}
