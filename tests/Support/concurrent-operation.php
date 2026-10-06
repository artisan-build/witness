<?php

declare(strict_types=1);

use App\Actions\SendEnvelope;
use App\Http\Controllers\EnvelopeController;
use App\Http\Controllers\TemplateController;
use App\Models\Team;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $teamId, $userId, $recordId, $payload] = $argv;
$team = Team::query()->findOrFail((int) $teamId);
$user = User::query()->findOrFail((int) $userId);
$data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

Auth::login($user);
resolve(SelectedTeam::class)->set($team);

try {
    match ($operation) {
        'send' => resolve(SendEnvelope::class)->handle($team, $user, (int) $recordId),
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
