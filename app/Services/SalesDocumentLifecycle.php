<?php

namespace App\Services;

use App\Models\SalesDocument;
use Carbon\CarbonInterface;

class SalesDocumentLifecycle
{
    /** @return list<string> */
    public function statusesFor(string $type): array
    {
        return $type === SalesDocument::TEMPLATE_TYPE
            ? SalesDocument::TEMPLATE_STATUSES
            : [];
    }

    public function canTransition(string $type, string $from, string $to): bool
    {
        if ($type !== SalesDocument::TEMPLATE_TYPE) {
            return false;
        }

        return match ($from) {
            'draft' => in_array($to, ['draft', 'archived'], true),
            'archived' => in_array($to, ['archived', 'draft'], true),
            default => false,
        };
    }

    public function canInitialize(string $type, string $status): bool
    {
        return $type === SalesDocument::TEMPLATE_TYPE && $status === 'draft';
    }

    public function displayStatus(SalesDocument $document, CarbonInterface $today): string
    {
        return $document->status;
    }
}
