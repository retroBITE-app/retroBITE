<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PathRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathRulesTest extends TestCase
{
    #[DataProvider('acceptedSubfolders')]
    public function test_accepts_legal_subfolders(string $subfolder): void
    {
        $this->assertTrue(PathRules::isSubfolder($subfolder));
    }

    #[DataProvider('rejectedSubfolders')]
    public function test_rejects_illegal_subfolders(string $subfolder): void
    {
        $this->assertFalse(PathRules::isSubfolder($subfolder));
    }

    public function test_root_is_only_legal_where_explicitly_allowed(): void
    {
        $this->assertFalse(PathRules::isSubfolder(''));
        $this->assertTrue(PathRules::isSubfolderOrRoot(''));
    }

    public function test_normalizes_surrounding_slashes(): void
    {
        $this->assertSame('DVD/EU', PathRules::normalizeSubfolder('/DVD/EU/'));
        $this->assertSame('', PathRules::normalizeSubfolder('/'));
    }

    public function test_accepts_a_uuid_v4_upload_id(): void
    {
        $this->assertTrue(PathRules::isUploadId('9c74df04-79f1-480b-a2be-80596030843a'));
    }

    #[DataProvider('rejectedUploadIds')]
    public function test_rejects_a_malformed_upload_id(string $uploadId): void
    {
        $this->assertFalse(PathRules::isUploadId($uploadId));
    }

    public static function acceptedSubfolders(): array
    {
        return [
            'single segment' => ['DVD'],
            'nested'         => ['DVD/EU'],
            'deeply nested'  => ['a/b/c/d'],
            'dashes'         => ['Misc-BIOS'],
            'underscores'    => ['misc_bios'],
            'digits'         => ['disc2'],
        ];
    }

    public static function rejectedSubfolders(): array
    {
        return [
            'parent traversal'   => ['../etc'],
            'nested traversal'   => ['DVD/../../etc'],
            'single dot'         => ['.'],
            'absolute'           => ['/etc'],
            'trailing slash'     => ['DVD/'],
            'empty segment'      => ['DVD//EU'],
            'space'              => ['Misc BIOS'],
            'like wildcard'      => ['%'],
            'like single wild'   => ['_x%'],
            'null byte'          => ["DVD\0"],
            'encoded traversal'  => ['..%2f..%2fetc'],
        ];
    }

    public static function rejectedUploadIds(): array
    {
        return [
            'all dashes'        => ['------------------------------------'],
            'too short'         => ['9c74df04-79f1-480b-a2be-8059603084'],
            'uppercase'         => ['9C74DF04-79F1-480B-A2BE-80596030843A'],
            'path traversal'    => ['../../etc/passwd'],
            'empty'             => [''],
            'wrong separators'  => ['9c74df0479f1480ba2be80596030843a'],
        ];
    }
}
