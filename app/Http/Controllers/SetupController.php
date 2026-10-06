<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\SetupTestMail;
use App\Support\SetupInstructions;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

final class SetupController
{
    public function index(): View
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return view('sender.setup', [
            'cloudDomain' => is_string($host) && str_ends_with($host, '.laravel.cloud'),
            'mailer' => (string) config('mail.default'),
            'instructions' => SetupInstructions::sections(),
        ]);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Mail::mailer((string) config('mail.default'))->to($user->email)->send(new SetupTestMail($user));

        return back()->with('status', 'Test email handed to the configured mailer.');
    }
}
