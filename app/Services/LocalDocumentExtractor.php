<?php

namespace App\Services;

use App\Models\FileObject;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class LocalDocumentExtractor
{
    /** @return array{poppler: bool, pdf_images: bool, tesseract: bool, ocr_languages: array{ara: bool, eng: bool}} */
    public function dependencyStatus(): array
    {
        return [
            'poppler' => $this->commandAvailable((string) config('local-ai.commands.pdftotext'), ['-v']),
            'pdf_images' => $this->commandAvailable((string) config('local-ai.commands.pdftoppm'), ['-v']),
            'tesseract' => $this->commandAvailable((string) config('local-ai.commands.tesseract'), ['--version']),
            'ocr_languages' => $this->ocrLanguages(),
        ];
    }

    /** @return array{segments:list<array{locator_type:string,locator:string,text:string}>,page_count:int,used_ocr:bool} */
    public function extract(FileObject $file, int $maxPages): array
    {
        if ($file->size_bytes > (int) config('local-ai.max_file_bytes')) {
            throw ValidationException::withMessages(['file' => 'يتجاوز الملف حد التحليل المحلي البالغ 25MB.']);
        }
        if (! in_array($file->extension, ['pdf', 'docx'], true)) {
            throw ValidationException::withMessages(['file' => 'التحليل المحلي يدعم PDF وDOCX فقط.']);
        }
        if (! in_array($file->scan_status, ['safe', 'structurally_safe'], true)) {
            throw ValidationException::withMessages(['file' => 'لم يجتز الملف فحص الأمان بعد.']);
        }
        $disk = Storage::disk($file->disk);
        $path = $disk->path($file->storage_key);
        if (! is_file($path)) {
            throw new RuntimeException('The source document is missing.');
        }

        return $file->extension === 'pdf' ? $this->extractPdf($path, $maxPages) : $this->extractDocx($path, $maxPages);
    }

    /** @return array{segments:list<array{locator_type:string,locator:string,text:string}>,page_count:int,used_ocr:bool} */
    private function extractPdf(string $path, int $maxPages): array
    {
        $pdfInfo = Process::timeout(30)->run([(string) config('local-ai.commands.pdfinfo'), $path]);
        if (! $pdfInfo->successful()) {
            throw ValidationException::withMessages(['file' => 'ملف PDF تالف أو مشفر ولا يمكن تحليله.']);
        }
        preg_match('/^Pages:\s+(\d+)/mi', $pdfInfo->output(), $match);
        $pageCount = isset($match[1]) ? (int) $match[1] : 0;
        if ($pageCount < 1 || $pageCount > $maxPages) {
            throw ValidationException::withMessages(['file' => "عدد صفحات PDF غير صالح أو يتجاوز الحد ({$maxPages})."]);
        }
        $textResult = Process::timeout(180)->run([
            (string) config('local-ai.commands.pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-',
        ]);
        $pages = $textResult->successful() ? explode("\f", $textResult->output()) : [];
        $segments = [];
        $usedOcr = false;
        for ($page = 1; $page <= $pageCount; $page++) {
            $text = trim((string) ($pages[$page - 1] ?? ''));
            if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 40) {
                $text = $this->ocrPdfPage($path, $page);
                $usedOcr = true;
            }
            if ($text !== '') {
                $segments[] = ['locator_type' => 'page', 'locator' => (string) $page, 'text' => $this->normalize($text)];
            }
        }
        if ($segments === []) {
            throw new RuntimeException('No readable text was extracted from the PDF.');
        }

        return ['segments' => $segments, 'page_count' => $pageCount, 'used_ocr' => $usedOcr];
    }

    /** @return array{segments:list<array{locator_type:string,locator:string,text:string}>,page_count:int,used_ocr:bool} */
    private function extractDocx(string $path, int $maxParagraphs): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Invalid DOCX package.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            if (! is_string($xml)) {
                throw new RuntimeException('DOCX document.xml is missing.');
            }
            $document = new \DOMDocument;
            $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $segments = [];
            $section = 'Document';
            $paragraph = 0;
            // Descendant paragraphs include normal body text and paragraphs
            // nested in table cells while preserving document order.
            $paragraphNodes = $xpath->query('//w:body//w:p');
            if ($paragraphNodes === false) {
                throw new RuntimeException('Unable to parse DOCX paragraphs.');
            }
            foreach ($paragraphNodes as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                $paragraph++;
                if ($paragraph > $maxParagraphs * 20) {
                    throw ValidationException::withMessages(['file' => 'مستند DOCX أكبر من حد التحليل.']);
                }
                $parts = [];
                $textNodes = $xpath->query('.//w:t', $node);
                if ($textNodes === false) {
                    continue;
                }
                foreach ($textNodes as $textNode) {
                    $parts[] = (string) $textNode->nodeValue;
                }
                $text = trim(implode('', $parts));
                if ($text === '') {
                    continue;
                }
                $styleNodes = $xpath->query('.//w:pPr/w:pStyle/@w:val', $node);
                $style = $styleNodes === false ? null : $styleNodes->item(0)?->nodeValue;
                if (is_string($style) && preg_match('/heading|title/i', $style) === 1) {
                    $section = $text;
                }
                $segments[] = [
                    'locator_type' => 'paragraph',
                    'locator' => mb_substr($section, 0, 180).' ¶'.$paragraph,
                    'text' => $this->normalize($text),
                ];
            }
            $usedOcr = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || preg_match('#^word/media/.*\.(png|jpe?g|tiff?|bmp)$#i', $name) !== 1) {
                    continue;
                }
                $binary = $zip->getFromIndex($index);
                if (! is_string($binary)) {
                    continue;
                }
                $text = $this->ocrBinaryImage($binary, pathinfo($name, PATHINFO_EXTENSION));
                if ($text !== '') {
                    $segments[] = ['locator_type' => 'image', 'locator' => $name, 'text' => $this->normalize($text)];
                    $usedOcr = true;
                }
            }
            if ($segments === []) {
                throw new RuntimeException('No readable text was extracted from the DOCX file.');
            }

            return ['segments' => $segments, 'page_count' => 0, 'used_ocr' => $usedOcr];
        } finally {
            $zip->close();
        }
    }

    private function ocrPdfPage(string $path, int $page): string
    {
        $directory = storage_path('app/private/analysis-temp/'.Str::uuid());
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create OCR workspace.');
        }
        $prefix = $directory.DIRECTORY_SEPARATOR.'page';
        try {
            $image = Process::timeout(180)->run([
                (string) config('local-ai.commands.pdftoppm'), '-f', (string) $page, '-l', (string) $page,
                '-singlefile', '-r', '220', '-png', $path, $prefix,
            ]);
            if (! $image->successful() || ! is_file($prefix.'.png')) {
                throw new RuntimeException("OCR rendering failed for page {$page}.");
            }
            $ocr = Process::timeout(180)->run([
                (string) config('local-ai.commands.tesseract'), $prefix.'.png', 'stdout', '-l', 'ara+eng', '--psm', '6',
            ]);
            if (! $ocr->successful()) {
                throw new RuntimeException("OCR failed for page {$page}.");
            }

            return trim($ocr->output());
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function ocrBinaryImage(string $binary, string $extension): string
    {
        $directory = storage_path('app/private/analysis-temp/'.Str::uuid());
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return '';
        }
        $path = $directory.DIRECTORY_SEPARATOR.'image.'.preg_replace('/[^a-z0-9]/i', '', $extension);
        try {
            file_put_contents($path, $binary, LOCK_EX);
            $ocr = Process::timeout(120)->run([(string) config('local-ai.commands.tesseract'), $path, 'stdout', '-l', 'ara+eng', '--psm', '6']);

            return $ocr->successful() ? trim($ocr->output()) : '';
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    /** @param list<string> $arguments */
    private function commandAvailable(string $command, array $arguments): bool
    {
        try {
            return Process::timeout(10)->run([$command, ...$arguments])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ara: bool, eng: bool} */
    private function ocrLanguages(): array
    {
        try {
            $result = Process::timeout(10)->run([(string) config('local-ai.commands.tesseract'), '--list-langs']);
            if (! $result->successful()) {
                return ['ara' => false, 'eng' => false];
            }
            $languages = preg_split('/\R/', $result->output()) ?: [];

            return ['ara' => in_array('ara', $languages, true), 'eng' => in_array('eng', $languages, true)];
        } catch (\Throwable) {
            return ['ara' => false, 'eng' => false];
        }
    }

    private function normalize(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text);
    }
}
