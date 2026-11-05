<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * App analytics (Microsoft Clarity + Firebase/GA4). The routes sit inside the
 * `admin` middleware group in routes/web.php; nothing here checks roles.
 */
class AnalyticsDashboardController extends Controller
{
    public const RANGES = [7, 30, 90];

    public function index(Request $request, AnalyticsDashboard $dashboard): View
    {
        return view('admin.analytics.index', [
            'data' => $dashboard->build($this->days($request)),
            'ranges' => self::RANGES,
        ]);
    }

    /** Same data as JSON, for an export or a future SPA. */
    public function json(Request $request, AnalyticsDashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->build($this->days($request)));
    }

    private function days(Request $request): int
    {
        $days = (int) $request->query('days', 7);

        return in_array($days, self::RANGES, true) ? $days : 7;
    }
}
