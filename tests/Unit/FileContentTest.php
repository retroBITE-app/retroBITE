<?php

use App\Enums\FileContent;

it('reads what a dump holds off its name, and claims nothing for a name that does not say', function (string $name, ?FileContent $content) {
    expect(FileContent::fromName($name))->toBe($content);
})->with([
    'Redump add-on disc' => ['Borderlands 2 - Game of the Year Edition (Europe) (En,Fr,De,Es,It) (Add-On Disc).iso', FileContent::Dlc],
    'DLC' => ['Some Game (USA) (DLC).nsp', FileContent::Dlc],
    'add-on' => ['Some Game (Japan) (Add-On).zip', FileContent::Dlc],
    'update' => ['Some Game (Europe) (Update).pkg', FileContent::Update],
    'patch, any case' => ['Some Game (World) (pAtCh v1.02).zip', FileContent::Update],
    'a game says nothing' => ['Diablo III - Reaper of Souls (Europe) (En,Fr,De,Es,It).iso', null],
    'no tags' => ['Game.iso', null],
    'the word outside a tag' => ['Update Hero (USA).iso', null],
]);

it('marks DLC and updates as extra, a game not', function () {
    expect(FileContent::Dlc->extra())->toBeTrue()
        ->and(FileContent::Update->extra())->toBeTrue()
        ->and(FileContent::Game->extra())->toBeFalse();
});
