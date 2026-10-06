<?php

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Enums\FieldType;
use App\Mail\SigningCode;
use App\Mail\SigningInvitation;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\RealTextPdfFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

function createSigningEnvelope(array $types = []): array
{
    Storage::fake('local');
    Mail::fake();
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create([
        'name' => 'Morgan Sender',
        'email' => 'sender-'.Str::uuid().'@example.test',
    ]);
    $team->users()->attach($sender);
    $pdf = RealTextPdfFixture::make();
    $path = 'documents/signing-fixture.pdf';
    Storage::put($path, $pdf);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        $team,
        $sender,
        'Visible two-page agreement',
        'agreement.pdf',
        $path,
        strlen($pdf),
        2,
        hash('sha256', $pdf),
    );
    $signer = $envelope->signers()->create([
        'name' => 'Alex Signer',
        'email' => 'alex-'.Str::uuid().'@example.test',
        'position' => 0,
    ]);

    foreach ($types ?: FieldType::cases() as $index => $type) {
        $signer->fields()->create([
            'page' => $index % 2 + 1,
            'type' => $type,
            'required' => true,
            'position_x' => .1,
            'position_y' => .1 + ($index * .1),
            'width' => .3,
            'height' => .08,
        ]);
    }

    resolve(SendEnvelope::class)->handle(
        $team,
        $sender,
        $envelope->getKey(),
        '192.0.2.20',
        'Sender test agent',
    );

    $token = null;
    Mail::assertSent(
        SigningInvitation::class,
        function (SigningInvitation $mail) use (&$token, $signer): bool {
            if ($mail->signer->is($signer)) {
                $token = $mail->linkToken;
            }

            return $mail->signer->is($signer);
        },
    );

    return [$envelope->refresh(), $signer->refresh(), $token];
}

function openAndVerifySigner(TestCase $test, string $token): string
{
    $test->withHeader('User-Agent', 'Witness signer test')->get(route('sign.open', $token))
        ->assertRedirect(route('sign.show'))
        ->assertDontSee($token);
    $code = null;
    Mail::assertSent(
        SigningCode::class,
        function (SigningCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        },
    );
    expect($code)->toMatch('/^\d{6}$/');
    $test->post(route('sign.verify'), ['code' => $code])->assertRedirect(route('sign.show'));

    return $code;
}
