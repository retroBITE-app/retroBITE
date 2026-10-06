# Installing retroBITE

retroBITE is three containers started from one compose file:

| Container | Image | What it does |
| --- | --- | --- |
| `retrobite-web` | `retrobite/retrobite` | The web interface, its background workers and live updates. |
| `retrobite-db` | `mariadb:11.4` | The database. |
| `retrobite-share` | `retrobite/share` | SMB and FTP, serving the library to consoles. |

Both images are published for `amd64` and `arm64`, so they run on an Intel or
AMD NAS, a Raspberry Pi 4 or 5, and Apple silicon alike.

You need two files from the repository:

- **`docker-compose.yml`**, the stack. It runs as it is; every setting has a
  default.
- **`.env.example`**, the handful of settings worth changing. Save it as
  `.env`, or type the same names into your container manager.

There is no key to generate and no command to run inside a container. On its
first start retroBITE:

- creates its database
- makes its own application key
- opens a four-step setup in the browser

Every setting is described in [configuration.md](configuration.md).

**Before the first start**, always change `AUTH_PASS`, `DB_PASSWORD` and
`DB_ROOT_PASSWORD`, and set `GAMES_PATH` to your library. The database
passwords are read only once, when the database is created.

## Contents

- [Linux, from the command line](#linux-from-the-command-line)
- [Portainer and Dockhand](#portainer-and-dockhand)
- [Synology](#synology)
- [QNAP](#qnap)
- [Unraid](#unraid)
- [TrueNAS SCALE](#truenas-scale)
- [Docker Desktop on Windows and macOS](#docker-desktop-on-windows-and-macos)
- [When ports 445, 139 and 21 are taken](#when-ports-445-139-and-21-are-taken)
- [Updating](#updating)
- [Backing up](#backing-up)

## Linux, from the command line

You need Docker Engine with Compose 2.24 or newer (`docker compose version`).

```bash
mkdir retrobite && cd retrobite
curl -LO https://raw.githubusercontent.com/retroBITE-app/retroBITE/develop/docker-compose.yml
curl -L -o .env https://raw.githubusercontent.com/retroBITE-app/retroBITE/develop/.env.example
nano .env            # passwords, GAMES_PATH, HOST_IP, APP_TIMEZONE
docker compose up -d
```

Open `http://<HOST_IP>:81` and follow the setup. `docker compose logs -f
retrobite-web` shows what it is doing.

The share container uses the host's network so that consoles find it by name.
That needs ports 445, 139 and 21 free: they are, unless Samba or an FTP server
already runs on the machine. If either does, see
[When ports 445, 139 and 21 are taken](#when-ports-445-139-and-21-are-taken).

## Portainer and Dockhand

Both deploy a compose file as a **stack** and fill its `${VARIABLES}` from
their own editor, so no `.env` file is needed.

1. **Stacks → Add stack** (Dockhand: **Stacks → New**). Name it `retrobite`.
2. Choose one source:
   - **Web editor**: paste the contents of `docker-compose.yml`.
   - **Repository**: `https://github.com/retroBITE-app/retroBITE`, compose
     path `docker-compose.yml`. For the reference, use a release's tag, such as
     `refs/tags/20261006-BETA`, and set `RETROBITE_TAG` to the same version below,
     so the file and the images match.
3. Under **Environment variables**, add at least:
   - `AUTH_PASS`, `DB_PASSWORD` and `DB_ROOT_PASSWORD`
   - `GAMES_PATH`, as an absolute path on the Docker host
   - `HOST_IP` and `APP_TIMEZONE`

   In Portainer, **Load variables from .env file** takes `.env.example` as a
   starting point.
4. **Deploy the stack.** A container's **Logs** shows its start, including a
   warning for every setting still left at its default.

Settings not in the compose file, such as `QUEUE_WORKERS_MEDIA`, go in the
same editor and are added under the service's `environment:` in the compose
file. See [configuration.md](configuration.md).

## Synology

DSM 7.2 or later, with **Container Manager** from the Package Center.

1. **Make the folders in File Station.**
   - Use your library's shared folder, such as `/volume1/games`, with one folder
     per console inside it.
   - Make a folder for the project, such as `/volume1/docker/retrobite`.
2. **Put the files in the project folder.** Save `.env.example` there as `.env`
   and edit it:
   - `GAMES_PATH=/volume1/games`
   - the passwords
   - `HOST_IP` (the NAS's address) and `APP_TIMEZONE`
   - optionally `DB_PATH`, `MEDIA_PATH` and `STATE_PATH` under
     `/volume1/docker/retrobite/`, to include them in Hyper Backup
3. **Create the project.** In Container Manager go to **Project → Create**, set
   the path to the project folder, and choose **Upload docker-compose.yml**.
4. **Free the SMB and FTP ports.** DSM's own file sharing already uses 445, 139
   and 21, so the share container cannot start as it is. Either:
   - give it an address of its own, as described in
     [When ports 445, 139 and 21 are taken](#when-ports-445-139-and-21-are-taken).
     The parent interface is `eth0`, or `ovs_eth0` with Open vSwitch on.
   - or turn off SMB and FTP in **Control Panel → File Services**, if the NAS
     shares nothing else.
5. **Build** starts it. Open `http://<NAS address>:81`.

The containers run as whoever owns the games folder. If a console cannot write
over SMB and the log says the library is not writable, give that folder's
owner read and write in **File Station → Properties → Permission**.

## QNAP

**Container Station 3**:

1. Go to **Applications → Create**, name it `retrobite`, and paste
   `docker-compose.yml`.
2. Container Station has no variables editor, so change the defaults in the
   file itself. In `${GAMES_PATH:-./games}`, the part after `:-` is the value
   used. Set `GAMES_PATH` to the library's absolute path, such as
   `/share/Games`, and change the passwords there.
3. QTS's own SMB and FTP use 445, 139 and 21, as on Synology. Use
   [macvlan](#when-ports-445-139-and-21-are-taken) with the parent interface
   `eth0` (or the virtual switch's name), or turn off QTS's Microsoft
   Networking and FTP.

## Unraid

Install **Docker Compose Manager** from Community Applications.

1. Go to **Docker → Compose → Add new stack**, name it `retrobite`, and paste
   `docker-compose.yml` with **Edit stack → Compose file**.
2. Under **Edit stack → Env file**, paste `.env.example` and set:
   - `GAMES_PATH=/mnt/user/games`
   - `DB_PATH=/mnt/user/appdata/retrobite/db`
   - `MEDIA_PATH=/mnt/user/appdata/retrobite/media`
   - `STATE_PATH=/mnt/user/appdata/retrobite/state`
   - the passwords, `HOST_IP` and `APP_TIMEZONE`
3. Unraid's own SMB uses 445 and 139. Use
   [macvlan](#when-ports-445-139-and-21-are-taken) with `SHARE_PARENT=br0`
   (Unraid's default bridge), or stop Unraid's SMB if nothing else uses it.
4. **Compose up.**

## TrueNAS SCALE

TrueNAS SCALE 24.10 (Electric Eel) or later runs compose files directly.

1. Make datasets for the library and for retroBITE's data, such as
   `/mnt/tank/games` and `/mnt/tank/apps/retrobite/{db,media,state}`.
2. Go to **Apps → Discover Apps → ⋮ → Install via YAML** and paste
   `docker-compose.yml`.
3. The YAML editor has no variables, so replace the `${…:-default}` values
   you need with your own. At least set:
   - `GAMES_PATH`, `DB_PATH`, `MEDIA_PATH` and `STATE_PATH` to the datasets
   - the passwords
   - `HOST_IP`
4. If TrueNAS shares anything over SMB itself, the share container needs
   [macvlan](#when-ports-445-139-and-21-are-taken), with the parent interface
   from **Network → Interfaces**.

## Docker Desktop on Windows and macOS

Docker Desktop runs the web interface well, and is a good way to try
retroBITE. Serving consoles from it is another matter:

- Windows holds port 445 for its own file sharing.
- macOS holds port 445 whenever File Sharing is on.
- Docker Desktop supports neither the host network the share container uses
  by default nor macvlan.

So start the web interface and the database alone:

```bash
docker compose up -d retrobite-web retrobite-db
```

and serve the library to consoles from the computer itself, or from a NAS. On
Windows, put the library on the Windows drive (`GAMES_PATH=C:/Games`), and
expect scanning to be slower than on a Linux disk.

Send to → USB drive works from Docker Desktop. It needs Chrome or Edge on
`http://localhost:81`.

## When ports 445, 139 and 21 are taken

A NAS's own file sharing, and Samba or an FTP server on a Linux machine,
already listen on the ports consoles connect to. **docker-compose.macvlan.yml**
gives the share container an address of its own on the LAN, as though it were
another computer, and leaves the NAS's sharing alone.

1. **Pick an address.** It must be free, and outside the router's DHCP range so
   nothing else is handed it.
2. **Add these settings** to `.env` or to the stack's variables:

   ```bash
   SHARE_IP=192.168.1.250        # the address consoles will use
   SHARE_SUBNET=192.168.1.0/24   # your LAN
   SHARE_GATEWAY=192.168.1.1     # your router
   SHARE_PARENT=eth0             # the host's LAN interface: `ip route` shows it
   ```

3. **Start it with both files:**

   ```bash
   curl -LO https://raw.githubusercontent.com/retroBITE-app/retroBITE/develop/docker-compose.macvlan.yml
   docker compose -f docker-compose.yml -f docker-compose.macvlan.yml up -d
   ```

   In Portainer, Dockhand or Container Manager, paste the two files together:
   the `networks:` block and the `retrobite-share` and `retrobite-web` changes
   from `docker-compose.macvlan.yml`, merged into `docker-compose.yml`.

Consoles connect to `SHARE_IP`. The NAS itself cannot reach that address, which
is how macvlan works and nothing to fix: retroBITE checks the share over a
network of its own.

## Updating

While retroBITE is in beta the compose file runs `develop`, the newest
pre-release, so an update is:

```bash
docker compose pull
docker compose up -d
```

In Portainer and Dockhand, use **Pull and redeploy**; in Container Manager,
**Action → Build**. If you set `RETROBITE_TAG` to one version, change it to
the next first.

The database is brought up to date on start. Your library, database, artwork
and keys are kept: they are in the volumes or folders above, not in the
containers.

## Backing up

| What | Where | Why |
| --- | --- | --- |
| The database | `DB_PATH`, or volume `retrobite-db` | Your games, metadata, settings and accounts. |
| The keys | `STATE_PATH`, or volume `retrobite-state` | Without the application key, the stored passwords in the database cannot be read. Always back it up with the database. |
| The library | `GAMES_PATH` | Your files. retroBITE only changes them when you ask it to. |
| Artwork | `MEDIA_PATH`, or volume `retrobite-media` | Optional: it can be downloaded again. |

For a consistent copy of the database, stop the stack first
(`docker compose stop`), or take a dump while it runs:

```bash
docker exec retrobite-mariadb mariadb-dump -u root -p"$DB_ROOT_PASSWORD" retrobite > retrobite.sql
```
