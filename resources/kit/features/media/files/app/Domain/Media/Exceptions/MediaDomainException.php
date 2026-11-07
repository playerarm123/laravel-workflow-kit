<?php

namespace App\Domain\Media\Exceptions;

use App\Domain\Shared\DomainException;

/**
 * The base of every refusal in this context. It is abstract, so a controller catches the
 * concrete reason by name and never this base (exceptions.md).
 */
abstract class MediaDomainException extends DomainException {}
