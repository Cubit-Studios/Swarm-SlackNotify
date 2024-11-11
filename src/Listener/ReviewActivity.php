<?php
namespace SlackNotify\Listener;

use Activity\Model\IActivity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Mail\MailAction;
use Reviews\Model\Review;
use SlackNotify\Service\ISlackNotify;

class ReviewActivity extends AbstractEventListener
{
    const LOG_PREFIX = ReviewActivity::class;
    protected $slackNotify = null;

    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->slackNotify = $this->services->get(ISlackNotify::SERVICE_NAME);
    }

    public function shouldAttach($eventName, $eventDetail): bool
    {
        $config = $this->services->get(IDef::CONFIG);
        try {
            ConfigManager::getValue($config, 'slack-notify.token');
        } catch (Exception $e) {
            return false;
        }
        $this->logger->trace(
            sprintf(
                "%s: Slack token has been provided",
                self::LOG_PREFIX
            )
        );
        return true;
    }

    public function handleReviewActivity(Event $event)
    {
        $review = $event->getParam('review');
        $activity = $event->getParam('activity');
        $quiet = $event->getParam('quiet');
        $data = (array) $event->getParam('data') + ['quiet' => null];

        // Add detailed debug logging
        $this->logger->debug(sprintf(
            "%s: Processing event type: %s",
            self::LOG_PREFIX,
            $event->getName()
        ));

        $this->logger->debug(sprintf(
            "%s: Event details - Review: %s, Activity Type: %s, Quiet: %s, Description: %s",
            self::LOG_PREFIX,
            $review ? $review->getId() : 'null',
            $activity ? $activity->get('action') : 'null',
            $quiet ? 'true' : 'false',
            $activity ? $activity->get('description') : 'null',
        ));

        // Detailed debugging of skip conditions
        $this->logger->debug(sprintf(
            "%s: Skip conditions - Activity null: %s, Quiet: %s, Data[quiet]: %s",
            self::LOG_PREFIX,
            $activity === null ? 'true' : 'false',
            $quiet ? 'true' : 'false',
            isset($data['quiet']) ? var_export($data['quiet'], true) : 'null'
        ));

        // Debug full data array
        $this->logger->debug(sprintf(
            "%s: Full data array: %s",
            self::LOG_PREFIX,
            var_export($data, true)
        ));

        // Modified skip condition check
        if ($activity === null) {
            $this->logger->debug(sprintf("%s: Skipping - Activity is null", self::LOG_PREFIX));
            return;
        }

        if ($quiet === true) {
            $this->logger->debug(sprintf("%s: Skipping - Quiet is true", self::LOG_PREFIX));
            return;
        }

        // The data['quiet'] check might need to be adjusted based on what we see in the logs
        if (!empty($data['quiet']) && $data['quiet'] !== ['mail']) {
            $this->logger->debug(sprintf("%s: Skipping - Data quiet is set: %s", self::LOG_PREFIX, var_export($data['quiet'], true)));
            return;
        }

        try {
            $action = $activity->get('action');
            $this->logger->debug(sprintf(
                "%s: Processing activity action: %s",
                self::LOG_PREFIX,
                $action
            ));

            switch ($action) {
                case MailAction::REVIEW_REQUESTED:
                    $this->logger->debug(sprintf("%s: Handling review request", self::LOG_PREFIX));
                    $this->slackNotify->notifyNewReview($review);
                    break;

                case MailAction::REVIEW_NEEDS_REVIEW:
                    $this->logger->debug(sprintf("%s: Handling review needs review", self::LOG_PREFIX));
                    $this->slackNotify->notifyReviewNeedsReview($review);
                    break;

                case MailAction::REVIEW_NEEDS_REVISION:
                    $this->logger->debug(sprintf("%s: Handling review needs revision", self::LOG_PREFIX));
                    $this->slackNotify->notifyReviewNeedsRevision($review);
                    break;

                case MailAction::REVIEW_APPROVED:
                    $this->logger->debug(sprintf("%s: Handling review approved", self::LOG_PREFIX));
                    $this->slackNotify->notifyReviewApproved($review);
                    break;

                case MailAction::COMMENT_ADDED:
                case MailAction::DESCRIPTION_COMMENT_ADDED:
                    $this->logger->debug(sprintf("%s: Handling comment added", self::LOG_PREFIX));
                    $this->slackNotify->notifyNewComment($review, $activity);
                    break;

                case MailAction::COMMENT_REPLY:
                    $this->logger->debug(sprintf("%s: Handling comment reply", self::LOG_PREFIX));
                    $originalCommentId = $data['current']['context']['comment'] ?? null;
                    $this->slackNotify->notifyNewReply($review, $activity, $originalCommentId);
                    break;

                case MailAction::REVIEW_TESTS:
                case MailAction::REVIEW_TESTS_NO_AUTH:
                    $this->logger->debug(sprintf("%s: Handling review test status", self::LOG_PREFIX));
                    $testStatus = $data['testStatus'] ?? null;
                    $testUrl = $data['testUrl'] ?? null;
                    $this->slackNotify->notifyTestStatus($review, $activity, [
                        'status' => $testStatus,
                        'url' => $testUrl
                    ]);
                    break;

                default:
                    $this->logger->debug(sprintf(
                        "%s: Ignoring unsupported action: %s",
                        self::LOG_PREFIX,
                        $action
                    ));
                    break;
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Error processing review activity: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        }
    }
}
