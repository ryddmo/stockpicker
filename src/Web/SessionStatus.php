<?php

declare(strict_types=1);

namespace Stockpicker\Web;

/**
 * Outcome of verifying the signed session cookie. See
 * AuthController::sessionStatus() for how each case is reached.
 */
enum SessionStatus
{
    /** Signature checks out and exp is in the future. */
    case Valid;

    /** No cookie was sent at all. */
    case Missing;

    /** A cookie was sent but its signature does not verify. */
    case Invalid;

    /** Signature checks out but exp is in the past. */
    case Expired;
}
