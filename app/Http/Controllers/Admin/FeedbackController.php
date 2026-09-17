<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    /**
     * List all user feedback with optional rating filter.
     */
    public function index(Request $request): View
    {
        $query = Feedback::with('user');

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }

        $feedbacks = $query->latest()->paginate(15)->withQueryString();

        // One grouped aggregate covers the per-star counts, the total and the
        // average, since rating is never null and is always 1-5.
        $counts = Feedback::selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->mapWithKeys(fn ($total, $rating) => [(int) $rating => (int) $total]);

        $ratingCounts = [];
        for ($star = 5; $star >= 1; $star--) {
            $ratingCounts[$star] = $counts->get($star, 0);
        }

        $totalCount = array_sum($ratingCounts);

        $weighted = 0;
        foreach ($ratingCounts as $star => $count) {
            $weighted += $star * $count;
        }

        $averageRating = $totalCount > 0 ? $weighted / $totalCount : 0.0;

        return view('admin.feedback.index', compact(
            'feedbacks',
            'totalCount',
            'averageRating',
            'ratingCounts'
        ));
    }
}
