# Console Setup Guide

Instructions for setting up network connectivity for various retro gaming consoles.

| Console | Share | Protocol | Path | Requirements | Tested |
|---------|-------|----------|------|--------------|--------|
| [PlayStation 2](#playstation-2-smb) | `ps2` | SMB | /games/ps2 | FMCB, OPL | ✅ |
| [GameCube](#gamecube-smb) | `gc` | SMB | /games/gc | Homebrew + Swiss or Nintendont | ❌ |
| [Wii](#nintendo-wii-smb) | `wii` | SMB | /games/wii | Homebrew + USB Loader GX or WiiFlow | ❌ |
| [PlayStation 3](#playstation-3-ftp) | N/A | FTP | /games/ps3 | CFW, Multiman or Webman | ❌ |
| [Xbox](#xbox-ftp) | N/A | FTP | /games/xbox | Softmod or modchip | ❌ |
| [Dreamcast](#dreamcast-ftp) | N/A | FTP | /games/dreamcast | DreamShell or dc-load | ❌ |


## PlayStation 2

1. Launch Open PS2 Loader
2. Go to Settings → Network Config
3. Set IP address type to "Static" or "DHCP"
4. Enter SMB Server:
   - Address: `192.168.50.250` (your retroBite server)
   - Port: `445`
   - Share/Path: `ps2`
   - Username: `retrobite` (your username)
   - Password: `retrobite` (your password)
5. Save and return to main menu
6. Games should appear in the ETH games list

## GameCube

**Swiss:**

1. Launch Swiss
2. Navigate to network share
3. Configure SMB:
   - Server: `192.168.50.250` (your retroBite server)
   - Share/Path: `gc`
   - Username: `retrobite` (your username)
   - Password: `retrobite` (your password)
4. Browse and load games

**S Nintendont:**
1. Place Nintendont on SD card
2. Launch Nintendont
3. Press B for settings
4. Enable network loading
5. Set SMB share path: `smb://192.168.50.250/games`

## Nintendo Wii (SMB)

1. Launch USB Loader GX
2. Go to Settings → Custom Paths
3. Enable SMB
4. Configure:
   - IP: `192.168.50.250` (your retroBite server)
   - Share/Path: `wii`
   - Username: `retrobite`  (your username)
   - Password: `retrobite` (your password)
5. Scan for games

## PlayStation 3 (FTP)

1. Install CFW (Custom Firmware) on your PS3
2. Install Multiman or Webman MOD
3. Launch Multiman/Webman
4. Go to FTP Server settings and note the IP
5. Connect via FTP client:
   - Host: `192.168.50.250` (your retroBite server)
   - Username: `retrobite` (your username)
   - Password: `retrobite` (your password)
   - Port: `21`
6. Navigate to `/games/ps3` and upload your games
7. Games in ISO or folder format can be mounted via Multiman/Webman

## Xbox (FTP)

1. Softmod or install modchip on your Xbox
2. Configure network settings (static or DHCP)
3. Use FTP client to connect:
   - Host: `192.168.50.250` (your retroBite server)
   - Username: `retrobite` (your username)
   - Password: `retrobite` (your password)
   - Port: `21`
4. Navigate to `/games/xbox` to access game files
5. Transfer games to Xbox HDD via FTP manager or dashboard

## Dreamcast (FTP)

1. Install DreamShell or use dc-load for network boot
2. Configure Dreamcast network adapter (BBA or LAN adapter)
3. Use FTP client on Dreamcast:
   - Host: `192.168.50.250` (your retroBite server)
   - Username: `retrobite` (your username)
   - Password: `retrobite` (your password)
   - Port: `21`
4. Navigate to `/games/dreamcast` to load games
5. Games can be in CDI or GDI format for network loading