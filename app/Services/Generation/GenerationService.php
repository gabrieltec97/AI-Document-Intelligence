<?php

namespace App\Services\Generation;

interface GenerationService
{
    public function generate(string $prompt): string;
}
