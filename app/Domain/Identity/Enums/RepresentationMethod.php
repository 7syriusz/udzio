<?php

namespace App\Domain\Identity\Enums;

/**
 * How a representation was established (Z-025). A configuration enables some of them
 * (`identity.representation.methods`); the confirmation itself is referenced by `basis`.
 */
enum RepresentationMethod: string
{
    /** Both persons accepted the representation. */
    case PartiesAcceptance = 'parties_acceptance';
    /** A statement made by an identified person, accepted by the configuration. */
    case Declaration = 'declaration';
    /** A decision of an authorized role (operator, administrator). */
    case RoleDecision = 'role_decision';
    /** A document (court decision, power of attorney, birth certificate) was checked. */
    case Document = 'document';
    /** An additional verification procedure was completed. */
    case AdditionalVerification = 'additional_verification';

    /** Name shown in the interface (lang/<locale>/identity.php). */
    public function label(): string
    {
        return __('identity.representation_methods.'.$this->value);
    }
}
