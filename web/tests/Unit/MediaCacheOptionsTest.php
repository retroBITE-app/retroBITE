<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaCacheService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every artwork download once died on `[...self::CURL_OPTIONS]`: unpacking
 * renumbers integer keys, so curl was handed options 0, 1 and 2 and rejected
 * the array — turning a missing cover into an HTTP 500 on identification.
 */
final class MediaCacheOptionsTest extends TestCase
{
    public function test_curl_accepts_the_merged_option_set(): void
    {
        $curl = curl_init('https://example.com/cover.png');

        self::assertNotFalse($curl);
        self::assertTrue(
            curl_setopt_array($curl, $this->options([CURLOPT_TIMEOUT => 30])),
            'curl rejected an option key, which means the shared options lost theirs',
        );
    }

    public function test_the_merge_keeps_real_option_keys(): void
    {
        $merged = $this->options([CURLOPT_NOBODY => true]);

        $this->assertArrayHasKey(CURLOPT_CONNECTTIMEOUT, $merged);
        $this->assertArrayHasKey(CURLOPT_NOBODY, $merged);
        $this->assertArrayNotHasKey(0, $merged, 'integer keys were renumbered');
        $this->assertArrayNotHasKey(1, $merged);
    }

    /**
     * A per-request option wins over the shared default of the same name.
     */
    public function test_extra_options_override_the_shared_ones(): void
    {
        $merged = $this->options([CURLOPT_CONNECTTIMEOUT => 99]);

        $this->assertSame(99, $merged[CURLOPT_CONNECTTIMEOUT]);
    }

    /**
     * @param array<int, mixed> $extra
     * @return array<int, mixed>
     */
    private function options(array $extra): array
    {
        $method = new ReflectionMethod(MediaCacheService::class, 'curlOptions');

        return $method->invoke(new MediaCacheService(), $extra);
    }
}
