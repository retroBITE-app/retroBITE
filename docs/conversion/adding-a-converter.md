# Adding a converter

Tools → Conversion converts disc images from one format to another. Each conversion is
one **converter**: a self-contained class that declares what it takes and what
it writes, registered by key in `config/converters.php`. Nothing else in the
app knows about any single tool. Adding a toolset for another console means
adding converters (and, where needed, a tool); the queue, the page, the job and
the tests around them stay as they are.

## How the pieces fit

| Piece | File | Job |
|---|---|---|
| Registry | `config/converters.php` → `converters` | key → class, the only place a converter is named |
| Tools | `config/converters.php` → `tools`, `App\Conversion\Tools` | where each binary is looked for, its pinned version, how to ask it its version |
| Manifest | `App\Conversion\Converter` (abstract) | what one converter declares and builds |
| Gate | `App\Conversion\Converters::routesFor()` | which converters a source on a console may go through |
| Source | `App\Conversion\SourceSet`, `App\Conversion\Disc` | one disc, or a multi-disc set, built from the scanner's rows |
| Queue | `App\Conversion\ConversionQueue`, `App\Models\Conversion` | add, cancel, retry, clear; the persisted state |
| Runner | `App\Conversion\ConversionRunner`, `App\Jobs\RunConversion` | runs it on the conversion worker: stage, run, verify, place, clean up |

**The gate.** A converter is offered for a source only when all of these hold:

- the console lists the converter's key in its own `config/consoles/<key>.php` `converters`;
- every disc's extension is in `from()`, and in the console's `file_extensions`;
- `supports()` returns true for this set;
- the tool is installed.

The page offers only what the gate returns, and the job asks again before it
runs. That is why a converter must never be reachable any other way.

## 1. Write the class

Put it in `app/Conversion/Converters/`, extending `App\Conversion\Converter`. If a
base class for its tool already exists, extend that instead:
`ChdmanConverter` and `MaxcsoConverter` carry the tool key, progress parsing
and shared helpers.

```php
final class IsoToRvz extends Converter
{
    public function key(): string { return 'rvz'; }          // stored on the row; never rename
    public function label(): string { return 'RVZ'; }        // as the page names the format
    public function description(): string { return 'dolphin-tool convert'; }
    public function tool(): string { return 'dolphin-tool'; } // a key in config('converters.tools')

    /** @return list<string> */
    public function from(): array { return ['iso', 'gcm']; }
    public function to(): string { return 'rvz'; }

    /** @return list<string> */
    public function arguments(string $input, array $outputs, array $options): array
    {
        return ['convert', '-i', $input, '-o', $outputs[0], '-f', 'rvz', '-c', 'zstd', '-l', '5', '-b', '131072'];
    }
}
```

Override only what differs from the defaults:

| Method | Default | Override when |
|---|---|---|
| `options()` | `[KEEP_SOURCE]` | it offers `VERIFY` or `COMPRESSION` too. Only declared options reach the page. A new kind of option (e.g. split above 4 GB) is a new constant on `Converter`, a control on the page, and a case in `ConversionQueue::options()`. |
| `compressions()` | none | it has levels: `value => label`, first is the default. Read the choice with `$this->compression($options)`. |
| `settings()` | none | the tool has flags worth tuning. Return `Setting`s (key, label, description, default, choices). They show under **Advanced options**, and anything not in `choices` falls back to the default, so free text never reaches the command line. Read one with `$this->setting($options, 'key')`. Existing examples: maxcso `block` / `threads`, chdman `hunk` / `processors`. |
| `supports(SourceSet)` | true | the extension is not enough: sector size, CD vs DVD, a single-track sheet. Keep it cheap, since the page calls it on every render; cache anything that starts a process (see `ChdmanConverter::kind()`). |
| `outputsFor(Disc)` | `[stem.to]` | it writes more than one file (a cue and a bin), or names the output differently (`Game.bin.ecm`). |
| `tools()` | `[tool()]` | it runs more than one tool. The gate offers it only when **all** are installed, and the page names the first missing one. |
| `commands(Disc, input, outputs, options, root, staging)` | one command: `tool()` + `arguments()` | a disc takes several steps — `CueToVcd` merges a split dump with chdman before cue2pops. Each command names its tool, its arguments and the file it reads (for the `/proc` progress fallback); the bar is shared between them. |
| `input(Disc, root, staging)` | the disc's own file | the tool needs something written first, such as the cue `CreateCdChd` writes for a bare image. Write it into `$staging`, never the library. |
| `progress(string $line)` | null | the tool prints a percentage when piped. Return null, and the runner reads the tool's read offset on the source from `/proc` instead. |
| `verifyFailed(string $output)` | false | the verifier exits 0 whatever it finds. nodtool prints `❌ (expected: …)` for a hash off and still exits 0, so `NodtoolConverter` looks for that. |
| `verifyArguments(string $output)` | null | the tool can check its own output. Verify runs only if `VERIFY` is also in `options()`. |
| `playlistEntry(outputs)` | the first output | a set's `.m3u` should list a different one of a disc's outputs. |

