<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Username
{
    public const TAKEN_MESSAGE = 'This username is already taken. Please choose another.';

    public static function normalize(?string $username): ?string
    {
        $username = strtolower(trim($username ?? ''));

        return $username === '' ? null : $username;
    }

    public static function rules(?User $user = null): array
    {
        return [
            ...($user ? ['sometimes', 'nullable'] : ['required']),
            'string',
            'min:3',
            'max:30',
            'regex:/\A[a-z0-9][a-z0-9._-]*\z/',
            Rule::unique('users', 'username')->ignore($user?->id),
        ];
    }

    public static function messages(): array
    {
        return [
            'username.required' => 'Please choose a username.',
            'username.min' => 'The username must be at least 3 characters.',
            'username.max' => 'The username must be no more than 30 characters.',
            'username.regex' => 'Start your username with a letter or number and use only letters, numbers, periods, underscores, or hyphens. Email addresses cannot be usernames.',
            'username.unique' => self::TAKEN_MESSAGE,
        ];
    }

    public static function save(User $user): void
    {
        try {
            $user->save();
        } catch (UniqueConstraintViolationException $exception) {
            // The database also protects simultaneous requests that pass validation.
            if ($user->isDirty('username') && $user->username !== null
                && User::where('username', $user->username)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['username' => self::TAKEN_MESSAGE]);
            }

            throw $exception;
        }
    }
}
