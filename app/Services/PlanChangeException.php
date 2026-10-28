<?php

namespace App\Services;

use RuntimeException;

/**
 * A plan change that was refused for a business reason (not a field
 * validation error). The message is already translated and safe to show to
 * the Super Admin who tried it.
 */
class PlanChangeException extends RuntimeException {}
