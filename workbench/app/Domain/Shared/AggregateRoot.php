<?php

namespace App\Domain\Shared;

/**
 * The one entity of an aggregate that the outside world may hold a reference to.
 *
 * Everything else inside the boundary — child entities, value objects — is reachable
 * only through the root, so only a root gets a repository. Extending this rather than
 * DomainEntity is what says a class is that root; the workflow kit's
 * tests/Architecture/LayersTest.php enforces it.
 */
abstract class AggregateRoot extends DomainEntity {}
