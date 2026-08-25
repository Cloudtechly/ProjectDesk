<?php

namespace Tests\Feature;

use App\Models\FileObject;
use App\Services\LocalDocumentExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;
use ZipArchive;

class LocalDocumentExtractorFeatureTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_docx_extraction_preserves_headings_paragraphs_and_table_text(): void
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('book.docx');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>المتطلبات التقنية</w:t></w:r></w:p>
    <w:p><w:r><w:t>يجب أن يدعم النظام اللغة العربية.</w:t></w:r></w:p>
    <w:tbl><w:tr><w:tc><w:p><w:r><w:t>متطلب داخل الجدول</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
  </w:body>
</w:document>
XML);
        $zip->close();
        $user = $this->makeUser('admin');
        $file = FileObject::query()->create([
            'disk' => 'local', 'storage_key' => 'book.docx', 'original_name' => 'book.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx', 'size_bytes' => filesize($path), 'checksum_sha256' => hash_file('sha256', $path),
            'scan_status' => 'structurally_safe', 'uploaded_by' => $user->id, 'uploaded_at' => now(),
        ]);

        $document = app(LocalDocumentExtractor::class)->extract($file, 300);

        $this->assertCount(3, $document['segments']);
        $this->assertSame('المتطلبات التقنية ¶2', $document['segments'][1]['locator']);
        $this->assertSame('متطلب داخل الجدول', $document['segments'][2]['text']);
        $this->assertFalse($document['used_ocr']);
    }
}
