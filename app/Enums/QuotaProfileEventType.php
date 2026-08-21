<?php

namespace App\Enums;

/** Les gestes humains que le registre des profils de quota conserve. */
enum QuotaProfileEventType: string
{
    case DEFINED = 'defined';
    case RENAMED = 'renamed';
    case VALUE_CHANGED = 'value_changed';
    case BOUNDS_CHANGED = 'bounds_changed';
    case AVAILABILITY_CHANGED = 'availability_changed';
}
