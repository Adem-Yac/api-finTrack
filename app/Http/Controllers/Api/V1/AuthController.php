<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $email = $this->blankToNull($request->input('email'));
        $phone = $this->normalizePhone($request->input('phone'));
        $request->merge([
            'email' => $email,
            'phone' => $phone,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => 'Email ou numéro de téléphone requis.',
            ]);
        }

        $existing = User::query()
            ->where(function ($query) use ($data): void {
                if (! empty($data['email'])) {
                    $query->orWhere('email', $data['email']);
                }
                if (! empty($data['phone'])) {
                    $query->orWhere('phone', $data['phone']);
                }
            })
            ->first();

        if ($existing) {
            if (Hash::check($data['password'], (string) $existing->password)) {
                return $this->tokenResponse($existing);
            }

            throw ValidationException::withMessages([
                'email' => 'Un compte existe déjà avec cet email ou ce téléphone. Connectez-vous.',
            ]);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? $this->placeholderEmail($data['phone'] ?? Str::uuid()),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'currency' => 'DZD',
        ]);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $identifier = trim((string) (
            $request->input('identifier')
            ?? $request->input('email')
            ?? $request->input('phone')
            ?? ''
        ));
        $password = (string) $request->input('password', '');
        $phone = $this->normalizePhone($identifier);

        if ($identifier === '' || $password === '') {
            throw ValidationException::withMessages([
                'identifier' => 'Email ou téléphone, et mot de passe, sont requis.',
            ]);
        }

        $user = User::query()
            ->where(function ($query) use ($identifier, $phone): void {
                $query->where('email', $identifier)
                    ->orWhere('phone', $identifier);
                if ($phone !== null && $phone !== $identifier) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'Identifiants incorrects.',
            ]);
        }

        return $this->tokenResponse($user);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $payload = Http::timeout(10)
            ->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $data['id_token'],
            ]);

        if (! $payload->ok()) {
            throw ValidationException::withMessages([
                'id_token' => 'Jeton Google invalide.',
            ]);
        }

        $info = $payload->json();
        $email = $info['email'] ?? null;
        $googleId = $info['sub'] ?? null;
        $expectedClient = config('fintrack.google.client_id');

        if ($expectedClient && ($info['aud'] ?? null) !== $expectedClient) {
            throw ValidationException::withMessages([
                'id_token' => 'Client Google non autorisé.',
            ]);
        }

        if (! $email || ! $googleId) {
            throw ValidationException::withMessages([
                'id_token' => 'Profil Google incomplet.',
            ]);
        }

        $user = User::query()->where('google_id', $googleId)->orWhere('email', $email)->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => $info['name'] ?? Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleId,
                'password' => Str::password(24),
                'currency' => 'DZD',
            ]);
        } else {
            $user->forceFill(['google_id' => $googleId])->save();
        }

        return $this->tokenResponse($user);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink(['email' => $data['email']]);

        return response()->json([
            'message' => 'Si un compte existe, un lien de réinitialisation a été envoyé.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $status = Password::reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password'],
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'Lien de réinitialisation invalide.',
            ]);
        }

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone,'.$user->id],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $user->fill($data)->save();

        return response()->json(['data' => $this->userPayload($user->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user),
            ],
        ], $status);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'currency' => $user->currency,
            'avatar_path' => $user->avatar_path,
        ];
    }

    private function placeholderEmail(string $phone): string
    {
        $safe = preg_replace('/\D+/', '', $phone) ?: Str::uuid();

        return $safe.'@phone.fintrack.local';
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $phone = $this->blankToNull($value);
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: $phone;

        return $digits;
    }
}
