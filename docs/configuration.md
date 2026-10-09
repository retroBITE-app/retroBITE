# Configuration

Every setting retroBITE reads from its environment, what it does, and when it
is worth changing. Most installs need only the first table.

You can set them in three ways, and they all end up in the same place:

- **In `.env` beside `docker-compose.yml`.** Start from
  [`.env.example`](../.env.example). Compose reads the file for the variables
  in the compose file, and both containers load the whole file, so anything
  listed here works there.
- **In your container manager's variables**: Portainer's *Environment
  variables*, Dockhand's environment editor, Synology's, Unraid's or
  TrueNAS's. These fill in the `${VARIABLE}`s in `docker-compose.yml`. They
  reach every setting in the first two tables, and every other setting the
  compose file passes on. For anything else, add a line under the service's
  `environment:` in the compose file, or use a `.env` file.
- **Under `environment:`** in the compose file itself.

The containers say at start, in their log, when a setting looks wrong:

- a default password
- debug left on
- no `HOST_IP`

Settings that are not about the installation, such as your ScreenScraper and
RetroAchievements accounts, the interface and the regions, are made in the web
interface under Settings, and kept in the database.

## The ones to set

| Variable | Default | What it is |
| --- | --- | --- |
| `AUTH_USER` / `AUTH_PASS` | `retrobite` / `retrobite` | The one account consoles use for SMB and FTP. **Change the password**: anyone on the network who knows it can read and write the library. |
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` | `retrobite` | The database's passwords. MariaDB reads them **only when the database is first created**, so set them before the first start. Changing them afterwards means changing them inside MariaDB too. |
| `GAMES_PATH` | `./games` | Where the ROM library is on the host, one folder per console. It is mounted into both containers and shared as `/games`. The containers run as whoever owns this folder, so files written over SMB, FTP or the web stay yours. |
| `HOST_IP` | empty | This machine's LAN address. It is shown as the address consoles connect to, it is what the dashboard checks SMB and FTP against, and it decides which network Settings → Destinations searches. The share container works it out when this is empty; the web container cannot, from behind Docker's bridge. |
| `APP_TIMEZONE` | `UTC` | A PHP zone name such as `Europe/Stockholm`. It sets the times shown and when the nightly jobs run. Timestamps are stored without an offset, so changing it later shifts the dates already written. |
| `WEB_PORT` | `81` | The host port the web interface answers on. |
| `RETROBITE_TAG` | `develop` | The image tag to run. `develop` is the newest pre-release, and a version such as `20261006-BETA` stays on that version. `latest`, the newest full release, exists from the first one on. A compose file attached to a release defaults to that release's version instead. |

## Where data is kept

All but the docs default to a named Docker volume. Point one at a folder to keep
it with the rest of a NAS's backed-up shares. Volumes and folders survive
`docker compose down` and updates alike.

| Variable | Default | What is in it |
| --- | --- | --- |
| `DB_PATH` | volume `retrobite-db` | The MariaDB database: your games, their metadata, your settings and accounts. |
| `MEDIA_PATH` | volume `retrobite-media` | Artwork retroBITE downloaded. It can be deleted and fetched again. |
| `STATE_PATH` | volume `retrobite-state` | The application key and the live-update keys. **Back it up with the database**: the stored passwords for ScreenScraper, RetroAchievements and destinations cannot be read without the key. |
| `DOCS_PATH` | `./docs` | The markdown knowledge base, a folder beside the compose file. |

## Keys

| Variable | Default | What it is |
| --- | --- | --- |
| `APP_KEY` | generated | Encrypts the passwords retroBITE stores. When it is empty, a key is generated on the first start and kept in `STATE_PATH`. Set it only to carry over a key you already have, such as from an install that had one in `.env`. |
| `APP_PREVIOUS_KEYS` | empty | Former keys, comma-separated, still accepted for reading while values move to the new one. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | generated | The keys for live updates. They are generated into `STATE_PATH` when unset. |

## Network shares and the share container

| Variable | Default | What it is |
| --- | --- | --- |
| `GAME_FOLDERS` | `ps2 ps3 gc wii xbox dreamcast` | The console folders created in the library on start. |
| `SMB_SHARES` | `ps2 gc wii` | Folders that also get an SMB share of their own. Open PS2 Loader, for one, connects to a share named after the console. |
| `PS3NETSRV_FOLDERS` | `PS3ISO=ps3 PS2ISO=ps2 PSXISO=psx` | Which of webMAN MOD's lists ps3netsrv serves, and the library folder behind each. Set on both containers. Add `PSPISO=psp` for PSP games; a moved console folder goes here too. Only webMAN's own names (`PS3ISO`, `PS2ISO`, `PSXISO`, `PSPISO`, `BDISO`, `DVDISO`, `GAMES`, `PKG`) and plain folder names are taken. |
| `PS3NETSRV` | `true` | Runs ps3netsrv on port 38008, serving the folders in `PS3NETSRV_FOLDERS` to webMAN MOD, read-only: it can list and stream, never create, change or delete. Set on both containers: the web one shows its status card from it. `false` turns it off. |
| `PS3NETSRV_WHITELIST` | none | Who may connect to ps3netsrv, such as `192.168.1.*` or one PS3's address. It has no login of its own, so anyone else on the network can read those folders through it. |
| `SHARE_IP`, `SHARE_SUBNET`, `SHARE_GATEWAY`, `SHARE_PARENT` | none | Only with `docker-compose.macvlan.yml`. They give the share container its own LAN address; see [installing.md](installing.md#when-ports-445-139-and-21-are-taken). |
| `SHARE_HOST` | `HOST_IP` | Where the web container checks SMB, FTP and ps3netsrv. The macvlan file sets it; nothing else needs to. |

## Web and reverse proxies

| Variable | Default | What it is |
| --- | --- | --- |
| `APP_URL` | `http://localhost:<WEB_PORT>` | The address retroBITE is reached at. Set it when it is behind a reverse proxy on a name of its own. |
| `TRUSTED_PROXIES` | none | Proxies whose `X-Forwarded-*` headers are believed: comma-separated, or `*`. A proxy that serves https needs this, or links come out as `http://` and the browser blocks them. |
| `TRANSFER_LOCALHOST_URL` | `http://localhost:<WEB_PORT>` | Send to → USB drive writes from the browser, which Chrome and Edge allow only on https or `localhost`. A page opened another way links here. |
| `TRANSFER_DISCOVERY_NAMES` | `batocera,recalbox,retropie` | Names Settings → Destinations looks up by NetBIOS and DNS. |
| `TRANSFER_DISCOVERY_SUBNETS` | the network `HOST_IP` is on | Networks to scan for SMB shares, such as `192.168.1.0/24`. |
| `APP_ENV` | `production` | Leave it as it is. `local` is for development and trusts every proxy. |
| `APP_DEBUG` | `false` | Shows full error pages. Turn it on only to diagnose a problem, and off again afterwards. |
| `LOG_LEVEL` | `warning` | `debug`, `info`, `warning` or `error`. The log is in the web container's output and `storage/logs`. |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before signing out. |

