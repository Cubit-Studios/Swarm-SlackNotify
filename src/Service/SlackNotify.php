<?php
namespace SlackNotify\Service;

use Activity\Model\Activity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Reviews\Model\Review;
use Record\Lock\Lock;
use Record\Key\AbstractKey;
use Exception;
use Interop\Container\ContainerInterface;

class SlackNotify implements ISlackNotify, InvokableService
{
    private $services;
    private $logger;
    private $userMapping;
    const LOG_PREFIX = SlackNotify::class;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->logger = $services->get(SwarmLogger::SERVICE);
        $this->userMapping = $services->get(IUserMapping::SERVICE_NAME);
    }

    public function notifyNewReview(Review $review)
    {
        $config = $this->services->get(IDef::CONFIG);
        $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);

        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-review-' . $review->getId(), $p4Admin);

        try {
            $lock->lock();

            $reviewers = $review->getReviewers();
            $author = $review->getAuthor();
            $description = $review->getDescription();

            $message = [
                'channel' => $channel,
                'text' => "New review #{$review->getId()} requested",
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => "New Review #{$review->getId()}"
                        ]
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*Author:* {$author}\n*Description:* {$description}"
                        ]
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*Reviewers:* " . implode(', ', array_keys($reviewers))
                        ]
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "<" . $this->getReviewUrl($review) . "|View Review in Swarm>"
                        ]
                    ]
                ]
            ];

            $this->postToSlack($message);

        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send new review notification: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        } finally {
            $lock->unlock();
        }
    }

    public function notifyNewComment(Review $review, Activity $activity)
    {
        $config = $this->services->get(IDef::CONFIG);
        $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);

        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-comment-' . $activity->getId(), $p4Admin);

        try {
            $lock->lock();

            $commentAuthor = $activity->get(IActivity::USER);
            $commentText = $activity->get('body');
            $reviewAuthor = $review->getAuthor();

            // Notify reviewers
            foreach ($review->getReviewers() as $reviewer => $status) {
                // Skip if reviewer is the comment author
                if ($reviewer === $commentAuthor) {
                    continue;
                }

                $slackUserId = $this->userMapping->getSlackUserId($reviewer);
                if ($slackUserId) {
                    $message = $this->buildCommentMessage(
                        $slackUserId,
                        $review,
                        $commentAuthor,
                        $commentText
                    );
                    $this->postToSlack($message);
                } else {
                    // Log failure to find user
                    $this->notifyUserLookupFailure($channel, $reviewer);
                }
            }

            // Notify review author if they didn't make the comment
            if ($reviewAuthor !== $commentAuthor) {
                $authorSlackId = $this->userMapping->getSlackUserId($reviewAuthor);
                if ($authorSlackId) {
                    $message = $this->buildCommentMessage(
                        $authorSlackId,
                        $review,
                        $commentAuthor,
                        $commentText
                    );
                    $this->postToSlack($message);
                } else {
                    // Log failure to find user
                    $this->notifyUserLookupFailure($channel, $reviewAuthor);
                }
            }

        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send comment notifications: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        } finally {
            $lock->unlock();
        }
    }

    private function buildCommentMessage(
        string $slackUserId,
        Review $review,
        string $commentAuthor,
        string $commentText
    ): array {
        return [
            'channel' => $slackUserId,
            'text' => "New comment on review #{$review->getId()}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => "New Comment on Review #{$review->getId()}"
                    ]
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*From:* {$commentAuthor}\n*Comment:* {$commentText}"
                    ]
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "<" . $this->getReviewUrl($review) . "|View Review in Swarm>"
                    ]
                ]
            ]
        ];
    }

    private function notifyUserLookupFailure(string $channel, string $user)
    {
        $message = [
            'channel' => $channel,
            'text' => "Failed to find Slack user for Swarm user: {$user}",
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "⚠️ Failed to find Slack user for Swarm user: {$user}"
                    ]
                ]
            ]
        ];

        $this->postToSlack($message);
    }

    private function getReviewUrl(Review $review): string
    {
        $config = $this->services->get(IDef::CONFIG);
        $hostname = ConfigManager::getValue($config, IDef::ENVIRONMENT_HOSTNAME);
        $externalURL = ConfigManager::getValue($config, IDef::ENVIRONMENT_EXTERNAL_URL);

        $baseUrl = $externalURL ?? "http://" . $hostname;
        $serverPath = P4_SERVER_ID ? P4_SERVER_ID . "/" : '';

        return $baseUrl . "/" . $serverPath . "reviews/" . $review->getId();
    }

    private function postToSlack(array $message)
    {
        $config = $this->services->get(IDef::CONFIG);
        $token = ConfigManager::getValue($config, 'slack-notify.token');

        $client = new Client();
        $client->setUri('https://slack.com/api/chat.postMessage');
        $client->setMethod(Request::METHOD_POST);
        $client->setHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ]);
        $client->setRawBody(json_encode($message));

        $response = $client->send();

        if (!$response->isSuccess()) {
            throw new Exception(
                "Failed to post to Slack: " . $response->getStatusCode() . " " . $response->getReasonPhrase()
            );
        }

        $result = json_decode($response->getBody(), true);
        if (!$result['ok']) {
            throw new Exception("Slack API error: " . ($result['error'] ?? 'Unknown error'));
        }
    }
}