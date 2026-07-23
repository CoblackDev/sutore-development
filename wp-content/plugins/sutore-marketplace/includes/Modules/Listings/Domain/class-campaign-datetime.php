<?php

declare(strict_types=1);

namespace SutoreMarketplace\Modules\Listings\Domain;

/**
 * Campaign datetimes are stored as WordPress site-local MySQL strings
 * (`current_time( 'mysql' )` convention). Never treat them as UTC / GMT.
 */
final class CampaignDatetime
{
    /**
     * Normalize datetime-local / MySQL input to `Y-m-d H:i:s` in site timezone.
     */
    public static function normalizeInput(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        $dt = self::parseLocal($value);
        if (!$dt instanceof \DateTimeImmutable) {
            return null;
        }

        return $dt->format('Y-m-d H:i:s');
    }

    public static function parseLocal(?string $mysql): ?\DateTimeImmutable
    {
        if ($mysql === null) {
            return null;
        }
        $mysql = trim($mysql);
        if ($mysql === '' || str_starts_with($mysql, '0000-00-00')) {
            return null;
        }

        try {
            $dt = date_create_immutable($mysql, wp_timezone());
            return $dt instanceof \DateTimeImmutable ? $dt : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatLabel(?string $mysql): string
    {
        $dt = self::parseLocal($mysql);
        if (!$dt instanceof \DateTimeImmutable) {
            return $mysql !== null ? (string) $mysql : '';
        }

        return (string) wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $dt->getTimestamp()
        );
    }

    public static function toTimestamp(?string $mysql): ?int
    {
        $dt = self::parseLocal($mysql);

        return $dt instanceof \DateTimeImmutable ? $dt->getTimestamp() : null;
    }

    public static function isPast(?string $mysql): bool
    {
        $ts = self::toTimestamp($mysql);
        if ($ts === null) {
            return false;
        }

        return $ts <= time();
    }

    public static function nowMysql(): string
    {
        return current_time('mysql');
    }
}
