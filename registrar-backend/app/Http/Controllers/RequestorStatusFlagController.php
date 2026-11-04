<?php

namespace App\Http\Controllers;

use App\Http\Requests\Representative\FlagRequestorRequest;
use App\Http\Requests\Representative\LiftRequestorFlagRequest;
use App\Models\RequestorStatusFlag;
use App\Models\SystemUser;
use App\Services\Representative\RequestorStatusFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RequestorStatusFlagController extends Controller
{
    public function __construct(private RequestorStatusFlagService $flags) {}

    // POST /requestors/{user}/status-flag
    public function store(FlagRequestorRequest $request, SystemUser $user): JsonResponse
    {
        $flag = $this->flags->flag(
            $user,
            $request->validated('status'),
            $request->validated('note'),
            Auth::user(),
            $request,
        );

        return response()->json($this->payload($flag), 200);
    }

    // DELETE /requestors/{user}/status-flag   (Super Admin)
    public function destroy(LiftRequestorFlagRequest $request, SystemUser $user): JsonResponse
    {
        $flag = RequestorStatusFlag::active()->where('user_id', $user->user_id)->first();
        abort_if($flag === null, 404, 'This requestor has no active flag.');

        $flag = $this->flags->lift($flag, Auth::user(), $request->validated('reason'), $request);

        return response()->json($this->payload($flag), 200);
    }

    /** @return array<string,mixed> */
    private function payload(RequestorStatusFlag $flag): array
    {
        return [
            'user_id'   => $flag->user_id,
            'status'    => $flag->status,
            'active'    => $flag->isActive(),
            'lifted_at' => $flag->lifted_at,
        ];
    }
}
