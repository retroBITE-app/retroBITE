<p align="center">
  <a href="https://github.com/mattiasghodsian/retroBite/">
    <img alt="retroBite" src="retroBite.png" height="150">
  </a>
  <p align="center">A Docker-based ROM server with a web UI for hosting, streaming, and managing retro collections over your local network.</p>
</p>

## Features

- **SMB/CIFS** with SMBv1 compatibility for older consoles
- **FTP Server** with anonymous access and passive mode
- **Web Interface** for browsing, uploading, and managing your collections
- and more coming

## Disclaimer
retroBITE is intended for use with backups you have legally made from media you own. We do not endorse or condone piracy in any form. Only use this software with ROMs you have the legal right to possess.

## Project Background
This project was created to provide a dockerized container for streaming rom files over a local network to various retro consoles, paired with a web interface for managing your collections. While other solutions existed, none offered the combination of features, Docker support, and a browser-based UI that I needed. retroBITE is an experimental project that aims to simplify retro gaming over a local network.

## Built With
- **Samba** - SMB/CIFS file sharing
- **vsftpd** - FTP server
- **Ubuntu 22.04** - Base image
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

5. Access the web interface at http://localhost:8080

## Credits
- [Libretro](https://github.com/libretro/retroarch-assets/tree/master/xmb/retrosystem/png): Sourced console iconography.
- AI/LLM Tools: Assisted in the creation of original project assets.
- Our Contributors: Made with ❤️ by the community.