<?php

namespace App\Domain\Shared\Enums;

/**
 * A day of the week, valued as ISO numbers it: 1 is Monday, 7 is Sunday (dates.md). The value is
 * what an integer column stores and what `weekdayName()` on the page names, so a weekly rule
 * (opening days, a delivery day) keeps one numbering from the database to the screen.
 *
 * Which days are the weekend, or which close the shop, is the project's rule, not this enum's.
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;
}
