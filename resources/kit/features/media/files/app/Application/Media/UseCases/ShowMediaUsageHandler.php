<?php

namespace App\Application\Media\UseCases;

use App\Application\Media\MediaQuota;
use App\Application\Media\MediaQuotaUsage;

/**
 * What the image library uses and what its quota lets it use, for the library page.
 */
final class ShowMediaUsageHandler
{
    public function __construct(
        protected MediaQuota $quota,
    ) {}

    public function __invoke(): MediaQuotaUsage
    {
        return $this->quota->usage();
    }
}
