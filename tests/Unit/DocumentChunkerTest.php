<?php

namespace Tests\Unit;

use App\Services\DocumentChunker;
use Tests\TestCase;

class DocumentChunkerTest extends TestCase
{
    public function test_oversized_pages_are_split_and_every_chunk_stays_within_the_configured_payload_limit(): void
    {
        config()->set('local-ai.chunk_max_chars', 5000);
        config()->set('local-ai.chunk_overlap_chars', 500);

        $chunks = app(DocumentChunker::class)->chunk([
            ['locator_type' => 'page', 'locator' => '1', 'text' => str_repeat('أ', 12000)],
            ['locator_type' => 'page', 'locator' => '2', 'text' => str_repeat('ب', 3500)],
        ]);

        $this->assertGreaterThan(2, count($chunks));
        foreach ($chunks as $chunk) {
            $payloadLength = array_sum(array_map(fn (array $segment): int => mb_strlen($segment['text']), $chunk['segments']));
            $this->assertLessThanOrEqual(5000, $payloadLength);
            $this->assertStringContainsString('[SOURCE ', $chunk['text']);
        }
    }
}
