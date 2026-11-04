<?php

namespace App\Http\Requests\Ticket\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * A date range in Manila calendar dates (both ends included), shared by the
 * summary and the export. Defaults to the last N days when omitted; capped at
 * config('tickets.reports.max_range_days').
 */
class TicketReportRangeRequest extends ManageTicketsRequest
{
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($to < $from) {
                $validator->errors()->add('to', 'The end date cannot be before the start date.');

                return;
            }

            $max = (int) config('tickets.reports.max_range_days');

            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > $max) {
                $validator->errors()->add('to', "The range can cover at most {$max} days.");
            }
        }];
    }

    /**
     * @return array{0:string,1:string} Y-m-d dates (Manila)
     */
    public function range(): array
    {
        $tz   = (string) config('app.display_timezone', 'Asia/Manila');
        $to   = $this->input('to') ?: Carbon::now($tz)->format('Y-m-d');
        $from = $this->input('from') ?: Carbon::parse($to, $tz)->subDays(max(1, (int) config('tickets.reports.default_range_days')) - 1)->format('Y-m-d');

        return [$from, $to];
    }
}
