<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

final class DiagnosticResult
{
    /**
     * @param array<string, scalar|null> $details jamais de secret
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly DiagnosticStatus $status,
        public readonly string $messageKey,
        public readonly array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'status' => $this->status->value,
            'message_key' => $this->messageKey,
            'details' => $this->details,
        ];
    }
}
