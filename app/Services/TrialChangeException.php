<?php

namespace App\Services;

use RuntimeException;

/**
 * A trial change that was refused or could not be saved. The message is safe
 * to show a Super Admin: it never carries a database or driver message.
 */
class TrialChangeException extends RuntimeException {}
