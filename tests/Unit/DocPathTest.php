<?php

use App\Exceptions\DocPathException;
use App\Support\DocPath;

/**
 * Every write, move and delete in the Docs feature resolves its path through
 * DocPath, so these are the cases a request can actually influence. They are
 * asserted here rather than through the component above them.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-docs-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/ps2/media', 0o755, true);

    $this->outside = sys_get_temp_dir().'/retrobite-outside-'.bin2hex(random_bytes(6));
    mkdir($this->outside);
    touch($this->outside.'/secret.md');

    $this->path = new DocPath($this->root);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root).' '.escapeshellarg($this->outside));
});

test('admits a document inside a console folder', function () {
    expect($this->path->assertDocument('ps2/scph-39001-laser.md'))
        ->toBe('ps2/scph-39001-laser.md');
});

test('admits a document that does not exist yet', function () {
    expect($this->path->assertDocument('n64/rcp-heatsink.md'))
        ->toBe('n64/rcp-heatsink.md');
});

test('builds a document path from a console and a slug', function () {
    expect($this->path->document('gc', 'dol-001-vs-dol-101'))
        ->toBe('gc/dol-001-vs-dol-101.md');
});

test('refuses a parent traversal', function () {
    $this->path->assertDocument('ps2/../../escaped.md');
})->throws(DocPathException::class);

test('refuses a bare parent segment', function () {
    $this->path->assertDocument('../escaped.md');
})->throws(DocPathException::class);

test('refuses an absolute path', function () {
    $this->path->assertDocument('/etc/passwd.md');
})->throws(DocPathException::class);

test('refuses a windows separator', function () {
    $this->path->assertDocument('ps2\\..\\escaped.md');
})->throws(DocPathException::class);

test('refuses a null byte', function () {
    $this->path->assertDocument("ps2/laser\0.md");
})->throws(DocPathException::class);

test('refuses an empty path', function () {
    $this->path->assertDocument('');
})->throws(DocPathException::class);

test('refuses a doubled separator', function () {
    $this->path->assertDocument('ps2//laser.md');
})->throws(DocPathException::class);

test('refuses a trailing separator', function () {
    $this->path->assertDocument('ps2/laser.md/');
})->throws(DocPathException::class);

test('refuses a hidden file', function () {
    $this->path->assertDocument('ps2/.hidden.md');
})->throws(DocPathException::class);

test('refuses a doubled extension', function () {
    $this->path->assertDocument('ps2/laser.php.md');
})->throws(DocPathException::class);

test('refuses an extension we do not write', function () {
    $this->path->assertDocument('ps2/laser.php');
})->throws(DocPathException::class);

test('refuses a document with no extension', function () {
    $this->path->assertDocument('ps2/laser');
})->throws(DocPathException::class);

test('refuses a document reached through the revisions folder', function () {
    $this->path->assertDocument('.revisions/ps2/laser/1758067200.md');
})->throws(DocPathException::class);

test('refuses a symlink pointing out of the root', function () {
    symlink($this->outside, $this->root.'/leak');

    $this->path->assertDocument('leak/secret.md');
})->throws(DocPathException::class);

test('refuses a symlinked document', function () {
    symlink($this->outside.'/secret.md', $this->root.'/ps2/leak.md');

    $this->path->assertDocument('ps2/leak.md');
})->throws(DocPathException::class);

test('carries the reason and the offending path', function () {
    try {
        $this->path->assertDocument('ps2/../../escaped.md');
    } catch (DocPathException $e) {
        expect($e->reason)->toBe(DocPathException::MALFORMED)
            ->and($e->path)->toBe('ps2/../../escaped.md');
    }
});

test('reports a resolved escape as outside the root', function () {
    symlink($this->outside, $this->root.'/leak');

    try {
        $this->path->assertDocument('leak/secret.md');
    } catch (DocPathException $e) {
        expect($e->reason)->toBe(DocPathException::OUTSIDE_ROOT);
    }
});

/**
 * realpath() cannot catch this one: the link lands back inside the root, so the
 * containment check passes and only the explicit refusal stops it. It matters
 * because the docs tree can be bind-mounted somewhere the share container writes.
 */
test('refuses a symlink that stays inside the root', function () {
    touch($this->root.'/ps2/real.md');
    symlink($this->root.'/ps2/real.md', $this->root.'/ps2/alias.md');

    try {
        $this->path->assertDocument('ps2/alias.md');
    } catch (DocPathException $e) {
        expect($e->reason)->toBe(DocPathException::SYMLINK);

        return;
    }

    $this->fail('a symlinked document was admitted');
});

test('admits an attachment in a media folder', function () {
    expect($this->path->assertMedia('ps2/media/scph-39001-pot.jpg'))
        ->toBe('ps2/media/scph-39001-pot.jpg');
});

test('refuses an attachment outside a media folder', function () {
    $this->path->assertMedia('ps2/scph-39001-pot.jpg');
})->throws(DocPathException::class);

test('refuses a markdown file through the media gate', function () {
    $this->path->assertMedia('ps2/media/laser.md');
})->throws(DocPathException::class);

test('refuses an executable extension through the media gate', function () {
    $this->path->assertMedia('ps2/media/shell.php');
})->throws(DocPathException::class);

test('accepts an uppercase attachment extension', function () {
    expect($this->path->assertMedia('ps2/media/Pot.JPG'))
        ->toBe('ps2/media/Pot.JPG');
});

test('names the media folder beside the document', function () {
    expect($this->path->mediaDirectory('ps2/scph-39001-laser.md'))->toBe('ps2/media');
});

test('links an attachment relative to the document', function () {
    expect(DocPath::mediaLink('scph-39001-pot.jpg'))->toBe('media/scph-39001-pot.jpg');
});

test('names the revision folder for a document', function () {
    expect($this->path->revisionDirectory('ps2/scph-39001-laser.md'))
        ->toBe('.revisions/ps2/scph-39001-laser');
});

test('names one revision for the moment it was superseded', function () {
    expect($this->path->revision('ps2/scph-39001-laser.md', 1758067200))
        ->toBe('.revisions/ps2/scph-39001-laser/1758067200.md');
});

test('refuses a revision folder for a malformed document', function () {
    $this->path->revisionDirectory('../escaped.md');
})->throws(DocPathException::class);

test('resolves an absolute path under the root', function () {
    expect($this->path->absolute('ps2/scph-39001-laser.md'))
        ->toBe($this->root.'/ps2/scph-39001-laser.md');
});

test('slugs a title into a filename stem', function () {
    expect(DocPath::slug('SCPH-39001 laser POT calibration'))
        ->toBe('scph-39001-laser-pot-calibration');
});

test('falls back when a title slugs to nothing', function () {
    expect(DocPath::slug('—'))->toBe(DocPath::FALLBACK_SLUG);
});

test('recognises a document on disk without touching it', function () {
    expect(DocPath::looksLikeDocument('ps2/laser.md'))->toBeTrue()
        ->and(DocPath::looksLikeDocument('ps2/media/laser.md'))->toBeFalse()
        ->and(DocPath::looksLikeDocument('.revisions/ps2/laser/1.md'))->toBeFalse()
        ->and(DocPath::looksLikeDocument('ps2/media/pot.jpg'))->toBeFalse();
});
