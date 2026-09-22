<?php

namespace App\Services\Ocr;

use RuntimeException;
use Symfony\Component\Process\Process;

class TesseractOcrService implements OcrService
{
    public function extractText(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(
                "Arquivo não encontrado: {$filePath}"
            );
        }

        $outputBase = tempnam(sys_get_temp_dir(), 'ocr_');

        if ($outputBase === false) {
            throw new RuntimeException(
                'Não foi possível criar arquivo temporário para OCR.'
            );
        }

        unlink($outputBase);

        $process = new Process([
            'tesseract',
            $filePath,
            $outputBase,
            '-l',
            'por+eng',
        ]);

        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Erro ao executar Tesseract: ' . $process->getErrorOutput()
            );
        }

        $textFile = $outputBase . '.txt';

        if (!is_file($textFile)) {
            throw new RuntimeException(
                'O Tesseract não gerou o arquivo de texto.'
            );
        }

        $text = file_get_contents($textFile);

        @unlink($textFile);

        if ($text === false) {
            throw new RuntimeException(
                'Não foi possível ler o texto extraído pelo Tesseract.'
            );
        }

        return trim($text);
    }
}
