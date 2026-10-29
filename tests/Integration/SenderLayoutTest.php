<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('places sender destinations and optional team switching in the sidebar while keeping page feedback in main', function (bool $hasTeam): void {
    $team = new Team(['name' => 'Sidebar fixture team']);
    $pageContent = 'Sender page fixture';
    $status = 'Sender status fixture';
    $error = 'Sender validation fixture';
    $errors = (new ViewErrorBag)->put('default', new MessageBag(['title' => $error]));
    view()->share('errors', $errors);
    if ($hasTeam) {
        view()->share('selectedTeam', $team);
    }
    session()->flash('status', $status);

    $html = Blade::render('<x-sender-layout title="Sidebar fixture">{{ $pageContent }}</x-sender-layout>', compact('pageContent'));
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $navigation = $xpath->query('//*[@data-testid="bfc-sidebar-slot"]//nav[@data-testid="sender-navigation"]');

    expect($navigation->length)->toBe(1);
    $links = $xpath->query('.//a', $navigation->item(0));
    $destinations = [];
    foreach ($links as $link) {
        assert($link instanceof DOMElement);
        $destinations[] = $link->getAttribute('href');
    }
    $expected = [
        route('sender.dashboard'),
        route('sender.dashboard'),
        route('sender.templates.index'),
        route('sender.setup'),
        route('sender.envelopes.create'),
    ];
    if ($hasTeam) {
        $expected[] = route('sender.teams.select');
    }
    expect($destinations)->toBe($expected);

    $teamLinks = $xpath->query('.//*[@data-testid="selected-team"]', $navigation->item(0));
    expect($teamLinks->length)->toBe($hasTeam ? 1 : 0);
    if ($hasTeam) {
        expect($teamLinks->item(0)->textContent)->toBe($team->name);
    }

    $main = $xpath->query('//*[@data-testid="bfc-main"]')->item(0);
    expect($main)->not->toBeNull();
    expect($main->textContent)->toContain($pageContent);
    expect($xpath->query('.//*[@data-testid="sender-status"]', $main)->item(0)->textContent)->toContain($status)
        ->and($xpath->query('.//*[@data-testid="sender-errors"]', $main)->item(0)->textContent)->toContain($error);
})->with([true, false]);
