<?php

namespace App\Services\Analytics;

use RuntimeException;

/** Clarity's 10-calls-per-day limit (or our lower budget) was hit. */
class ClarityQuotaExceeded extends RuntimeException {}
