<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FolderScope;
use App\Enums\MediaKind;
use App\Enums\ResponseStatus;
use App\Enums\SettingFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    /**
     * Every case must carry a label, since the UI renders them unconditionally.
     */
    #[DataProvider('allCases')]
    public function test_every_case_has_a_label(object $case): void
    {
        $this->assertNotSame('', $case->label());
    }

    public function test_media_kind_maps_to_its_payload_key(): void
    {
        $this->assertSame('cover_url', MediaKind::Cover->payloadKey());
        $this->assertSame('backdrop_url', MediaKind::Backdrop->payloadKey());
    }

    public function test_every_media_kind_has_provider_types(): void
    {
        foreach (MediaKind::cases() as $kind) {
            $this->assertNotEmpty($kind->screenScraperTypes(), $kind->value);
        }
    }

    public function test_setting_field_type_falls_back_to_text(): void
    {
        $this->assertSame(SettingFieldType::Text, SettingFieldType::fromSchema('nonsense'));
        $this->assertSame(SettingFieldType::Number, SettingFieldType::fromSchema('number'));
    }

    public function test_number_coercion_keeps_null_distinct_from_zero(): void
    {
        $this->assertNull(SettingFieldType::Number->coerce(''));
        $this->assertNull(SettingFieldType::Number->coerce(null));
        $this->assertSame(0, SettingFieldType::Number->coerce('0'));
        $this->assertSame(7, SettingFieldType::Number->coerce('7'));
    }

    public function test_list_coercion_drops_blanks_and_reindexes(): void
    {
        $this->assertSame(['iso', 'bin'], SettingFieldType::TextList->coerce(['iso', '', 'bin']));
        $this->assertSame([], SettingFieldType::TextList->coerce('not a list'));
    }

    public function test_validation_reports_only_real_type_errors(): void
    {
        $this->assertNull(SettingFieldType::Number->validate('12'));
        $this->assertSame('Must be a number', SettingFieldType::Number->validate('abc'));
        $this->assertSame('Must be a list', SettingFieldType::TextList->validate('abc'));
        $this->assertNull(SettingFieldType::Text->validate('anything'));
    }

    #[DataProvider('acceptedUrls')]
    public function test_url_accepts_a_renderable_value(mixed $value): void
    {
        $this->assertNull(SettingFieldType::Url->validate($value));
    }

    #[DataProvider('rejectedUrls')]
    public function test_url_rejects_an_unrenderable_value(mixed $value): void
    {
        $this->assertNotNull(SettingFieldType::Url->validate($value));
    }

    public static function acceptedUrls(): array
    {
        return [
            'https'         => ['https://example.test/icon.png'],
            'http'          => ['http://example.test/icon.png'],
            'root relative' => ['/images/consoles/ps2.png'],
            'empty'         => [''],
            'null'          => [null],
        ];
    }

    public static function rejectedUrls(): array
    {
        return [
            'javascript'      => ['javascript:alert(1)'],
            'data uri'        => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            'protocol split'  => ['//evil.test/icon.png'],
            'file'            => ['file:///etc/passwd'],
            'bare word'       => ['not-a-url'],
            'array'           => [['https://example.test']],
        ];
    }

    public function test_folder_scope_uses_empty_string_for_all(): void
    {
        $this->assertSame('', FolderScope::All->value);
        $this->assertSame('root', FolderScope::Root->value);
        $this->assertSame(FolderScope::All, FolderScope::tryFrom(''));
    }

    public static function allCases(): array
    {
        $cases = [];

        foreach ([MediaKind::class, SettingFieldType::class, FolderScope::class, ResponseStatus::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $cases[$enum . '::' . $case->name] = [$case];
            }
        }

        return $cases;
    }
}
