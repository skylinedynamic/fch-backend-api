<?php

namespace App\Exceptions;

use DomainException;

/**
 * A department delete was refused because employees are still in it (BUG-183).
 */
class DepartmentInUseException extends DomainException {}