Rules the runner relies on:

- **Build argv, never a shell string.** Paths go in as given, and nothing is
  interpolated.
- **Write only to the output paths you are handed.** They live in the
  conversion's own staging folder. The runner moves them into the console's
  folder through `FileTransferJob`, which never writes over a file, and deletes
  the staging folder however the run ends.
- **Success is exit 0 plus every declared output present and non-empty.**
  Nothing else counts.

The size estimate beside each format is **not** the converter's job. The
runner records every finished conversion's source and output bytes in
`conversion_stats`, per converter and console, and the page estimates from
that ratio. It shows nothing until one conversion of that kind has finished.

## 2. Register it

In `config/converters.php`, under `converters`:

```php
'rvz' => IsoToRvz::class,
```

The order there is the order formats are offered in.

## 3. A new tool

Add the tool to `config/converters.php` → `tools`:

```php
'dolphin-tool' => [
    'label' => 'dolphin-tool',
    'path' => env('DOLPHIN_TOOL_PATH') ?: 'dolphin-tool', // bare name: looked for on PATH
    'version' => '2606',                                   // what the image pins
    'probe' => ['--version'],                              // arguments that make it print its version
    'pattern' => '/(\d{4}[a-z]?)/',                        // where the version is in stdout+stderr
    'licence' => 'GPL-2.0-or-later',
    // 'success_exit' => 1,  // only for a tool whose success is not exit 0 (cue2pops)
],
```

Then build it into the image. The runtime is `php:8.5-fpm-alpine`, which is
musl, so a glibc release binary will not run.

1. Add a step to the `converters` stage in **both** `Dockerfile.web` and
   `Dockerfile.web.dev`:
   - build from a pinned `ARG …_TAG`;
   - `install -m755` the binary into `/usr/local/bin`;
   - end with a smoke test that greps the tool's version output.
2. Add the binary to the `COPY --from=converters` line in both files.
3. Add a check to the `RUN` smoke test after that line.
4. Add any shared libraries it links to the runtime `apk add` list. Check with
   `ldd` in the stage.
5. Add the path variable to `.env.dev.example`, commented, and to `docs/configuration.md`.

A tool that is not found only turns its converters off. The page shows them
greyed out with "<tool> is not installed", and `conversion:tools` names them at
boot. Nothing crashes.

## 4. Consoles

A converter never names a console. Each console's own file says which
conversions it offers, the way it says which `layouts` it offers:

```php
// config/consoles/gc.php
'layouts' => ['custom'],
'default_layout' => 'custom',
'converters' => ['rvz', 'rvz-to-iso'],
```

The order there is the order the formats are listed on the page. Every
console file declares `converters`, most of them `[]`, and a test fails if one
does not or names a key `config/converters.php` does not register. List only
converters whose output that console's frontends read.

The output's extension must also be in the console's `file_extensions`, or
the scanner will never pick the new file up. ECM needed `ecm` added to `psx`
for this reason.

A console that lists no `chd-dvd` is taken to have only CDs: an .iso there is
a CD image for `chd-cd`, and a CHD there holds a CD, so it is not asked. That
is what lets a CD system such as Saturn get CHD by config alone.

## 5. Tests

Mirror `tests/Feature/Conversion/`:

- **`RegistryTest`**: which routes a source on each console gets, including a
  source the converter must *not* be offered. Tools are stand-in executables,
  pointed at through `config()->set('converters.tools.<tool>.path', …)`, so the
  test decides what is installed. If `supports()` runs a process, fake it.
- **`ConversionJobTest`**: `fakeTools()` writes whatever `-o` (or, for
  `ecm`/`unecm`, the last argument) names. A tool that writes its output
  elsewhere needs a branch there.
- **`RealConversionTest`**: one real round trip per tool, there and back,
  compared by md5. Call `requireTools()` so it skips where the tool is absent.

Run them in the dev container, as the web user:

```bash
docker compose -f docker-compose.dev.yml exec -T -u www-data retrobite-web php artisan test tests/Feature/Conversion
```

## Runtime notes

- **Queue.** Conversions run on the `toolbox-conversion` queue, over the `database-long`
  connection (`retry_after` 7200). Each has a time limit of `CONVERSION_TIMEOUT`,
  7000 seconds by default.
- **Concurrency.** `CONVERSION_CONCURRENCY` (default 1) is enforced with cache
  locks, one per slot. Raising it also needs `QUEUE_WORKERS_CONVERSION` raised to
  match.
- **Restart.** `conversion:recover` runs at boot, before the workers start. It
  marks any conversion still running as failed ("Interrupted"), removes its
  staging folder, and frees the slots. A job handed out again while its row
  says running is treated the same way.
- **Health check.** `conversion:tools` runs at boot and prints every tool's path
  and version, then warns about pin mismatches and converters that are off.
