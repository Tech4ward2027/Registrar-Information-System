<?php

namespace App\Http\Requests\Opcr;

use App\Models\SystemUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validates the query string for GET /opcr/performance.
 *
 * Both dates are required and inclusive (Y-m-d, display timezone). The span
 * is capped by config('opcr.max_range_days') so one request cannot ask the
 * server to aggregate an unbounded history.
 */
class OpcrPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role/module gating is done by route middleware
        // (role:3,4 + module:logbook,View). This only rejects an
        // unauthenticated or non-SystemUser principal.
        return $this->user() instanceof SystemUser;
    }

    public function rules(): array
    {
        return [
            'from'             => ['required', 'date_format:Y-m-d'],
            'to'               => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'include_archived' => ['sometimes', 'in:0,1,true,false'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $max  = (int) config('opcr.max_range_days', 732);
                $days = Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to')));

                if ($days > $max) {
                    $validator->errors()->add('to', "The date range may not exceed {$max} days.");
                }
            },
        ];
    }

    public function includeArchived(): bool
    {
        return $this->boolean('include_archived');
    }
}
