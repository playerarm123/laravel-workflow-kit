<?php

namespace App\Application\Media;

/**
 * Which images of the library a list keeps, by whether anything uses them.
 */
enum MediaUsageFilter: string
{
    case InUse = 'in_use';
    case Unused = 'unused';
}
