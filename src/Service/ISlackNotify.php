<?php
namespace SlackNotify\Service;

use Activity\Model\Activity;
use Reviews\Model\Review;

interface ISlackNotify
{
    const SERVICE_NAME = "SlackNotifyService";

    /**
     * Send notification about a new review request
     *
     * @param Review $review - The review that was created
     * @throws ConfigException
     */
    public function notifyNewReview(Review $review);

    /**
     * Send notifications about a new comment
     *
     * @param Review $review - The review that was commented on
     * @param Activity $activity - The comment activity
     * @throws ConfigException
     */
    public function notifyNewComment(Review $review, Activity $activity);
}