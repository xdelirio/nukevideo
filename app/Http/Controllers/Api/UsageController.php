<?php

namespace App\Http\Controllers\Api;

use App\Enums\UsageMetric;
use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsageController extends Controller
{
    public function __construct(
        private AnalyticsService $analytics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => DateRange::from(),
            'to' => DateRange::to(),
            // Constrained to the catalogue, not free text. `value` is one shared column whose
            // unit lives in the metric name, so an unrecognised name used to answer with an empty
            // result — which, for a caller building an invoice out of this, reads as zero rather
            // than as a typo. `origin_bytes` is left out on purpose: it is booked under account 0,
            // which no account-scoped read can ever be, so accepting it would only ever answer
            // empty for a different reason.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::account())],
            // The same width the column and the video's own `external_user_id` are validated to,
            // so a value this endpoint accepts is one an upload could actually have stored.
            'externalUserId' => 'nullable|string|max:255',
        ]);

        $rows = $this->analytics->accountUsage(
            $request->user()->accountId(),
            $request->input('from'),
            $request->input('to'),
            $request->input('metric'),
            $request->input('externalUserId'),
        );

        return response()->json(['data' => $rows]);
    }
}
