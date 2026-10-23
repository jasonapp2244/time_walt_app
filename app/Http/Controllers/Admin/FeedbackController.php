<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FeedbackController extends Controller
{
    /**
     * List all user feedback with optional rating filter.
     */
    public function index(Request $request): View
    {
        $feedbacks = $this->filteredQuery($request)->latest()->paginate(15)->withQueryString();

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

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Feedback', $this->filteredQuery($request)->latest(), [
            'ID', 'User', 'Email', 'Rating', 'Feedback', 'Date',
        ], fn (Feedback $f) => [
            str_pad($f->id, 5, '0', STR_PAD_LEFT), $f->user?->displayName() ?? 'Deleted user', $f->user?->displayEmail() ?? '—',
            (int) $f->rating, $f->feedback, $f->created_at,
        ], ['Rating' => $request->rating ? $request->rating.' stars' : null]);
    }

    /**
     * Live total and newest ID for AJAX polling: either changing means new feedback.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Feedback::count(),
            'latest_id' => (int) Feedback::max('id'),
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Feedback::with('user');

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }

        return $query;
    }
}
