<?php

namespace App\DataTransferObjects\CorrectiveActions;

final class VerifyCorrectiveActionData
{
    public function __construct(
        public readonly string $verificationComments,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(verificationComments: $data['verification_comments']);
    }
}
