<?php
namespace SlackNotify\Service;

use Users\Model\User;

interface IUserMapping
{
    const SERVICE_NAME = "SlackNotifyUserMapping";

    /**
     * Get Slack user ID for a Swarm user
     *
     * @param User $user - Swarm user to look up
     * @return string|null - Slack user ID or null if not found
     */
    public function getSlackUserId(User $user): ?string;
}