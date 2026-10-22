<?php

namespace App\Http\Requests\Push;

use App\Rules\AllowedPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body is the browser's PushSubscription.toJSON():
 *   { endpoint, expirationTime, keys: { p256dh, auth } }
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind auth:sanctum; a user can only ever act on their own devices.
        return true;
    }

    public function rules(): array
    {
        return [
            'endpoint'         => ['required', 'string', 'max:2048', new AllowedPushEndpoint()],
            'keys'             => ['required', 'array'],
            // base64url alphabet only. p256dh is 65 bytes (87 chars), auth is 16 bytes (22 chars).
            'keys.p256dh'      => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'keys.auth'        => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}
