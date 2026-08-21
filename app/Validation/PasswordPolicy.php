<?php

namespace App\Validation;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public static function rule(): Password
    {
        $policy = config('naja7i.password');
        $rule = Password::min($policy['min_length'])->max($policy['max_length']);

        return $policy['check_compromised'] ? $rule->uncompromised() : $rule;
    }
}
