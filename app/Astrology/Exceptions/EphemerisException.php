<?php

namespace App\Astrology\Exceptions;

use RuntimeException;

/**
 * The engine could not produce a result. The message is for the log only; users
 * see a generic notice (docs/spec/06: raw engine errors are never shown).
 */
class EphemerisException extends RuntimeException {}
