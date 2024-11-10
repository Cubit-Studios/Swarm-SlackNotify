<?php
namespace SlackNotify\Model;

use Notifications\Model\Notification;

class SlackNotify extends Notification implements ISlackNotify
{
    const UPGRADE_LEVEL = 0;
    const KEY_PREFIX    = 'notification-slack-notify-';
}