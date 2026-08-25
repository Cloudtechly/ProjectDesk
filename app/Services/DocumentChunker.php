<?php

namespace App\Services;

class DocumentChunker
{
    /**
     * @param  list<array{locator_type: string, locator: string, text: string}>  $segments
     * @return list<array{segments: list<array{locator_type: string, locator: string, text: string}>, text: string}>
     */
    public function chunk(array $segments): array
    {
        $max = (int) config('local-ai.chunk_max_chars', 18000);
        $overlapLength = min((int) config('local-ai.chunk_overlap_chars', 1000), (int) floor($max / 4));
        $chunks = [];
        $current = [];
        $length = 0;
        foreach ($this->splitOversizedSegments($segments, max(1000, $max - $overlapLength)) as $segment) {
            $segmentLength = mb_strlen($segment['text']);
            if ($current !== [] && $length + $segmentLength > $max) {
                $chunks[] = ['segments' => $current, 'text' => $this->render($current)];
                $last = end($current);
                $overlapText = mb_substr($last['text'], -$overlapLength);
                $current = $overlapText === '' ? [] : [[...$last, 'text' => $overlapText]];
                $length = mb_strlen($overlapText);
            }
            $current[] = $segment;
            $length += $segmentLength;
        }
        if ($current !== []) {
            $chunks[] = ['segments' => $current, 'text' => $this->render($current)];
        }

        return $chunks;
    }

    /**
     * @param  list<array{locator_type: string, locator: string, text: string}>  $segments
     * @return list<array{locator_type: string, locator: string, text: string}>
     */
    private function splitOversizedSegments(array $segments, int $limit): array
    {
        $pieces = [];
        foreach ($segments as $segment) {
            $text = $segment['text'];
            if (mb_strlen($text) <= $limit) {
                $pieces[] = $segment;

                continue;
            }
            for ($offset = 0, $length = mb_strlen($text); $offset < $length; $offset += $limit) {
                $pieces[] = [...$segment, 'text' => mb_substr($text, $offset, $limit)];
            }
        }

        return $pieces;
    }

    /** @param list<array{locator_type: string, locator: string, text: string}> $segments */
    private function render(array $segments): string
    {
        return implode("\n\n", array_map(
            fn (array $segment): string => '[SOURCE '.$segment['locator_type'].':'.$segment['locator']."]\n".$segment['text'],
            $segments,
        ));
    }
}
