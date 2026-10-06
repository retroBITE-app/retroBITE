# retroBITE

A self-hosted collection manager for retro games. Point it at your ROM
folders, and retroBITE:

- identifies what is in them against ScreenScraper
- fetches metadata, artwork and RetroAchievements
- serves the library to real consoles over SMB and FTP

This is the **web interface**. It runs beside `mariadb:11.4` and
[`retrobite/share`](https://hub.docker.com/r/retrobite/share), the SMB and FTP
server, all from one compose file.

## Install

```bash
mkdir retrobite && cd retrobite
curl -LO https://github.com/retroBITE-app/retroBITE/releases/latest/download/docker-compose.yml
curl -L -o .env https://github.com/retroBITE-app/retroBITE/releases/latest/download/.env.example
# change the passwords and GAMES_PATH in .env
docker compose up -d
```

Then open `http://<your machine>:81`. There is no key to generate: it makes
its own on the first start.

- [Installing](https://github.com/retroBITE-app/retroBITE/blob/develop/docs/installing.md):
  Portainer, Dockhand, Synology, QNAP, Unraid, TrueNAS, Docker Desktop
- [Every setting](https://github.com/retroBITE-app/retroBITE/blob/develop/docs/configuration.md)

## Tags

| Tag | What |
| --- | --- |
| `latest` | The newest release. |
| `develop` | The newest pre-release. |
| `20260930`, `20261004-BETA`, … | One version, to stay on. |

`linux/amd64` and `linux/arm64`.

## Volumes

| Path in the container | What |
| --- | --- |
| `/app/storage/app/games` | The ROM library, shared with `retrobite/share`. |
| `/app/storage/state` | The application key. Back it up with the database. |
| `/app/storage/app/media` | Downloaded artwork. |
| `/app/storage/app/docs` | The knowledge base. |

Source and issues: https://github.com/retroBITE-app/retroBITE
