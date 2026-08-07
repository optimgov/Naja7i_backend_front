<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'une opération sur une table isolée est tentée sans tenant
 * résolu. Ne jamais rattraper cette exception pour « continuer quand même » :
 * elle signale un défaut de câblage, pas un cas métier.
 */
class NoTenantResolvedException extends RuntimeException {}
