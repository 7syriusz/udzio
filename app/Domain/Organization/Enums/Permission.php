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
    /** Former members and ended membership periods (E3.7b; plain members.view shows current members only). */
    case MembersHistoryView = 'members.history.view';
    /** History of archived units that belonged to the scope (E3.7b). */
    case StructureHistoryView = 'structure.history.view';
    /** Control of roles: every role assignment in the scope, also outside one's role-granting catalog (E3.7b). */
    case RolesAuditView = 'roles.audit.view';
    /** Full contact data (e.g. unmasked e-mail) of people in the scope (E3.7b). */
    case PeopleContactsView = 'people.contacts.view';
    /** Specially protected data (SPECIAL CATEGORY) of people in the scope; every read is audited (E3.7b). */
    case PeopleProtectedView = 'people.protected.view';
    /** Exports and bulk reads of data of the scope; every one is audited (E3.7b). */
    case DataExport = 'data.export';

    /** Name shown in the interface (lang/<locale>/permissions.php). */
    public function label(): string
    {
        return __('permissions.'.$this->value);
    }
}
