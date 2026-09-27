<?php

namespace App\Domain\Organization\Enums;

/**
 * PERMISSION catalog (A5 §2.4): the right to one kind of operation. Kept in code because each value is
 * checked by code; roles combine them as data. Names describe operations, never an industry. Later stages
 * add their own permissions here (events E4, registrations E5, scanning E6, finance E8, …).
 */
enum Permission: string
{
    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case StructureManage = 'structure.manage';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case RolesManage = 'roles.manage';
    case RolesAssign = 'roles.assign';
    case PersonLinksResolve = 'person_links.resolve';
    case RepresentationsEstablish = 'representations.establish';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationView => 'podgląd organizacji',
            self::OrganizationManage => 'zarządzanie organizacją (nazwa, archiwizacja)',
            self::StructureManage => 'zarządzanie strukturą jednostek',
            self::MembersView => 'podgląd członków',
            self::MembersManage => 'zarządzanie członkostwem',
            self::RolesManage => 'definiowanie ról',
            self::RolesAssign => 'nadawanie ról',
            self::PersonLinksResolve => 'rozstrzyganie połączeń kont z osobami',
            self::RepresentationsEstablish => 'ustanawianie reprezentacji',
            self::AuditView => 'podgląd audytu',
        };
    }
}
