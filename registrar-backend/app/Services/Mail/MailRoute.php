<?php

namespace App\Services\Mail;

/**
 * Where one lifecycle email may go, decided by RepresentativeMailRouter.
 */
final class MailRoute
{
    /** Normal case: the requestor's own address. */
    public const REQUESTOR = 'requestor';

    /** §3.6 closure notice to a verified representative. */
    public const REPRESENTATIVE = 'representative';

    /** Requestor is deceased/incapacitated and this email has no other legitimate recipient. */
    public const SUPPRESSED = 'suppressed';

    /** §3.6 closure notice with no verified representative: audit "sent-on-file", in-app is the record. */
    public const SENT_ON_FILE = 'sent_on_file';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $address = null,
        public readonly ?string $studentName = null,
    ) {}

    public static function requestor(): self
    {
        return new self(self::REQUESTOR);
    }

    public static function representative(string $address, string $studentName): self
    {
        return new self(self::REPRESENTATIVE, $address, $studentName);
    }

    public static function suppressed(): self
    {
        return new self(self::SUPPRESSED);
    }

    public static function sentOnFile(): self
    {
        return new self(self::SENT_ON_FILE);
    }
}