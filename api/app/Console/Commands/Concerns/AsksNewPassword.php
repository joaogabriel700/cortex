<?php

namespace App\Console\Commands\Concerns;

use App\Auth\PasswordPolicy;
use RuntimeException;

/** The password only comes from a hidden prompt: never an argument, option or variable. */
trait AsksNewPassword
{
    private function askNewPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->error('Rode em um terminal interativo: a senha só é digitada no prompt oculto.');

            return null;
        }

        try {
            // false: fail instead of falling back to a visible prompt.
            $password = (string) $this->secret('Nova senha (12 a 72 caracteres)', false);
            $confirmation = (string) $this->secret('Repita a senha', false);
        } catch (RuntimeException) {
            $this->error('Não foi possível ocultar a digitação. Use um terminal interativo.');

            return null;
        }

        if (! PasswordPolicy::allows($password)) {
            $this->error('Senha fora da regra: 12 a 72 caracteres e no máximo 72 bytes (acentos ocupam 2).');

            return null;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('As senhas digitadas não são iguais.');

            return null;
        }

        return $password;
    }
}
