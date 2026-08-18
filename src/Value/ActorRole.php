<?php

declare(strict_types=1);

namespace App\Value;

final class ActorRole
{
    public const OWNER_LENDER = 'owner_lender';
    public const BORROWER = 'borrower';
    public const CONSERVATOR = 'conservator';
    public const COURIER = 'courier';
    public const ART_HANDLER = 'art_handler';
    public const INSURER = 'insurer';
    public const OTHER = 'other';

    private const ROLES = [
        self::OWNER_LENDER,
        self::BORROWER,
        self::CONSERVATOR,
        self::COURIER,
        self::ART_HANDLER,
        self::INSURER,
        self::OTHER,
    ];

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'actor_role.owner_lender' => self::OWNER_LENDER,
            'actor_role.borrower' => self::BORROWER,
            'actor_role.conservator' => self::CONSERVATOR,
            'actor_role.courier' => self::COURIER,
            'actor_role.art_handler' => self::ART_HANDLER,
            'actor_role.insurer' => self::INSURER,
            'actor_role.other' => self::OTHER,
        ];
    }

    public static function normalize(?string $role): ?string
    {
        $role = $role === null ? null : trim($role);

        if ($role === null || $role === '') {
            return null;
        }

        return in_array($role, self::ROLES, true) ? $role : null;
    }
}
