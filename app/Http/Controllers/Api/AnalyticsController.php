<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsRequest;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Cost per message in Indian Rupees
    // ─────────────────────────────────────────────────────────────────────────
    private const COST_PER_MESSAGE = 0.35;

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/analytics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return aggregated analytics metrics for the authenticated user's company.
     *
     * Supported filters (all optional, mutually exclusive):
     *   - quick_filter : last_7_days | last_14_days | last_30_days
     *   - from_date    : Y-m-d  (used together with to_date for a custom range)
     *   - to_date      : Y-m-d
     *
     * When no filter is supplied the endpoint returns lifetime metrics.
     */
    public function index(AnalyticsRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Guard: user must belong to a company.
        if (empty($user->company_id)) {
            return response()->json([
                'success' => true,
                'message' => 'Analytics retrieved successfully.',
                'data'    => $this->emptyMetrics($request),
            ]);
        }

        // ── Build base query scoped to this company ───────────────────────────
        $query = Campaign::query()->where('company_id', $user->company_id);

        // ── Apply date filter ─────────────────────────────────────────────────
        [$from, $to, $filterLabel] = $this->resolveDateRange($request);

        if ($from !== null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('created_at', '<=', $to);
        }

        // ── Aggregate in a single DB round-trip ───────────────────────────────
        $agg = $query->selectRaw(
            'COUNT(*)                                AS total_campaigns,
             SUM(status = ?)                         AS running_campaigns,
             SUM(sent_count)                         AS messages_sent,
             SUM(delivered_count)                    AS messages_delivered,
             SUM(read_count)                         AS messages_read,
             SUM(failed_count)                       AS messages_failed,
             SUM(total_recipients)                   AS total_recipients',
            ['running']
        )->first();

        // ── Safely extract raw counts ─────────────────────────────────────────
        $totalCampaigns    = (int) ($agg->total_campaigns   ?? 0);
        $runningCampaigns  = (int) ($agg->running_campaigns ?? 0);
        $messagesSent      = (int) ($agg->messages_sent     ?? 0);
        $messagesDelivered = (int) ($agg->messages_delivered ?? 0);
        $messagesRead      = (int) ($agg->messages_read     ?? 0);
        $messagesFailed    = (int) ($agg->messages_failed   ?? 0);
        $totalRecipients   = (int) ($agg->total_recipients  ?? 0);

        // ── Derived metrics ───────────────────────────────────────────────────
        $estimatedCost = round($messagesSent * self::COST_PER_MESSAGE, 2);

        // Delivery rate  = delivered / sent   × 100
        $deliveryRate  = $messagesSent > 0
            ? round(($messagesDelivered / $messagesSent) * 100, 2)
            : 0.0;

        // Read rate      = read / delivered   × 100
        $readRate      = $messagesDelivered > 0
            ? round(($messagesRead / $messagesDelivered) * 100, 2)
            : 0.0;

        // Average CTR    = read / sent        × 100  (industry-standard proxy)
        $averageCtr    = $messagesSent > 0
            ? round(($messagesRead / $messagesSent) * 100, 2)
            : 0.0;

        // ── Build response payload ────────────────────────────────────────────
        return response()->json([
            'success' => true,
            'message' => 'Analytics retrieved successfully.',
            'data'    => [
                'filter' => [
                    'type'      => $filterLabel,
                    'from_date' => $from?->toDateString(),
                    'to_date'   => $to?->toDateString(),
                ],
                'overview' => [
                    'total_campaigns'   => $totalCampaigns,
                    'running_campaigns' => $runningCampaigns,
                ],
                'messages' => [
                    'sent'      => $messagesSent,
                    'delivered' => $messagesDelivered,
                    'read'      => $messagesRead,
                    'failed'    => $messagesFailed,
                ],
                'financials' => [
                    'estimated_cost'          => $estimatedCost,
                    'estimated_cost_formatted' => '₹' . number_format($estimatedCost, 2),
                    'cost_per_message'        => self::COST_PER_MESSAGE,
                    'currency'                => 'INR',
                ],
                'rates' => [
                    'delivery_rate' => $deliveryRate,   // percentage
                    'read_rate'     => $readRate,        // percentage
                    'average_ctr'   => $averageCtr,      // percentage
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the from/to Carbon dates and a human-readable label from the
     * incoming request.  Quick filters take precedence over custom date pairs.
     *
     * @return array{Carbon|null, Carbon|null, string}
     */
    private function resolveDateRange(AnalyticsRequest $request): array
    {
        $quickFilter = $request->input('quick_filter');

        if ($quickFilter !== null) {
            $days = match ($quickFilter) {
                'last_7_days'  => 7,
                'last_14_days' => 14,
                'last_30_days' => 30,
            };

            $from = Carbon::today()->subDays($days - 1)->startOfDay();
            $to   = Carbon::today()->endOfDay();

            return [$from, $to, $quickFilter];
        }

        $fromDate = $request->input('from_date');
        $toDate   = $request->input('to_date');

        if ($fromDate !== null || $toDate !== null) {
            $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
            $to   = $toDate   ? Carbon::parse($toDate)->endOfDay()     : null;

            return [$from, $to, 'custom'];
        }

        // No filter — lifetime metrics
        return [null, null, 'all_time'];
    }

    /**
     * Return a zeroed-out metrics payload (used when the user has no company).
     */
    private function emptyMetrics(AnalyticsRequest $request): array
    {
        [, , $filterLabel] = $this->resolveDateRange($request);

        return [
            'filter' => [
                'type'      => $filterLabel,
                'from_date' => null,
                'to_date'   => null,
            ],
            'overview' => [
                'total_campaigns'   => 0,
                'running_campaigns' => 0,
            ],
            'messages' => [
                'sent'      => 0,
                'delivered' => 0,
                'read'      => 0,
                'failed'    => 0,
            ],
            'financials' => [
                'estimated_cost'          => 0.00,
                'estimated_cost_formatted' => '₹0.00',
                'cost_per_message'        => self::COST_PER_MESSAGE,
                'currency'                => 'INR',
            ],
            'rates' => [
                'delivery_rate' => 0.0,
                'read_rate'     => 0.0,
                'average_ctr'   => 0.0,
            ],
        ];
    }
}
