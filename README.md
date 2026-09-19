<img src="icon.png" alt="AquaDiscordSRV" width="120"/>

# AquaDiscordSRV

[![Stars](https://img.shields.io/github/stars/MJMCPE/AquaDiscordSRV?style=flat&color=0E9C8F)](https://github.com/MJMCPE/AquaDiscordSRV/stargazers)
[![Issues](https://img.shields.io/github/issues/MJMCPE/AquaDiscordSRV?style=flat&color=5865F2)](https://github.com/MJMCPE/AquaDiscordSRV/issues)
![Platform](https://img.shields.io/badge/platform-Aquamarine%20(API%203.0.1)-0E9C8F)
[![Discord](https://img.shields.io/badge/Discord-Join-5865F2?logo=discord&logoColor=white)](https://discord.gg/JtX2PQtn54)

A Discord ⇄ Minecraft chat bridge for **Aquamarine**, inspired by [DiscordSRV](https://github.com/DiscordSRV/DiscordSRV) - built from scratch for Aquamarine's own plugin API rather than a direct port, since PHP and Java don't exactly share code.

## Community

[![Discord Banner](https://dcbadge.limes.pink/api/server/JtX2PQtn54?style=flat)](https://discord.gg/JtX2PQtn54)

Questions, bugs, or just want to see it running live → [discord.gg/JtX2PQtn54](https://discord.gg/JtX2PQtn54)

## What is AquaDiscordSRV?

DiscordSRV runs on a full Discord Gateway (WebSocket) connection with a live member/role cache - Aquamarine's plugin API doesn't expose anything like that to plugins. So AquaDiscordSRV takes the same idea (keep your Discord server and your Minecraft server talking to each other) and builds it around what's actually available here: REST polling instead of a live socket, and a companion service instead of guessing at things the platform can't tell a plugin.

## Features

- In-game chat → Discord, and Discord → in-game chat
- Join / leave / death messages posted to Discord
- Server start/stop notices
- Optional "chat as the player" mode via a Discord webhook
- Optional: real per-player avatars rendered from their actual Bedrock skin, via the companion [aquadiscordsrv-skin-render](https://github.com/MJMCPE/aquadiscordsrv-skin-render) service - falls back to a generic lookup if that service isn't set up
- Optional console log relay to a Discord channel, and (off by default) executing messages from that channel as console commands
- `/discordsrv reload` and `/discordsrv status`

## Not included, on purpose

A few DiscordSRV staples need that persistent Gateway connection and member cache to work at all, so rather than half-build them, they're left out for now:

- Role sync, ban sync, nickname sync
- Voice proximity chat
- `/discord link` account linking

## Installation

1. Grab the latest release and drop the `AquaDiscordSRV` folder into your server's `plugins/` directory
2. Start the server once to generate `config.yml` (see [where that file actually lives](#configuration) below)
3. Create a bot at the [Discord Developer Portal](https://discord.com/developers/applications), enable the **Server Members** and **Message Content** privileged intents, and invite it using the permissions in the [Bot Permissions](#bot-permissions) section
4. Fill in `BotToken` and `Channels.Chat` in `config.yml`, set `Enabled: true`
5. Restart, or run `/discordsrv reload`

## Configuration

Aquamarine keeps plugin config files separate from plugin code - `config.yml` lives at:

```
plugin_data/AquaDiscordSRV/config.yml
```

not inside the `plugins/AquaDiscordSRV/` folder itself. Every option is documented with comments directly in that file.

## Bot Permissions

AquaDiscordSRV doesn't touch roles, nicknames, or bans, so it needs a lot less than DiscordSRV does:

### Server Permissions

None required. Webhook messages (if you use that mode) are set up manually as a Discord channel webhook - the bot itself never needs `Manage Webhooks`.

### Channel Permissions

| Permission | Why |
|---|---|
| `View Channel` | required to read the chat (and console) channels you configure |
| `Send Messages` | to post in-game chat, join/leave, and death messages |
| `Read Message History` | required for polling - the bot checks for new messages since the last one it saw |

Apply these to the specific channel(s) you configure, or server-wide if that's simpler for you.

## Donations

Coming soon.

## License

Not yet decided - treat this as source-available for now. Open an issue if you want to use it somewhere and licensing terms matter for that.
