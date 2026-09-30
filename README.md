<p align="center">
  <a href="https://github.com/retroBITE-app/retroBITE/">
    <img alt="retroBite" src="retroBITE.png" width="400">
  </a>
  <p align="center">A clean, self-hosted collection manager for retro games. <br>Pair your ROMs with rich metadata and artwork, then serve your library over the local network directly to your devices.</p>
</p>

<p align="center">
  <a href="https://github.com/retroBITE-app/retroBITE/stargazers" target="_new">
    <img alt="GitHub Repo stars" src="https://img.shields.io/github/stars/retroBITE-app/retroBITE?style=for-the-badge&logo=github&label=Stars&color=e8b44a">
  </a>
  <a href="https://github.com/retroBITE-app/retroBITE/network/members" target="_new">
    <img alt="GitHub forks" src="https://img.shields.io/github/forks/retroBITE-app/retroBITE?style=for-the-badge&logo=github&label=Forks&color=e8b44a">
  </a>
  <br>
  <a href="https://github.com/retroBITE-app/retroBITE/actions/workflows/docker.yml" target="_new">
    <img alt="Docker build" src="https://img.shields.io/github/actions/workflow/status/retroBITE-app/retroBITE/docker.yml?style=for-the-badge&logo=docker&label=Docker&color=e8b44a">
  </a>
  <a href="https://github.com/retroBITE-app/retroBITE/actions/workflows/tests.yml" target="_new">
    <img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/retroBITE-app/retroBITE/tests.yml?style=for-the-badge&logo=github&label=Tests&color=e8b44a">
  </a>
  <br>
  <a href="https://github.com/retroBITE-app/retroBITE/releases" target="_new">
    <img alt="Latest Release" src="https://img.shields.io/github/v/release/retroBITE-app/retroBITE?include_prereleases&sort=date&style=for-the-badge&logo=github&label=Latest%20Release&color=e8b44a">
  </a>
  <a href="https://hub.docker.com/r/retrobite/retrobite" target="_new">
    <img alt="Web image pulls" src="https://img.shields.io/docker/pulls/retrobite/retrobite?style=for-the-badge&logo=docker&label=Web%20Pulls&color=e8b44a">
  </a>
  <a href="https://hub.docker.com/r/retrobite/share" target="_new">
    <img alt="Share image pulls" src="https://img.shields.io/docker/pulls/retrobite/share?style=for-the-badge&logo=docker&label=Share%20Pulls&color=e8b44a">
  </a>
</p>

## Features

- **Serve your library to consoles** over SMB (with SMBv1 for older consoles)
  and FTP with passive mode, confined to the library.
- **Browse and manage it on the web**: every console on its own shelf, with
  uploads, folders and the layout your loader expects — keep your own
  arrangement, or use game folders for disc consoles, RetroArch or Open PS2
  Loader.
- **Metadata and artwork from ScreenScraper**: titles, descriptions, covers,
  screenshots and more, matched by hash or by name.
- **RetroAchievements**: see which games have a set and how far you are.
- **Tools → Conversion**: shrink and convert disc images — CHD, CSO/ZSO, ECM,
  VCD for POPStarter, RVZ and WBFS for GameCube and Wii — in a queue, one game
  or a hundred at a time.
- **Send to** a USB drive or a network share, laid out for Batocera or Open PS2
  Loader, covers and all.

## Disclaimer

retroBITE is intended for use with backups you have legally made from media you
own. We do not endorse or condone piracy in any form. Only use this software
with ROMs you have the legal right to possess.

## Project Background

retroBITE was born out of necessity. What started as a hunt for a Docker setup
to network-load games onto personal consoles quickly grew when available
projects fell short. It naturally evolved from a simple server script into a
full collection management hub.

## Getting started

retroBITE runs only in Docker. We do not support installing it any other way.

### With the Docker Hub images

1. **Make a folder for retroBITE** and move into it.

   ```bash
   mkdir retrobite && cd retrobite
   ```

