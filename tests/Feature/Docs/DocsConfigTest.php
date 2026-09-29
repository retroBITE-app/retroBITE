<?php

use App\Support\DocPath;
use Illuminate\Support\Facades\Storage;

/**
 * Config files load alphabetically, so filesystems.php cannot read a value out
 * of settings.php. These assert against the real config, with no overrides —
 * the test suite's own fakes would hide exactly that failure.
 */
test('the docs disk and the docs setting name the same directory', function () {
    expect(realpath(dirname(Storage::disk('docs')->path('x'))))
        ->toBe(realpath((string) config('settings.docs_path')));
});

test('the container hands DocPath the configured root', function () {
    expect(app(DocPath::class)->root())->toBe((string) config('settings.docs_path'));
});
