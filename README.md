# FlatBB Messages Plus

An enhanced private messaging plugin for [FlatBB](https://www.flatbb.com) with a polished, Telegram-style interface.

## Credits

This plugin is an enhanced version of the original [Messages plugin](https://www.flatbb.com/market/messages) for FlatBB, created by [FlatBB](https://www.flatbb.com/u/FlatBB).

## Features
- Telegram-like chat UI: conversation list, message bubbles, live updates without reload
- Instant message sending with read receipts (sent/read ticks)
- Message editing and recalling
- Search across contacts you've chatted with
- Real message deletion: "Delete chat" clears history per user; full purge when both sides delete
- Admin panel to read conversations and delete messages directly from the database
- Blocking and privacy preferences (members can opt out of receiving messages)
- Rate limiting for new members
- English-only interface with Gregorian dates

## Installation
1. Download or clone this repository.
2. Copy the plugin folder into your FlatBB `plugins/` directory.
3. Go to **Admin → Plugins** and activate "Messages Plus".
4. Configure settings under the plugin's settings page (min posts, messages per hour, edit/recall windows, etc.).

## Requirements
- FlatBB 0.1.94 or higher
- PHP 8.1+

## License
MIT (or your preferred license)
