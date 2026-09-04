<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Routes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    public function test_builds_a_url_with_no_parameters(): void
    {
        $this->assertSame('/', Routes::url('dashboard'));
        $this->assertSame('/api/network/status', Routes::url('network.status'));
    }

    public function test_substitutes_a_single_segment_parameter(): void
    {
        $this->assertSame('/consoles/ps2', Routes::url('console', ['console' => 'ps2']));
    }

    public function test_encodes_a_single_segment_parameter(): void
    {
        $this->assertSame(
            '/consoles/ps2/Some%20Game%20%28USA%29.iso',
            Routes::url('game', ['console' => 'ps2', 'game' => 'Some Game (USA).iso']),
        );
    }

    public function test_a_single_segment_parameter_cannot_inject_a_path(): void
    {
        $this->assertSame(
            '/consoles/ps2/..%2F..%2Fetc%2Fpasswd',
            Routes::url('game', ['console' => 'ps2', 'game' => '../../etc/passwd']),
        );
    }

    public function test_a_catch_all_parameter_keeps_its_separators(): void
    {
        $this->assertSame(
            '/consoles/ps2/folder/DVD/EU',
            Routes::url('console.folder.destroy', ['console' => 'ps2', 'folder' => 'DVD/EU']),
        );
    }

    public function test_a_catch_all_parameter_still_encodes_each_segment(): void
    {
        $this->assertSame(
            '/consoles/ps2/folder/Misc%20BIOS/x',
            Routes::url('console.folder.destroy', ['console' => 'ps2', 'folder' => 'Misc BIOS/x']),
        );
    }

    public function test_rejects_an_unknown_route(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Routes::url('does.not.exist');
    }

    public function test_rejects_a_url_with_an_unfilled_parameter(): void
    {
        $this->expectExceptionMessageMatches('/missing a parameter/');

        Routes::url('console');
    }

    public function test_every_route_declares_a_method_path_and_handler(): void
    {
        foreach (Routes::all() as $name => $route) {
            $this->assertArrayHasKey('method', $route, $name);
            $this->assertArrayHasKey('path', $route, $name);
            $this->assertArrayHasKey('handler', $route, $name);
            $this->assertStringStartsWith('/', $route['path'], $name);
            $this->assertTrue(method_exists($route['handler'][0], $route['handler'][1]), $name);
        }
    }

    public function test_login_routes_are_the_only_public_ones(): void
    {
        $this->assertSame(
            ['login', 'login.submit'],
            Routes::requiringAuth(false)->keys()->all(),
        );
    }
}
