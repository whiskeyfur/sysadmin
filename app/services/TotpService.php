<?php

namespace App\Services;

use App\Utils\SystemClock;
use chillerlan\QRCode\QRCode;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * Time-based one-time codes for authenticator apps such as Google
 * Authenticator. Uses the defaults every app supports (SHA-1, 6 digits,
 * 30-second period); Google Authenticator ignores other settings.
 *
 * The secret is stored inside the user's encrypted user-data field, so it
 * can only be read after the correct password.
 */
class TotpService
{
    public const ISSUER = 'sys';

    private const SECRET_BYTES = 20;

    /**
     * Accept codes from one period either side of now, for clock drift.
     */
    private const WINDOW = 1;

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function generateSecret(): string
    {
        return TOTP::generate($this->clock, self::SECRET_BYTES)->getSecret();
    }

    /**
     * Secrets round-trip through the browser in a hidden field during
     * enrolment, so check the shape before using one.
     */
    public function isValidSecret(string $secret): bool
    {
        return preg_match('/^[A-Z2-7]{32}$/', $secret) === 1;
    }

    public function provisioningUri(string $secret, string $username): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($username);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /**
     * An SVG data URI for an <img> tag. Rendered on the server so the
     * secret is never sent to a third-party QR service.
     */
    public function qrCode(string $provisioningUri): string
    {
        return (new QRCode())->render($provisioningUri);
    }

    /**
     * Check a code and return the time step it matched, or null. Steps at
     * or before $lastStep are rejected so a code can't be replayed.
     */
    public function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $totp = $this->totp($secret);
        $period = $totp->getPeriod();
        $currentStep = intdiv($this->clock->now()->getTimestamp(), $period);

        for ($step = $currentStep - self::WINDOW; $step <= $currentStep + self::WINDOW; $step++) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }

            if (hash_equals($totp->at($step * $period), $code)) {
                return $step;
            }
        }

        return null;
    }

    private function totp(string $secret): TOTP
    {
        return TOTP::createFromSecret($secret, $this->clock);
    }
}
