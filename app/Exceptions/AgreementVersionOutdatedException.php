<?php

namespace App\Exceptions;

use DomainException;

/**
 * A signature was refused because the agreement's wording changed after the
 * signer opened it. The portal reloads the agreement so they can review the
 * current wording before signing.
 */
class AgreementVersionOutdatedException extends DomainException
{
}
