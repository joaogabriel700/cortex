<?php

namespace App\Console\Commands;

use App\Auth\OwnerAccounts;
use App\Console\Commands\Concerns\AsksNewPassword;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    use AsksNewPassword;

    protected $signature = 'cortex:user:create
        {--locale=pt-BR : pt-BR or en}
        {--timezone=America/Sao_Paulo : IANA time zone}';

    protected $description = 'Create a user account (there is no public sign up)';

    public function handle(OwnerAccounts $accounts): int
    {
        $data = [
            'name' => trim((string) $this->ask('Nome')),
            'email' => OwnerAccounts::normalizeEmail((string) $this->ask('E-mail')),
            'locale' => (string) $this->option('locale'),
            'timezone' => (string) $this->option('timezone'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'locale' => ['required', 'in:pt-BR,en'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($accounts->findId($data['email']) !== null) {
            $this->error('Já existe um usuário com esse e-mail.');

            return self::FAILURE;
        }

        $password = $this->askNewPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $accounts->create($data['name'], $data['email'], $password, $data['locale'], $data['timezone']);
        } catch (UniqueConstraintViolationException) {
            // Never print the database message: it carries the values of the statement.
            $this->error('Já existe um usuário com esse e-mail.');

            return self::FAILURE;
        }

        $this->info('Usuário criado.');

        return self::SUCCESS;
    }
}
