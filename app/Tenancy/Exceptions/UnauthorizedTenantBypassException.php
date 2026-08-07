<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'on tente de retirer le scope tenant sans passer par le service
 * TenantBypass — donc sans raison structurée ni journalisation corrélable.
 */
class UnauthorizedTenantBypassException extends RuntimeException {}
