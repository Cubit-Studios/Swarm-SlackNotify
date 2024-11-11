<?php
namespace SlackNotify\Service;

use Activity\Model\Activity;
use Reviews\Model\Review;

interface ISlackNotify
{
    const SERVICE_NAME = "SlackNotifyService";

    /**
     * Send notification about a new review request to channel
     *
     * @param Review $review - The review that was created
     * @throws ConfigException
     */
    public function notifyNewReview(Review $review);

    /**
     * Send notification about revisions being requested on existing review by direct message to review author
     *
     * @param Review $review - The review
     * @throws ConfigException
     */
    public function notifyReviewNeedsRevision(Review $review);

    /**
     * Send notification about a new review being requested on existing review by direct message to reviewers
     *
     * @param Review $review - The review
     * @throws ConfigException
     */
    public function notifyReviewNeedsReview(Review $review);

    /**
     * Send notification about a review being approved by direct message to review author
     *
     * @param Review $review - The review approved
     * @throws ConfigException
     */
    public function notifyReviewApproved(Review $review);

    /**
     * Send notifications about a new comment to all participants by direct message except the comment author
     *
     * @param Review $review - The review that was commented on
     * @param Activity $activity - The comment activity
     * @throws ConfigException
     */
    public function notifyNewComment(Review $review, Activity $activity);

    /**
     * Send notifications about a reply to a comment by direct message to the author of the comment replied to
     *
     * @param Review $review - The review that was commented on
     * @param Activity $activity - The comment activity
     * @param string $originalCommentId - The ID of the original comment replied to
     * @throws ConfigException
     */
    public function notifyNewReply(Review $review, Activity $activity, string $originalCommentId);

    /**
     * Send notification about test status to review author
     *
     * @param Review $review - The review with failed tests
     * @param Activity $activity - The activity
     * @param array $testData - Details about the test
     * @throws ConfigException
     */
    public function notifyTestStatus(Review $review, Activity $activity, array $testData = []);
}