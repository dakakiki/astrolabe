<?php

namespace App\Enums;

/**
 * A service's colour in the calendar. Named colours rather than free hex
 * values: each maps to a design token (`--svc-*` in resources/css/tokens.css),
 * so it stays legible in both the night and the day theme.
 */
enum ServiceColor: string
{
    case Indigo = 'indigo';
    case Sky = 'sky';
    case Teal = 'teal';
    case Green = 'green';
    case Amber = 'amber';
    case Coral = 'coral';
    case Rose = 'rose';
    case Violet = 'violet';
}
