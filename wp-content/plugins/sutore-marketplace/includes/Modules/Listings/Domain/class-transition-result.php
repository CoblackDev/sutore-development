<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Result of an atomic listing transition (STATE-01).
 * Only the "changed" outcome may emit side effects.
 */
final class TransitionResult
{
    public const CHANGED = 'changed';
    public const ALREADY_DONE = 'already_done';
    public const CONFLICT = 'conflict';

    private function __construct(
        private readonly string $outcome,
        private readonly string $operationId = '',
    ) {
    }

    public static function changed(string $operationId = ''): self
    {
        return new self(self::CHANGED, $operationId);
    }

    public static function alreadyDone(string $operationId = ''): self
    {
        return new self(self::ALREADY_DONE, $operationId);
    }

    public static function conflict(string $operationId = ''): self
    {
        return new self(self::CONFLICT, $operationId);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function isChanged(): bool
    {
        return $this->outcome === self::CHANGED;
    }

    public function isAlreadyDone(): bool
    {
        return $this->outcome === self::ALREADY_DONE;
    }

    public function isConflict(): bool
    {
        return $this->outcome === self::CONFLICT;
    }

    public function ok(): bool
    {
        return $this->isChanged() || $this->isAlreadyDone();
    }
}
