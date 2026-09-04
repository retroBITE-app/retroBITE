<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AboutService;
use Illuminate\Support\Arr;

/**
 * The About tab mixes declared content with what only the running host knows,
 * so both halves have to arrive intact.
 */
final class AboutPageDataTest extends DatabaseTestCase
{
    private AboutService $about;

    protected function setUp(): void
    {
        parent::setUp();

        $this->about = new AboutService();
    }

    public function test_payload_carries_the_declared_identity(): void
    {
        $payload = $this->about->payload();

        $this->assertSame(config('about.name'), $payload['name']);
        $this->assertSame(config('about.version'), $payload['version']);
        $this->assertNotEmpty($payload['summary']);
    }

    public function test_links_and_credits_carry_what_the_tab_renders(): void
    {
        $payload = $this->about->payload();

        foreach ($payload['links'] as $link) {
            $this->assertNotSame('', Arr::get($link, 'label', ''));
            $this->assertStringStartsWith('https://', Arr::get($link, 'url', ''));
        }

        foreach ($payload['credits'] as $credit) {
            $this->assertNotSame('', Arr::get($credit, 'name', ''));
            $this->assertNotSame('', Arr::get($credit, 'role', ''));
        }

        $this->assertNotEmpty($payload['links']);
        $this->assertNotEmpty($payload['credits']);
    }

    /**
     * The build rows report the live host, so the version has to come from the
     * open connection rather than a constant.
     */
    public function test_build_rows_report_the_running_host(): void
    {
        $rows = Arr::pluck($this->about->payload()['build'], 'value', 'key');

        $this->assertStringContainsString(PHP_VERSION, $rows['Runtime']);
        $this->assertStringContainsString(strtolower(PHP_OS_FAMILY), $rows['Platform']);
        $this->assertMatchesRegularExpression('/^sqlite \d+\.\d+/', $rows['Database']);
    }
}
