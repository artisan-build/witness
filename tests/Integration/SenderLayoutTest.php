<?php

declare(strict_types=1);

use App\Enums\EnvelopeFilters;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('places envelope filters and templates in the sidebar, keeps admin and team links out for a guest, and keeps page feedback in main', function (): void {
    $pageContent = 'Sender page fixture';
    $status = 'Sender status fixture';
    $error = 'Sender validation fixture';
    $errors = (new ViewErrorBag)->put('default', new MessageBag(['title' => $error]));
    view()->share('errors', $errors);
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
    expect($destinations)->toBe([
        route('sender.dashboard'),
        ...array_map(fn (EnvelopeFilters $filter): string => route('sender.dashboard', ['status' => $filter->value]), EnvelopeFilters::cases()),
        route('sender.templates.index'),
    ]);

    $footer = $xpath->query('//*[@data-testid="bfc-sidebar-bottom"]')->item(0);
    expect($xpath->query('.//*[@data-testid="setup-navigation"]', $footer)->length)->toBe(0)
        ->and($xpath->query('.//*[@data-testid="selected-team"]', $footer)->length)->toBe(0);

    $main = $xpath->query('//*[@data-testid="bfc-main"]')->item(0);
    expect($main)->not->toBeNull();
    expect($main->textContent)->toContain($pageContent);
    expect($xpath->query('.//*[@data-testid="sender-status"]', $main)->item(0)->textContent)->toContain($status)
        ->and($xpath->query('.//*[@data-testid="sender-errors"]', $main)->item(0)->textContent)->toContain($error);
});
