<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The phone's own door: a token per device, and no session anywhere.
 *
 * The panel signs people in with a cookie, which a collector's phone cannot hold across a rebuild
 * and which the office cannot revoke one device at a time. A token per DEVICE can: the office sees
 * the phones on the account, and «this phone is gone» is one row deleted rather than a password
 * changed for everybody.
 *
 * The identity is read exactly as the panel reads it (`User::credentialFor`), so an address typed on
 * a phone keyboard — bidi marks and all — still finds its account instead of being refused by a
 * character the collector cannot see.
 */
class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:60'],
        ]);

        $credential = User::credentialFor($data['identity']);

        if ($credential === null) {
            throw new ApiRefusal('auth_failed', __('portal.auth_failed'));
        }

        $user = User::query()
            ->where(array_key_first($credential), reset($credential))
            ->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            // One answer for a wrong address and a wrong password: which of the two it was is not
            // something a phone outside the office needs to learn.
            throw new ApiRefusal('auth_failed', __('portal.auth_failed'));
        }

        if ($user->isInactive()) {
            throw new ApiRefusal('account_disabled', __('auth.account_disabled'), 403);
        }

        // One token per device: a phone that is signed in again does not leave the old token alive
        // as a way back in.
        $device = (string) ($data['device'] ?? 'phone');
        $user->tokens()->where('name', $device)->delete();

        return response()->json([
            'token' => $user->createToken($device, [$user->role])->plainTextToken,
            'user' => self::userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only the token that was used: signing out on the phone must not sign the office's tablet
        // out of the panel.
        $user->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::userPayload($request->user())]);
    }

    /** @return array<string, mixed> */
    public static function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
            'locale' => $user->locale,
            'phone' => $user->phone,
        ];
    }
}
