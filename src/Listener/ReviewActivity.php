<?php
namespace SlackNotify\Listener;

use Activity\Model\IActivity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Reviews\Model\Review;
use SlackNotify\Service\ISlackNotify;
use Laminas\EventManager\Event;

class ReviewActivity extends AbstractEventListener
{
    const LOG_PREFIX = ReviewActivity::class;
    protected $slackNotify = null;

    /**
     * Construct with required services
     * @param ServiceLocator  $services    the service locator to use
     * @param array          $eventConfig  the event config for this listener
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->slackNotify = $this->services->get(ISlackNotify::SERVICE_NAME);
    }

    /**
     * Attaches this event only if the slack token has been provided
     * @param mixed $eventName    the event name
     * @param array $eventDetail  the event detail
     * @return bool true if the slack token is set
     */
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

    /**
     * Handle review activity events
     * @param Event $event the event
     */
    public function handleReviewActivity(Event $event)
    {
        $review = $event->getParam('review');
        $activity = $event->getParam('activity');
        $quiet = $event->getParam('quiet');
        $data = (array) $event->getParam('data') + ['quiet' => null];

        // Skip if quiet mode or no activity
        if (!$activity || $quiet || $data['quiet']) {
            return;
        }

        try {
            $action = $activity->get(IActivity::ACTION);

            switch ($action) {
                case IActivity::REQUESTED:
                    // New review requested
                    $this->slackNotify->notifyNewReview($review);
                    break;

                case IActivity::COMMENTED:
                    // New comment added
                    $this->slackNotify->notifyNewComment($review, $activity);
                    break;

                default:
                    // Ignore other types of activity
                    break;
            }
        } catch (Exception $e) {
            $this->logger->err(
                sprintf(
                    "%s: Error processing review activity: %s",
                    self::LOG_PREFIX,
                    $e->getMessage()
                )
            );
        }
    }
}