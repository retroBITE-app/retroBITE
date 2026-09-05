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
- **Debian 12 (bookworm-slim)** - Base image
- **PHP 8.3 + Slim 4** - Web interface backend
- **Vue 3 + TypeScript** - Web interface frontend
- **Inertia.js** - Server-driven SPA bridge
- **Tailwind CSS** - Styling
- **SQLite** - Database

## Deployment & Usage
retroBITE is designed to run exclusively as a Docker container. To ensure stability and ease of use, we do not provide support or assistance for manual installations outside of Docker.

### Quick Start

1. Clone this repository.

2. Copy `.env.example` to `.env` and adjust the settings to match your local setup.

3. Run the provided `docker-compose.yml` file

```bash
docker-compose up -d
```
For local devopment
```bash
docker compose -f docker-compose.dev.yml up --build
```

4. Connect your retro console to the network share to load ROMs directly over your local network. See [`CONSOLES.md`](CONSOLES.md) for per-console setup instructions.

5. Access the web interface at http://localhost (or `WEB_PORT` from your `.env`)

## Credits
- [Libretro](https://github.com/libretro/retroarch-assets/tree/master/xmb/retrosystem/png): Sourced console iconography.
- AI/LLM Tools: Assisted in the creation of original project assets.
- Our Contributors: Made with ❤️ by the community.