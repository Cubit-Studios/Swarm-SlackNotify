# Slack Notify Module for Helix Swarm

A Helix Swarm module that provides enhanced Slack notifications for code reviews, focusing on direct communication with reviewers and review authors.

## Features

- 🔔 **New Review Notifications**: Automatically posts new review requests to a designated Slack channel
- 💬 **Direct Comment Notifications**:
    - Sends direct Slack messages to reviewers when new comments are posted
    - Notifies review authors of new comments on their reviews
    - Skips notifications for comment authors (no self-notifications)
- 👤 **Smart User Mapping**:
    - Automatically maps Swarm users to Slack users using email addresses
    - Caches user mappings to minimize API calls
    - Reports mapping failures to the configured notification channel
- 🔒 **Duplicate Prevention**: Implements locking mechanism to prevent duplicate notifications
- ⚡ **Performance Optimized**:
    - Efficient caching of user mappings
    - Configurable cache TTL
    - Minimal API calls

## Installation

1. Clone this repository into your Swarm modules directory (typically /opt/perforce/swarm/module):
```bash
cd modules
git clone [repository-url] SlackNotify
```

2. Add the module to your Swarm configuration (`config/custom.modules.config.php`):
```php
<?php
\Laminas\Loader\AutoloaderFactory::factory(
    array(
        'Laminas\Loader\StandardAutoloader' => array(
            'namespaces' => array(
                // ... other modules ...
                'SlackNotify'       => BASE_PATH . '/module/SlackNotify/src',
            )
        )
    )
);
return [
    // ... other modules ...
    'SlackNotify',
];
```

3. Copy the example configuration and adjust as needed:
```bash
cp /opt/perforce/swarm/module/slack-notify/config/module.config.php.example /opt/perforce/swarm/module/slack-notify/config/slack-notify.config.php
```

4. Delete cache
```bash
rm -rf /opt/perforce/swarm/data/cache
```
## Configuration

The module requires the following configuration in your Swarm settings:

```php
[
    'slack' => [
        // OAuth token for your Slack app (Found in OAuth & Permissions after installing the Slack, more details: https://api.slack.com/authentication/oauth-v2)
        'token' => 'xoxb-your-token-here',
        
        // Channel for new review notifications
        'notify_channel' => 'swarm-reviews',
        
        // Cache time for user mappings (in seconds)
        'user_cache_ttl' => 3600,
    ]
]
```

### Required Slack App Permissions

Your Slack app needs the following OAuth scopes:

- chat:write - Send messages as @SwarmNotifier.
- chat:write.public - Send messages to channels where @SwarmNotifier isn’t a member.
- im:write - Start direct messages with users.
- users:read - View user information in the workspace.
- users:read.email - Look up users by their email address

## Message Examples

### New Review Notification (Channel)
```
New Review #1234
Author: john.doe
Description: Update user authentication flow
Reviewers: alice.smith, bob.jones
[View Review in Swarm]
```

### New Comment Notification (Direct Message)
```
New Comment on Review #1234
From: alice.smith
Comment: The error handling could be improved here
[View Review in Swarm]
```

## Acknowledgments

This module is built on top of the Helix Swarm platform and uses the Slack API. Special thanks to the Perforce team for providing the base Slack integration module that served as inspiration for this enhanced notification system.
