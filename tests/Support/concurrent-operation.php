<?php

declare(strict_types=1);

use App\Actions\DeclineEnvelope;
use App\Actions\RetryEnvelopeCompletion;
use App\Actions\SendEnvelope;
use App\Actions\SignEnvelope;
use App\Actions\VoidEnvelope;
use App\Events\EnvelopeReadyForCompletion;
use App\Http\Controllers\EnvelopeController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\EnsureSelectedTeam;
use App\Models\Signer;
use App\Models\Team;
use App\Services\EnvelopeLifecycleService;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $teamId, $userId, $recordId, $payload] = $argv;
$team = $operation === 'team-autoprovision' ? null : Team::query()->findOrFail((int) $teamId);
$user = User::query()->findOrFail((int) $userId);
$data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

Auth::login($user);
if ($team !== null) {
    resolve(SelectedTeam::class)->set($team);
}

try {
    if ($operation === 'completion-retry') {
        Event::fake([EnvelopeReadyForCompletion::class]);
    }
    match ($operation) {
        'send' => resolve(SendEnvelope::class)->handle($team, $user, (int) $recordId),
        'team-autoprovision' => (function () use ($user): Response {
            $request = Request::create('/sender/dashboard', 'GET');
            $request->setUserResolver(static fn (): User => $user);
            $request->setLaravelSession(app('session.store'));

            return resolve(EnsureSelectedTeam::class)->handle(
                $request,
                static fn (): Response => response('ok'),
            );
        })(),
        'sign' => resolve(SignEnvelope::class)->handle(
            Signer::query()->findOrFail((int) $recordId),
            '192.0.2.44',
            'Concurrent signing test',
        ),
        'decline' => resolve(DeclineEnvelope::class)->handle(
            Signer::query()->findOrFail((int) $recordId),
            (string) ($data['reason'] ?? 'Declined during concurrency test'),
            '192.0.2.44',
            'Concurrent signing test',
        ),
        'completion-retry' => resolve(RetryEnvelopeCompletion::class)->handle($team, $user, (int) $recordId),
        'lifecycle-due' => resolve(SystemAuthorityContext::class)->run(
            fn () => resolve(EnvelopeLifecycleService::class)->runDue(),
        ),
        'void' => resolve(VoidEnvelope::class)->handle(
            $team,
            $user,
            (int) $recordId,
            (string) ($data['reason'] ?? 'Voided during concurrency test'),
        ),
        'envelope-fields' => resolve(EnvelopeController::class)->updateFields(
            requestFor($user, ['fields_json' => json_encode($data, JSON_THROW_ON_ERROR)]),
            (int) $recordId,
        ),
        'envelope-update' => resolve(EnvelopeController::class)->update(
            requestFor($user, $data),
            (int) $recordId,
        ),
        'template-fields' => resolve(TemplateController::class)->updateFields(
            requestFor($user, ['fields_json' => json_encode($data, JSON_THROW_ON_ERROR)]),
            (int) $recordId,
        ),
        'envelope-replace' => resolve(EnvelopeController::class)->replaceDocument(
            uploadRequestFor($user, $data),
            (int) $recordId,
        ),
        'template-replace' => resolve(TemplateController::class)->replaceDocument(
            uploadRequestFor($user, $data),
            (int) $recordId,
        ),
        default => throw new LogicException('Unknown concurrent operation.'),
    };

    fwrite(STDOUT, json_encode(['status' => 'completed'], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'status' => 'refused',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'status_code' => $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : null,
    ], JSON_THROW_ON_ERROR));
}

/** @param array<string, mixed> $data */
function requestFor(User $user, array $data): Request
{
    $request = Request::create('/', 'PUT', $data);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/** @param array{path: string, name: string} $data */
function uploadRequestFor(User $user, array $data): Request
{
    $request = requestFor($user, []);
    $request->files->set('document', new UploadedFile($data['path'], $data['name'], 'application/pdf', null, true));

    return $request;
}
