<?php

namespace App\Livewire\Cabinet;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.cabinet')]
class Login extends Component
{
    #[Validate('required|string')]
    public string $login = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $this->validate();

        if (! Auth::guard('customer')->attempt(
            ['login' => $this->login, 'password' => $this->password],
            $this->remember
        )) {
            $this->addError('login', 'Невірний логін або пароль.');

            return;
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer->is_active) {
            Auth::guard('customer')->logout();
            $this->addError('login', 'Ваш акаунт деактивовано.');

            return;
        }

        $this->redirect(route('cabinet.catalog'), navigate: true);
    }

    public function render()
    {
        return view('livewire.cabinet.login');
    }
}
