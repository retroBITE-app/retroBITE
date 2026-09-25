<p align="center">
  <a href="https://github.com/mattiasghodsian/retroBite/">
    <img alt="retroBite" src="retroBite.png" height="150">
  </a>
  <p align="center">A clean, self-hosted collection manager for retro games. Pair your ROMs with rich metadata and artwork, then serve your library over the local network directly to your devices.</p>
</p>

## Features

- **SMB/CIFS** with SMBv1 compatibility for older consoles
- **FTP Server** with passive mode, confined to the library
- **Web Interface** for browsing, uploading, and managing your collections
- and more coming

## Disclaimer
retroBITE is intended for use with backups you have legally made from media you own. We do not endorse or condone piracy in any form. Only use this software with ROMs you have the legal right to possess.

## Project Background
retroBITE was born out of necessity. What started as a hunt for a Docker setup to network-load games onto personal consoles quickly grew when available projects fell short. It naturally evolved from a simple server script into a full collection management hub.

## Built With
- **Samba** - SMB/CIFS file sharing
- **vsftpd** - FTP server
- **Debian 12 (bookworm-slim)** - Share container base image
- **PHP 8.5 + Laravel 13** - Web interface backend
- **Livewire 4 + Flux** - Web interface frontend
- **Tailwind CSS 4** - Styling, built with Vite
- **SQLite** - Database

## Deployment & Usage
retroBITE is designed to run exclusively as a Docker container. To ensure stability and ease of use, we do not provide support or assistance for manual installations outside of Docker.

### Quick Start

1. Clone this repository.

2. Copy `.env.example` to `.env` and adjust the settings to match your local setup.

   At minimum set `AUTH_USER` / `AUTH_PASS` (the SMB and FTP account) and
   `HOST_IP` (your machine's LAN address — FTP passive mode advertises it, so
   `localhost` will not work for consoles).

3. Start the stack.

```bash
./retrobite up
```

   This builds the images, starts the stack and waits until retroBITE answers.
   Nothing needs installing on your machine first: the image installs its PHP
   and JavaScript dependencies and builds its assets while it is built.

   `./retrobite` is the one command for the containers — `./retrobite help`
   lists the rest: `down`, `restart`, `logs`, `artisan …`, `composer …`,
   `shell`.

4. Connect your retro console to the network share to load ROMs directly over your local network.

5. Access the web interface at http://localhost (or `WEB_PORT` from your `.env`)

   The first visit opens a four-step setup:

   1. Create the account you will sign in with.
   2. Pick how retroBITE looks — color scheme, CRT scanlines, how a game's page
      is headed and whether ROM uploads are offered.
   3. Enter your ScreenScraper account, which identifies games and fetches
      artwork ([free sign-up](https://www.screenscraper.fr/membreinscription.php)).
   4. Optionally, your RetroAchievements username and web API key.

   All of it can be changed later under Settings.

   There is no default login. Until the account exists, whoever reaches the
   page first creates it, so finish setup before putting retroBITE on a network
   you do not control.

   The SMB and FTP account is separate, and set by `AUTH_USER` / `AUTH_PASS`.

### Forgot your password?

There is no reset-by-email flow — retroBITE is local-first and assumes no mail
server. Reset from the machine running it instead:

```bash
./retrobite artisan user:password <username>
```

The argument accepts a username or an email address, and falls back to a partial
match. Omit it to be prompted. Omit `--password` and the new password is asked
for without echoing.

### Adding users

Beyond the first account made during setup, there is no sign-up page. Create more from the box:

```bash
./retrobite artisan user:create retrogamer
./retrobite artisan user:create retrogamer --email=me@example.com --name="Player One"
```

The email defaults to `<username>@retrobite.local` and the display name to the
username. New accounts are marked verified on creation, since there is no mail
server to verify through.

### Local development

Bind-mounts the source, so PHP changes take effect without a rebuild:

```bash
./retrobite up --dev
```

The dev container installs its own dependencies when it starts — Composer when
`composer.lock` has changed since the last install, npm when `package-lock.json`
has — so after pulling a branch, `./retrobite up --dev` (or a restart) is all it
takes. `./retrobite test` runs Pint, PHPStan and the suite.

The development stack runs everything in the web container — PHP-FPM, nginx,
the queue workers and the Vite dev server — so nothing has to be installed on
the host to work on this.

Vite listens on **port 1337** (fixed in `vite.config.js` with `strictPort`, so a
clash fails loudly rather than drifting to another port). Starting it writes
`public/hot`, which Laravel reads to point asset URLs at the dev server instead
of the built bundle; stop the container and the built bundle takes over again.

The container keeps its own `node_modules` in a volume rather than sharing the
host's through the bind mount: the native build tools (Tailwind's oxide,
lightningcss, Rolldown) are compiled per platform, and one tree cannot serve
both macOS and the container's Alpine Linux. So node runs **only in the
container** — use `./retrobite npm` instead of `npm` on the host:

```bash
./retrobite npm install some-pkg   # add a package
./retrobite npm run build          # a production build of the assets
```

On start the container installs again whenever `package-lock.json` has changed
since its last install, so pulling a branch that adds a package is enough.

Queue workers run under `queue:listen` here, not `queue:work`. `work` keeps one
booted application in memory for its whole life, so a job runs whatever the code
was when the worker started — edit a job class and the container keeps running
the old one with nothing to say so. `listen` boots a fresh application per job.

The entrypoint scripts are copied into the image rather than bind-mounted, so
changing one needs `--build`; a plain restart runs the old script.

The two stacks are separate Compose projects and both publish port 81, so they
are alternatives rather than companions — bring one down before starting the
other.

### Where things live

| Path | Purpose |
| --- | --- |
| `storage/app/games` | The ROM library. Bind-mounted into both containers; exported as `/games` over SMB and FTP. |
| `retrobite-data` volume | The SQLite database, mounted at `/data`. Kept out of the image so it survives `docker compose down`. |

ROMs are not served directly over HTTP — the `games` disk sits outside
`storage/app/public` on purpose, so downloads go through an authenticated route.

## Credits
- [Libretro](https://github.com/libretro/retroarch-assets/tree/master/xmb/retrosystem/png): Sourced console iconography.
- AI/LLM Tools: Assisted in the creation of original project assets.
- Our Contributors: Made with ❤️ by the community.