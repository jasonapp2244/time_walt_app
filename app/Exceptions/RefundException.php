<?php

namespace App\Exceptions;

/**
 * A refund that was refused or failed. The message is written for the admin
 * and is safe to show on the page.
 */
class RefundException extends \RuntimeException {}
