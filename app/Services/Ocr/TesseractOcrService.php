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

        $mimeType = mime_content_type($filePath);

        return match ($mimeType) {
            'application/pdf' => $this->extractFromPdf($filePath),
            'image/jpeg',
            'image/png' => $this->extractFromImage($filePath),
            default => throw new RuntimeException(
                "Tipo de arquivo não suportado pelo OCR: {$mimeType}"
            ),
        };
    }

    private function extractFromPdf(string $filePath): string
    {
        $tempDirectory = sys_get_temp_dir() . '/ocr_' . uniqid();

        if (!mkdir($tempDirectory, 0755, true) && !is_dir($tempDirectory)) {
            throw new RuntimeException(
                'Não foi possível criar o diretório temporário do OCR.'
            );
        }

        try {
            $outputPrefix = $tempDirectory . '/page';

            $pdfProcess = new Process([
                'pdftoppm',
                '-png',
                '-r',
                '150',
                $filePath,
                $outputPrefix,
            ]);

            $pdfProcess->setTimeout(120);
            $pdfProcess->run();

            if (!$pdfProcess->isSuccessful()) {
                throw new RuntimeException(
                    'Erro ao converter PDF para imagem: ' .
                    $pdfProcess->getErrorOutput()
                );
            }

            $images = glob($tempDirectory . '/page-*.png');

            if (empty($images)) {
                throw new RuntimeException(
                    'Nenhuma página foi gerada a partir do PDF.'
                );
            }

            natsort($images);

            $fullText = [];

            foreach ($images as $image) {
                $text = $this->extractFromImage($image);

                if ($text !== '') {
                    $fullText[] = $text;
                }

                @unlink($image);
            }

            return trim(implode("\n\n", $fullText));
        } finally {
            @rmdir($tempDirectory);
        }
    }

    private function extractFromImage(string $filePath): string
    {
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
                'Erro ao executar Tesseract: ' .
                $process->getErrorOutput()
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
