# WP Post Sync

A WordPress plugin that synchronizes posts and pages from an admin (source) site to a public (target) site.

## Description

WP Post Sync enables content synchronization between WordPress sites. It's designed with a primary-secondary architecture where:

- **Admin Site (Source)**: Manages content creation and initiates synchronization
- **Public Site (Target)**: Receives synchronized content from the admin site

The plugin automatically queues content for synchronization when posts, pages, or media are created or updated on the admin site, then sends them to the target site based on configured settings.

## Features

- Bidirectional site role configuration (Admin/Public)
- Automatic post, page, and media synchronization
- Configurable sync delay
- Manual sync option via admin bar button
- Detailed sync logs and status tracking
- API key authentication for secure communication

## Installation

1. Upload the `wp-post-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Tools > WP Post Sync' to configure the plugin

## Configuration

### Admin Site (Source)

1. Set "Site Role" to "Admin (Source)"
2. Enter the URL of your target site
3. Generate and enter an API key (must match on both sites)
4. Set the desired sync delay in minutes (0 for immediate sync)
5. Save changes

### Public Site (Target)

1. Set "Site Role" to "Public (Target)"
2. Enter the same API key as configured on the admin site
3. Save changes

## Usage

### Automatic Synchronization

Posts, pages, and media are automatically queued for synchronization when they are created or updated on the admin site. The sync process runs on a schedule based on your configured delay.

### Manual Synchronization

If there are pending items in the queue, an admin bar button "Sync Now" will appear, allowing you to manually trigger the synchronization process.

### Sync Status

The plugin adds a "Sync Status" column to post and page lists, showing the current sync status:
- Not synced
- Queued
- Synced
- Failed

## Developer Notes

### Filters

- `wp_post_sync_post_types`: Modify which post types are synchronized (default: 'post' and 'page')

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Fadlee
