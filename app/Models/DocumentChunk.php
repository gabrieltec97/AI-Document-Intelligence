<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'character_count',
        'embedding',
        'embedding_model',
        'embedded_at',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'character_count' => 'integer',
        'embedding' => 'array',
        'embedded_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
