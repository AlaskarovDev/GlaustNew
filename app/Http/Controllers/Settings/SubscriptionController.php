<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function expired(): View|RedirectResponse
    {
        if (tenant()->isUsable()) {
            return redirect()->route('dashboard');
        }

        return view('settings.subscription-expired', ['company' => tenant()]);
    }
}
