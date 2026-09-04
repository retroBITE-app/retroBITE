<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RomFilename;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RomFilenameTest extends TestCase
{
    #[DataProvider('searchNames')]
    public function test_derives_a_search_name(string $filename, string $expected): void
    {
        $this->assertSame($expected, RomFilename::toSearchName($filename));
    }

    public function test_extracts_parenthesised_tags(): void
    {
        $this->assertSame(
            ['Europe', 'En,Fr,De'],
            RomFilename::tags('Some Game (Europe) (En,Fr,De).iso'),
        );
    }

    public function test_has_no_tags_when_the_name_is_bare(): void
    {
        $this->assertSame([], RomFilename::tags('Some Game.iso'));
    }

    public static function searchNames(): array
    {
        return [
            'strips extension'      => ['Ico.iso', 'Ico'],
            'strips region tag'     => ['Ico (Europe).iso', 'Ico'],
            'strips bracket tag'    => ['Ico [!].iso', 'Ico'],
            'strips several tags'   => ['Ico (Europe) (En,Fr,De) [!].iso', 'Ico'],
            'strips SLUS prefix'    => ['SLUS-20576. Ico.iso', 'Ico'],
            'strips SLES prefix'    => ['SLES_527.25 - Ico.iso', 'Ico'],
            'strips disc suffix'    => ['Final Fantasy VII - Disc 1.bin', 'Final Fantasy VII'],
            'collapses whitespace'  => ['Ico    the   Game.iso', 'Ico the Game'],
            'keeps inner punctuation' => ["Ratchet & Clank.iso", 'Ratchet & Clank'],
        ];
    }
}