2. **Create `docker-compose.yml`** in it:

   ```yaml
   services:
     retrobite-web:
       image: retrobite/retrobite:latest
       container_name: retrobite-web
       env_file: .env
       environment:
         DB_CONNECTION: mariadb
         DB_HOST: retrobite-db
         DB_PORT: 3306
         TRANSFER_LOCALHOST_URL: http://localhost:81
         SERVE_WITH_NGINX: "true"
       ports:
         - "81:80"
       volumes:
         - ${GAMES_PATH:-./games}:/app/storage/app/games
         - ${DOCS_PATH:-./docs}:/app/storage/app/docs
         - retrobite-media:/app/storage/app/media
       depends_on:
         retrobite-db:
           condition: service_healthy
       restart: unless-stopped

     retrobite-db:
       image: mariadb:11.4
       container_name: retrobite-mariadb
       environment:
         MARIADB_DATABASE: ${DB_DATABASE:-retrobite}
         MARIADB_USER: ${DB_USERNAME:-retrobite}
         MARIADB_PASSWORD: ${DB_PASSWORD:-retrobite}
         MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-retrobite}
       volumes:
         - retrobite-db:/var/lib/mysql
       healthcheck:
         test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
         interval: 5s
         timeout: 5s
         retries: 20
         start_period: 30s
       restart: unless-stopped

     retrobite-share:
       image: retrobite/share:latest
       container_name: retrobite-share
       env_file: .env
       network_mode: host
       volumes:
         - ${GAMES_PATH:-./games}:/games
       cap_add:
         - NET_ADMIN
         - SYS_ADMIN
       init: true
       restart: unless-stopped

   volumes:
     retrobite-db:
     retrobite-media:
   ```

   `latest` is the newest release. Use `develop` for the newest pre-release, or
   a date tag such as `20260930` to stay on one version.

3. **Create `.env`** from the example, and give it an application key:

   ```bash
   curl -o .env https://raw.githubusercontent.com/retroBITE-app/retroBITE/develop/.env.example
   key=$(docker run --rm --entrypoint php retrobite/retrobite:latest artisan key:generate --show)
   sed -i.bak "s|^APP_KEY=.*|APP_KEY=$key|" .env && rm .env.bak
   ```

   Then set at least:

   | Setting | What it is |
   | --- | --- |
   | `AUTH_USER` / `AUTH_PASS` | The account consoles use for SMB and FTP. **Change the password.** |
   | `HOST_IP` | This machine's LAN address. FTP passive mode hands it to consoles, so `localhost` will not do. |
   | `GAMES_PATH` | Where your library is on this machine. Defaults to `./games`. |
   | `DB_PASSWORD` / `DB_ROOT_PASSWORD` | The database's passwords. Change them before the first start: they are set when the database is created. |

4. **Start it.**

   ```bash
   docker compose up -d
   ```

5. Carry on with [First run](#first-run). Open http://localhost:81** and follow the four-step setup.


## Where your data lives

| Where | What |
| --- | --- |
| `GAMES_PATH` (default `./games`) | Your ROM library, one folder per console. Shared as `/games` over SMB and FTP. |
| `retrobite-db` volume | The MariaDB database: your games, their metadata, your settings. |
| `retrobite-media` volume | The artwork and media retroBITE downloads. |

The volumes survive `docker compose down` and updates. ROMs are never served
straight over the web: downloads go through a signed-in route only.

## Built With

- **Samba** and **vsftpd** — SMB and FTP
- **Debian 12 (bookworm-slim)** — the share container
- **PHP 8.5 + Laravel 13** — the web interface
- **Livewire 4 + Flux** and **Tailwind CSS 4** — its frontend, built with Vite
- **MariaDB 11.4** — the database

## Contributing

Pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first: it
covers the development setup, the tests, the code style, commit messages and
how releases are published.

## Credits

- [Libretro](https://github.com/libretro/retroarch-assets/tree/master/xmb/retrosystem/png): Sourced console iconography.
- AI/LLM Tools: Assisted in the creation of original project assets.
- Our Contributors: Made with ❤️ by the community.