## Queue workers

Background work is done by workers, one per queue unless set; `0` starts none.
Raise them on a machine with the room.

| Variable | Default | Work |
| --- | --- | --- |
| `QUEUE_WORKERS_SCRAPER` | 1 | Identification and ratings. Raising it adds speed up to your ScreenScraper account's threads (1 on a free account, more on a paid one); past that, workers wait. |
| `QUEUE_WORKERS_MEDIA` | 1 | Artwork downloads. They share the scraper's threads. |
| `QUEUE_WORKERS_DEFAULT` | 1 | Scans and file counts. |
| `QUEUE_WORKERS_THUMBNAILS` | 1 | Cover thumbnails, CPU bound. |
| `QUEUE_WORKERS_TOOLBOX` | 1 | Loader exports and licence ID reading. |
| `QUEUE_WORKERS_CONVERSION` | 1 | Tools → Conversion, one disc at a time. |
| `QUEUE_WORKERS_RA` | 1 | RetroAchievements identification and sets. |
| `QUEUE_WORKERS_RA_PROGRESS` | 1 | RetroAchievements unlocks. |
| `QUEUE_WORKERS_HASH` | 1 | Checksums and RetroAchievements hashes, disk bound. |
| `QUEUE_WORKERS_TRANSFER` | 3 | Copies to network shares. |

## ScreenScraper

Your own ScreenScraper account goes in Settings → ScreenScraper, not here.

| Variable | Default | What it is |
| --- | --- | --- |
| `SCREENSCRAPER_DEV_ID` / `SCREENSCRAPER_DEV_PASSWORD` | the project's | A developer account of your own, in place of the one retroBITE ships with. Set both or neither. |
| `SCREENSCRAPER_CONNECT_TIMEOUT` | `15` | Seconds to wait for a connection. |
| `SCREENSCRAPER_TIMEOUT` | `90` | Seconds to wait for an answer. |

## RetroAchievements

Your RetroAchievements account goes in Settings → RetroAchievements.

| Variable | Default | What it is |
| --- | --- | --- |
| `RETROACHIEVEMENTS_CONNECT_TIMEOUT` | `15` | Seconds to wait for a connection. |
| `RETROACHIEVEMENTS_TIMEOUT` | `180` | Seconds to wait for an answer. |
| `RA_HASHER_PATH` | `/usr/local/bin/RAHasher` | The hasher, which ships in the image. |
| `RA_HASHER_TIMEOUT` | `1800` | Seconds one file may take to hash. |

## Tools → Conversion

Every tool ships in the web image, on `PATH`.

| Variable | Default | What it is |
| --- | --- | --- |
| `CONVERSION_CONCURRENCY` | `1` | Conversions at once. Raise `QUEUE_WORKERS_CONVERSION` to match. |
| `CONVERSION_TIMEOUT` | `7000` | Seconds one conversion may take. |
| `CHDMAN_PATH`, `MAXCSO_PATH`, `ECM_PATH`, `UNECM_PATH`, `EXTRACT_XISO_PATH`, `CUE2POPS_PATH`, `POPS2CUE_PATH`, `NODTOOL_PATH`, `PS3DEC_PATH` | `/usr/local/bin/…` | Point one at another build of that tool. |
