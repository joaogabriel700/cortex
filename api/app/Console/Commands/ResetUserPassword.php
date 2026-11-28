<?php

namespace App\Console\Commands;

use App\Auth\OwnerAccounts;
use App\Console\Commands\Concerns\AsksNewPassword;
use App\Console\Commands\Concerns\HidesDatabaseErrors;
use Illuminate\Console\Command;

class ResetUserPassword extends Command
{
    use AsksNewPassword, HidesDatabaseErrors;

    protected $signature = 'cortex:user:reset-password {email : E-mail of the account}';

    protected $description = 'Set a new password and sign the user out of every device';

    public function handle(OwnerAccounts $accounts): int
    {
        return $this->hidingDatabaseErrors(fn () => $this->reset($accounts));
    }

    private function reset(OwnerAccounts $accounts): int
    {
        $userId = $accounts->findId((string) $this->argument('email'));

        if ($userId === null) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $password = $this->askNewPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        if ($accounts->passwordIsCurrent($userId, $password)) {
            $this->error('A nova senha precisa ser diferente da atual.');

            return self::FAILURE;
        }

        $accounts->resetPassword($userId, $password);

        $this->info('Senha redefinida. Todas as sessões desse usuário foram encerradas.');

        return self::SUCCESS;
    }
}
