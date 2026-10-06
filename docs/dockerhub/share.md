# retroBITE share

The SMB and FTP server of [retroBITE](https://hub.docker.com/r/retrobite/retrobite).
It serves a ROM library to consoles: SMBv1 for older ones such as the PS2's
Open PS2 Loader, and FTP with passive mode.

It is meant to run beside `retrobite/retrobite`, from retroBITE's compose
file. See
[Installing](https://github.com/retroBITE-app/retroBITE/blob/develop/docs/installing.md).

## Settings

| Variable | Default | What it is |
| --- | --- | --- |
| `AUTH_USER` / `AUTH_PASS` | `retrobite` / `retrobite` | The one account for SMB and FTP. Change the password. |
| `HOST_IP` | from the default route | The address FTP passive mode hands to clients. |
| `GAME_FOLDERS` | `ps2 ps3 gc wii xbox dreamcast` | Console folders created in `/games`. |
| `SMB_SHARES` | `ps2 gc wii` | Folders with an SMB share of their own. |

It mounts the library at `/games`, runs as the owner of that folder, and
needs ports 445, 139, 21 and 21100–21110. On a NAS whose own file sharing
holds those ports, give it an address of its own with
[docker-compose.macvlan.yml](https://github.com/retroBITE-app/retroBITE/blob/develop/docs/installing.md#when-ports-445-139-and-21-are-taken).

`linux/amd64` and `linux/arm64`. Source: https://github.com/retroBITE-app/retroBITE
