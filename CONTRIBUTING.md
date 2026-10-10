# Contributing to retroBITE

Thanks for helping. This file is everything about working on the code: the
rules a change has to follow, how to run the project for development, how the
code is written, and how images are published.

To work on it, one command starts the whole development stack, with the source
mounted so your changes show up as you make them:

```bash
./retrobite up --dev
```

See [Development setup](#development-setup) for the rest.

## Ground rules

Read these before you open a pull request. A change that breaks one of them
will be asked to change, however good it is otherwise.

1. **Follow Laravel's fundamentals.** Use what the framework already gives you
   before writing your own: Eloquent for data, the container for services,
   queued jobs for slow work, `Storage` disks for files, `Http::` for outbound
   requests, `Arr::` / `Str::` / collections instead of raw PHP helpers, form
   requests and validation rules, `__()` for text, `route()` for URLs. If
   Laravel has a way to do it, that is the way.
2. **No wrapper packages — go to the source.** Depend on the upstream project
   itself, never on a package that wraps it. The conversion tools show how:
   chdman, maxcso, ecm, cue2pops, nodtool and extract-xiso are built from their
   own pinned source tags in the `converters` stage of the web Dockerfiles, and
   the app calls the binaries directly — no Composer package that shells out to
   them, no image that bundles them. The same goes for PHP and JavaScript
   libraries: take the library, not somebody's adapter around it.
3. **Proper semantic commit messages.** [Conventional
   Commits](https://www.conventionalcommits.org/), one line, and **no scope in
   parentheses**: `feat(api)!: …` or `feat(api): …` is not allowed — write
   `feat: …`. See [Commit messages](#commit-messages).
4. **Follow how the project works, and its code style.** Read the code around
   your change and write like it. The next reader should not be able to tell
   which lines are yours. See [Code style](#code-style).
5. **A short, proper PHP docblock on every function.** One or two lines that
   say *why* the function exists or what it guarantees, not what the signature
   already says. See [Docblocks](#docblocks).

## Development setup

retroBITE runs only in Docker, in development too. Nothing has to be installed
on the host: PHP, Composer and Node all run inside the web container.

```bash
cp .env.dev.example .env
./retrobite up --dev
```

The dev stack bind-mounts the source, so PHP changes take effect without a
rebuild. It answers on **http://localhost:82** (production uses 81).

`./retrobite` is the one command for the containers. With `--dev`, or whenever
the dev stack is the one running, it talks to that:

```bash
./retrobite artisan migrate          # php artisan, as the web user
./retrobite composer require x/y     # composer
./retrobite npm install some-pkg     # npm (dev stack only)
./retrobite test                     # Pint, PHPStan and the suite
./retrobite shell                    # a shell in the web container
./retrobite logs -f                  # the web container's log
```

Commands run as the web user, so what they write — caches, compiled views,
`vendor`, `node_modules` — is owned the way the application expects.

### What the dev container does for you

- **Dependencies install themselves.** On start the container runs Composer
  when `composer.lock` has changed since its last install, and npm when
  `package-lock.json` has. After pulling a branch, `./retrobite up --dev` (or a
  restart) is all it takes.
- **Everything runs in one container**: PHP-FPM, nginx, the queue workers and
  the Vite dev server.
- **Vite listens on port 1337**, fixed with `strictPort` in `vite.config.js`,
  so a clash fails loudly instead of drifting to another port. It writes
  `public/hot`, which points asset URLs at the dev server; stop it and the
  built bundle takes over again.
- **Node runs only in the container.** It keeps its own `node_modules` in a
  volume, because the native build tools (Tailwind's oxide, lightningcss,
  Rolldown) are compiled per platform and one tree cannot serve both your host
  and the container's Alpine Linux. Use `./retrobite npm`, never `npm` on the
  host.
- **Queue workers run under `queue:listen`, not `queue:work`.** `work` keeps
  one booted application for its whole life, so an edited job class would keep
  running the old code with nothing to say so. `listen` boots a fresh
  application per job.

### Things that catch people out

- **The entrypoint scripts are copied into the image**, not bind-mounted.
  Changing `docker/web/entrypoint.sh` or `entrypoint.dev.sh` needs
  `./retrobite up --dev` again, which rebuilds; a plain restart runs the old
  script.
- **Run one stack at a time.** Both share containers use host networking and
  publish SMB and FTP on the same ports, so bring one stack down before
  starting the other.
- **Compiled Blade is not shared with the host.** Livewire bakes an absolute
  path into each compiled component, so the dev compose file keeps
  `storage/framework/views/livewire` on a volume of its own. Leave it there.
- **Migrations only go forward.** Installs in the wild have already run what is
  committed, and the entrypoint migrates on every boot. Add a migration; never
  edit one that has been merged.

## Everyday use

`./retrobite` talks to whichever stack is running:

```bash
./retrobite up --dev    # start the dev stack, or rebuild it
./retrobite down        # stop
./retrobite restart     # restart the web container
./retrobite logs -f     # follow the web container's log
./retrobite help        # everything else
```

### Forgot your password?

There is no reset by email — retroBITE assumes no mail server. Reset it from
the machine running retroBITE instead:

```bash
./retrobite artisan user:password <username>
```

The argument takes a username or an email address, and falls back to a partial
match. Leave it out to be asked. The new password is asked for without echoing.

### Adding users

Beyond the account made during setup there is no sign-up page. Add more from
the machine running retroBITE:

```bash
./retrobite artisan user:create retrogamer
./retrobite artisan user:create retrogamer --email=me@example.com --name="Player One"
```

The email defaults to `<username>@retrobite.local` and the display name to the
username. New accounts are verified on creation, since there is no mail server
to verify through.

The SMB and FTP account is separate from these, and set by `AUTH_USER` /
`AUTH_PASS` in `.env`.

## Tests

```bash
./retrobite test
```

That runs Pint (`--test`), PHPStan at level 7 and the Pest suite inside the
container, the same checks CI runs. Every change comes with tests: a feature
test for anything a person or a job does, a unit test for pure logic.

- `tests/Feature` refreshes the database for every test; `tests/Unit` does not
  boot the application at all.
- Test what a change *does*, not how it is built: a page shows this, a job
  writes that, a request is refused.
- Pest helpers are global functions, shared by every test file. Give yours a
  name nobody else will pick (`convertibleGameOn`, not `gameWithFiles`).

## Code style

Pint and PHPStan catch the mechanical part. The rest is on you and the
reviewer.

### PHP

- `declare(strict_types=1)` in domain code (`app/Support`, `app/Services`,
  `app/Enums`, `app/Transfers`, `app/Conversion`, …). Match the file you are in.
- **An explicit return type on every function**, `: void` included.
- **No arrow functions.** `fn () => …` is not used anywhere. Write a braced
  `function () { return …; }` with a return type, even for one expression.
- **`Arr::` instead of brackets** for reading arrays — `Arr::get($payload,
  'data.title')`, not `$payload['data']['title'] ?? null`. Brackets stay for
  building arrays.
- **`Str::` instead of `strpos` / `substr` / `preg_*`**, and collections
  (`collect()->map()->filter()`) where they read more clearly than a loop.
- camelCase in PHP; snake_case for database columns, JSON keys and config keys.
- **Guard clauses first**, one job per function, private helpers below the
  public methods they serve.
- **Enums only for closed vocabularies.** Anything a person can change at
  runtime — a console, a layout — is config read through a value object such
  as `App\Support\Console`.
- **Exceptions carry structured data**, not messages a caller has to parse. An
  exception message never reaches the browser: log it, show a fixed string.
- **Services are nouns with verb methods**, resolved from the container.
- **Library files go through the gate.** Every write, move or delete under the
  games folder goes through `App\Support\LibraryPath`, never a bare `unlink` or
  `rename`.

### Docblocks

Every function gets one: one or two lines on why it exists, what it
guarantees, or what it refuses. Add `@param` / `@return` / `@throws` only where
the type cannot say it — an array shape, a unit, a contract.

```php
/** The first tool a converter needs that is not here, or null when all are. */
public static function missing(Converter $converter): ?string

/**
 * Every version of the game, each a list of its present files, root first.
 *
 * @return list<list<GameFile>>
 */
public static function of(Game $game): array
```

Not this:

```php
/**
 * Get the serial for the game.
 *
 * @param  Game  $game  The game
 * @return string|null The serial
 */
```

### Frontend

- **Flux components first** (`flux:button`, `flux:select`, `flux:modal`, …).
  Hand-written markup only where Flux has nothing.
- **Livewire components are single-file**: the class in the `<?php ?>` block at
  the top of `resources/views/livewire/**.blade.php`.
- **Design tokens only.** Colours come from `resources/css/app.css` (`fg-soft`,
  `accent`, `line-input`, …) — never `zinc-*`, `gray-*` or any raw palette
  colour. There is no light theme.
- **Every string in `__()`, every URL from `route()`.**
- **Fonts and assets are self-hosted.** The production CSP blocks outside
  origins, so a CDN link that works under `npm run dev` breaks in Docker.

### Where to look

- `docs/adr/` — the decisions behind live updates, transfers and moving files.
- `docs/conversion/adding-a-converter.md` — adding a format conversion.
- `config/consoles.php` — every console. Adding one also needs a share block in
  `docker/smb.conf` and a `mkdir` in `docker/entrypoint.sh`.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/), one line, in the
imperative:

```
<type>: <what the change does>
```

No scope in parentheses — `feat(api)!: …` or `fix(transfers): …` is not
allowed. The type and the message say it.

| Type | For |
| --- | --- |
| `feat` | Something a person can now do |
| `fix` | Something that was broken and now is not |
| `perf` | Faster, doing the same thing |
| `refactor` | The same behaviour, different code |
| `test` | Tests only |
| `docs` | Documentation only |
| `ci` | Workflows and the build script |
| `chore` | Dependencies, tooling, housekeeping |

From this repository's history:

```
feat: add game folders layout for disc consoles
fix: read PS2 SYSTEM.CNF via ISO9660 directory instead of scanning the disc
ci: build and push web and share images to Docker Hub on GitHub releases
```

One logical change per commit. Say what it does, not how you got there — no
"wip", no "fix stuff", no "address review".

## Pull requests

- Branch from `develop`, and open the pull request against `develop`.
- Keep it to one feature or one fix. A refactor that the change needs can come
  along; one it does not goes in its own pull request.
- Run `./retrobite test` before you push, and say in the description what you
  tried by hand — which console, which browser, which share.
- Never commit `.env`. It is gitignored for a reason.

## Releases and Docker images

Both containers are published to Docker Hub, for `linux/amd64` and
`linux/arm64`:

| Image | Container |
| --- | --- |
| `retrobite/retrobite` | The web UI (`Dockerfile.web`) |
| `retrobite/share` | SMB and FTP (`Dockerfile`) |

Versions are dates. A release's tag is the version, and whether it is a
pre-release picks the channel, never the suffix:

| GitHub release | Tags pushed |
| --- | --- |
| Release `20260930` | `:20260930`, `:latest` |
| Pre-release `20260930-ALPHA` (or `-PREALPHA`) | `:20260930-ALPHA`, `:develop` |

Releases are built and pushed by a founder from their own Mac with `./build`.
That takes about ten minutes on Apple silicon (arm64 natively, amd64 through
Rosetta).

Every push to `develop` is also built by `.github/workflows/images.yml`: each
architecture on a GitHub runner of its own (no emulation), pushed to GHCR,
started on both architectures, and copied unchanged to Docker Hub as
`:develop` and `:develop-<short sha>` once someone approves the `dockerhub`
environment.

### Publishing a release

You need, once:

- Docker with buildx: OrbStack or Docker Desktop, with Rosetta on for amd64
- `docker login` as an account that can push to `retrobite/retrobite` and
  `retrobite/share`
- `gh auth login`, for the GitHub release
- `jq`, which `./build` reads the build's metadata with

Then, for each release:

1. **Start from a clean, tested `develop`.** `./build` builds what is
   committed, never the working tree.

   ```bash
   git switch develop && git pull
   git status                      # nothing to commit
   ./retrobite up --dev && ./retrobite test
   ```

2. **Tag it with the version.** A version is today's date; a pre-release adds
   a suffix.

   ```bash
   git tag 20261010                # a release
   git tag 20261010-BETA           # or a pre-release
   git push origin develop --tags
   ```

3. **Build, test and push both images.** The version is read off the tag.

   ```bash
   ./build master                  # a release: :20261010 and :latest
   ./build develop                 # a pre-release: :20261010-BETA and :develop
   ```

   Answer `y` to push. `./build` first builds the web image for this Mac and
   starts it with `./smoke`, the way an install starts it. **Nothing is pushed
   unless it starts**: it must come up healthy, serve its first page, generate
   its application key and keep that key when recreated. Then both images are
   built for amd64 and arm64 and pushed.

4. **Check what Docker Hub has.** Both platforms should be listed under each
   tag.

   ```bash
   docker buildx imagetools inspect retrobite/retrobite:20261010
   docker buildx imagetools inspect retrobite/share:20261010
   ```

5. **Publish the release on GitHub,** from the same tag.

   ```bash
   gh release create 20261010 --title 20261010 --notes ""
   gh release create 20261010-BETA --title 20261010-BETA --notes "" --prerelease
   ```

   Publishing it runs two workflows:

   - **changelog.yml** writes the notes from the commits since the previous
     tag. Anything typed in `--notes` is kept above them.
   - **release-files.yml** does two things:
     - It attaches `docker-compose.yml`, `docker-compose.macvlan.yml` and
       `.env.example`, the last as `env.example` because GitHub renames an
       asset whose name starts with a dot. The attached compose file defaults to that release's
       version, so anyone can install one version and stay on it.
       [docs/installing.md](docs/installing.md) and the site fetch the
       `develop` branch's files instead. Either way these files *are* the
       installation, and a change to them belongs in the notes.
     - For a release, not a pre-release, it updates the Docker Hub pages from
       `docs/dockerhub/`. This needs `DOCKERHUB_TOKEN` to have Read, Write and
       Delete access.

6. **Check it as an installer would**, on another machine or in an empty
   folder:

   ```bash
   curl -LO https://github.com/retroBITE-app/retroBITE/releases/download/20261010/docker-compose.yml
   docker compose up -d                # runs exactly the version just released
   ```

`./build` also works outside a release:

```bash
./build                            # this Mac only, loaded: :develop, :develop-<commit>
./smoke develop                    # start that as an install would
./build --only web                 # one of the two images
./build master --version 20261010  # a version without its tag on this commit
./build --no-smoke                 # push without starting the web image first
```

**The first full release** creates `:latest`. Until then, every compose file
defaults to `develop`, because `:latest` does not exist. Once `:latest` is on
Docker Hub, change the default to `latest` in two places:

- `${RETROBITE_TAG:-develop}` in `docker-compose.yml` and in `release-files.yml`'s `sed`
- the site's `docs/public/docker-compose.yml`

A new environment variable goes in three places:

- in `docs/configuration.md`, always
- in `.env.example`, when most installs should set it
- under `environment:` in `docker-compose.yml`, when a container manager's
  variable editor must reach it

**Contributors never push images.** Publishing to Docker Hub is done only by
the founders, as above.
When the script asks whether to push, answer no: it builds for your machine
only and loads the images locally, which is all you need to try a change to a
Dockerfile.
