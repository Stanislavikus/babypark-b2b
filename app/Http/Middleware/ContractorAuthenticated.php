<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ContractorAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('contractor')->check()) {
            return redirect()->route('cabinet.login');
        }

        $contractor = Auth::guard('contractor')->user();

        if (! $contractor->is_active) {
            Auth::guard('contractor')->logout();

            return redirect()->route('cabinet.login')
                ->withErrors(['login' => 'Ваш акаунт деактивовано.']);
        }

        return $next($request);
    }
}
