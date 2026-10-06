<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

final class SenderDashboardController
{
    public function __invoke(): RedirectResponse
    {
        return to_route('sender.dashboard');
    }
}
