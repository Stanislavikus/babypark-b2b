<?php

namespace App\Filament\Cabinet\Pages\Auth;

use Filament\Schemas\Components\Component;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getCredentialsFromFormData(array $data): array
    {
        return ['login' => $data['login'], 'password' => $data['password']];
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Логін')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response === null) {
            return null;
        }

        $customer = filament()->auth()->user();

        if (! $customer->is_active) {
            filament()->auth()->logout();

            throw ValidationException::withMessages([
                'data.login' => 'Ваш акаунт деактивовано.',
            ]);
        }

        return $response;
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'Невірний логін або пароль.',
        ]);
    }
}
