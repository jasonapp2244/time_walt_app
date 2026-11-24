<?php

namespace App\Exceptions;

/**
 * Stripe gave no clear answer (network error, a 5xx, or an error on the retry
 * of either), so the call may or may not have happened. The money must stay
 * where it is until Stripe's own record settles it.
 */
class StripeOutcomeUnknownException extends \RuntimeException {}
