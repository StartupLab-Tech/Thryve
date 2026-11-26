<?php

namespace App\Http\Controllers;

use App\Ai\Personalization;
use App\Ai\SensitiveData;
use App\Http\Responses\Api;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** The signed-in person's own account. */
class AccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        $d = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.config('thryve.min_password_length'), 'max:255', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();

        if (! Hash::check($d['current_password'], $user->password)) {
            return Api::error('validation_failed', 'The given data was invalid.', 422, ['current_password' => ['The current password is incorrect.']]);
        }

        $user->password = $d['password']; // hashed by the model cast; the session stays signed in
        $user->must_change_password = false;
        $user->save();
        Audit::log('account.password_changed', $user, $user);

        return Api::ok(['changed' => true]);
    }

    public function showPersonalization(Request $request): JsonResponse
    {
        return $this->personalizationResponse((array) $request->user()->personalization);
    }

    /** Saves the person's own AI preferences. Sending every field empty clears them. */
    public function updatePersonalization(Request $request): JsonResponse
    {
        $rules = ['acknowledge_sensitive' => ['nullable', 'boolean']];
        foreach (Personalization::FIELD_LIMITS as $field => $max) {
            $rules[$field] = ['nullable', 'string', 'max:'.$max];
        }
        $d = $request->validate($rules);
        $values = Personalization::clean($d);

        if (Personalization::length($values) > Personalization::TOTAL_LIMIT) {
            $message = 'Keep your preferences to '.Personalization::TOTAL_LIMIT.' characters in total.';

            return Api::error('validation_failed', $message, 422, ['personalization' => [$message]]);
        }
        if ($values && ($found = SensitiveData::find(Personalization::joined($values)))) {
            $what = implode(', ', $found);
            if (Settings::get('sensitive_data_mode') === 'block') {
                return Api::error('sensitive_blocked', "Your preferences look like they contain {$what}. Remove it and try again.", 422, ['found' => $found]);
            }
            if (! ($d['acknowledge_sensitive'] ?? false)) {
                return Api::error('sensitive_warning', "Your preferences look like they contain {$what}. Save them anyway only if it is safe to share.", 409, ['found' => $found]);
            }
        }

        $user = $request->user();
        $user->forceFill(['personalization' => $values ?: null])->save();
        Audit::log($values ? 'account.personalization_updated' : 'account.personalization_cleared', $user, $user, $values ? ['length' => Personalization::length($values)] : []);

        return $this->personalizationResponse($values);
    }

    /** @param  array<string, mixed>  $values */
    private function personalizationResponse(array $values): JsonResponse
    {
        $full = [];
        foreach (array_keys(Personalization::FIELD_LIMITS) as $field) {
            $full[$field] = is_string($values[$field] ?? null) ? $values[$field] : '';
        }

        return Api::ok(['personalization' => $full, 'limit' => Personalization::TOTAL_LIMIT, 'fields' => Personalization::FIELD_LIMITS]);
    }
}
