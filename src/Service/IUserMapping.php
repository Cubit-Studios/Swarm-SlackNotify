<?php
namespace SlackNotify\Service;

interface IUserMapping
{
    const SERVICE_NAME = "SlackNotifyUserMapping";

    /**
     * Get Slack user ID for a Swarm user
     *
     * @param string $email - Email address to look up
     * @return string|null - Slack user ID or null if not found
     */
    public function getSlackUserId(string $email): ?string;
}