<?php
namespace SlackNotify\Service;

use Activity\Model\Activity;
use Activity\Model\IActivity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Reviews\Model\Review;
use Record\Lock\Lock;
use Exception;
use Interop\Container\ContainerInterface;
use Users\Model\User;

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
        $this->logger->debug(sprintf(
            "%s: Starting notifyNewReview for review #%s",
            self::LOG_PREFIX,
            $review->getId()
        ));

        $config = $this->services->get(IDef::CONFIG);
        $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);

        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-review-' . $review->getId(), $p4Admin);

        try {
            $lock->lock();

            $reviewData = $review->getDescription();
            $this->logger->debug(sprintf(
                "%s: Review data: %s",
                self::LOG_PREFIX,
                json_encode($reviewData)
            ));

            $user = $review->getAuthorObject();
            $this->logger->debug(sprintf(
                "%s: Review user: %s",
                self::LOG_PREFIX,
                $user->getFullName()
            ));

            $description = $review->getDescription() ?? 'No description';
            $reviewers = $review->getReviewers() ?? [];

            $this->logger->debug(sprintf(
                "%s: Found reviewers: %s",
                self::LOG_PREFIX,
                implode(', ', $reviewers)
            ));

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
                            'text' => "*User:* {$user->getFullName()}\n*Description:* {$description}"
                        ]
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*Reviewers:* " . implode(', ', $reviewers)
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

            $this->logger->debug(sprintf(
                "%s: Sending Slack message for new review: %s",
                self::LOG_PREFIX,
                json_encode($message)
            ));

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

    public function notifyReviewNeedsReview(Review $review)
    {
        $this->logger->debug(sprintf(
            "%s: Starting notifyReviewNeedsReview for review #%s",
            self::LOG_PREFIX,
            $review->getId()
        ));

        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-needs-review-' . $review->getId(), $p4Admin);

        try {
            $lock->lock();
            $reviewers = $review->getReviewers();
            $author = $review->getAuthorObject();

            foreach ($reviewers as $reviewerId) {
                $userDao = $this->services->get(IDao::USER_DAO);
                if (!$userDao->exists($reviewerId, $p4Admin)) {
                    $this->logger->debug(sprintf(
                        "%s: User with id does not exist: %s",
                        self::LOG_PREFIX,
                        $reviewerId
                    ));
                    continue;
                }

                /** @var User $reviewer */
                $reviewer = $userDao->fetchById($reviewerId, $p4Admin);
                $slackUserId = $this->userMapping->getSlackUserId($reviewer);

                if ($slackUserId) {
                    $message = [
                        'channel' => $slackUserId,
                        'text' => "Review needs your attention",
                        'blocks' => [
                            [
                                'type' => 'header',
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => "Review #{$review->getId()} Needs Your Review"
                                ]
                            ],
                            [
                                'type' => 'section',
                                'text' => [
                                    'type' => 'mrkdwn',
                                    'text' => "*Author:* {$author->getFullName()}\n*Description:* {$review->getDescription()}"
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
                } else {
                    $config = $this->services->get(IDef::CONFIG);
                    $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
                    $this->notifyUserLookupFailure($channel, $reviewerId);
                }
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send review needs review notification: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        } finally {
            $lock->unlock();
        }
    }

    public function notifyReviewApproved(Review $review)
    {
        $this->logger->debug(sprintf(
            "%s: Starting notifyReviewApproved for review #%s",
            self::LOG_PREFIX,
            $review->getId()
        ));

        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-approved-' . $review->getId(), $p4Admin);

        try {
            $lock->lock();
            $author = $review->getAuthorObject();
            $slackUserId = $this->userMapping->getSlackUserId($author);

            if ($slackUserId) {
                $message = [
                    'channel' => $slackUserId,
                    'text' => "Your review has been approved",
                    'blocks' => [
                        [
                            'type' => 'header',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => "✅ Review #{$review->getId()} Approved"
                            ]
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "Your review has been approved! You can now commit your changes."
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
            } else {
                $config = $this->services->get(IDef::CONFIG);
                $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
                $this->notifyUserLookupFailure($channel, $author->getId());
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send review approved notification: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        } finally {
            $lock->unlock();
        }
    }

    public function notifyNewComment(Review $review, Activity $activity)
    {
        $this->logger->debug(sprintf(
            "%s: Starting notifyNewComment for review #%s",
            self::LOG_PREFIX,
            $review->getId()
        ));

        $commentAuthorId = $activity->getRawValue(IActivity::USER);
        $commentAction = $activity->getRawValue(IActivity::ACTION);

        // Log the activity values using proper methods
        $this->logger->debug(sprintf(
            "%s: Activity values - Author: %s, Action: %s",
            self::LOG_PREFIX,
            $commentAuthorId,
            $commentAction
        ));

        $config = $this->services->get(IDef::CONFIG);
        $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
        $p4      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $userDao = $this->services->get(IDao::USER_DAO);


        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-comment-' . $activity->getId(), $p4);

        try {
            $lock->lock();

            // Try to get comment text directly
            $commentText = $activity->get('description');
            $reviewAuthor = $review->getAuthorObject();
            $this->logger->debug(sprintf(
                "%s: Review owner: %s",
                self::LOG_PREFIX,
                $reviewAuthor->getFullName()
            ));

            // Notify reviewers
            $reviewers = $review->getReviewers();
            if (!empty($reviewers)) {
                foreach ($reviewers as $reviewerId) {
                    // Skip if the reviewer is also the comment author
                    if ($reviewerId === $commentAuthorId) {
                        $this->logger->debug(sprintf(
                            "%s: Skipping notification to reviewer %s (comment author)",
                            self::LOG_PREFIX,
                            $reviewerId
                        ));
                        continue;
                    }

                    if (!$userDao->exists($reviewerId, $p4)) {
                        $this->logger->debug(sprintf(
                            "%s: User with id does not exist: %s",
                            self::LOG_PREFIX,
                            $reviewerId
                        ));
                        continue;
                    }

                    // Retrieve user
                    /** @var User $reviewer */
                    $reviewer = $userDao->fetchById($reviewerId, $p4);
                    $this->logger->debug(sprintf(
                        "%s: Processing reviewer: %s",
                        self::LOG_PREFIX,
                        $reviewerId
                    ));

                    // Retrieve Slack user ID
                    $slackUserId = $this->userMapping->getSlackUserId($reviewer);
                    if ($slackUserId) {
                        $this->logger->debug(sprintf(
                            "%s: Found Slack user ID for reviewer %s: %s",
                            self::LOG_PREFIX,
                            $reviewerId,
                            $slackUserId
                        ));

                        // Build and post the Slack message
                        $message = $this->buildCommentMessage(
                            $slackUserId,
                            $review,
                            $commentAuthorId,
                            $commentText
                        );
                        $this->postToSlack($message);
                    } else {
                        // Log and handle missing Slack ID
                        $this->logger->debug(sprintf(
                            "%s: No Slack user ID found for reviewer %s",
                            self::LOG_PREFIX,
                            $reviewerId
                        ));
                        $this->notifyUserLookupFailure($channel, $reviewerId);
                    }
                }
            }

            // Notify review owner if they didn't make the comment
            if ($reviewAuthor->getId() !== $commentAuthorId) {
                $this->logger->debug(sprintf(
                    "%s: Attempting to notify review owner %s",
                    self::LOG_PREFIX,
                    $reviewAuthor->getId()
                ));

                $ownerSlackId = $this->userMapping->getSlackUserId($reviewAuthor);
                if ($ownerSlackId) {
                    $this->logger->debug(sprintf(
                        "%s: Found Slack user ID for review owner %s (email: %s): %s",
                        self::LOG_PREFIX,
                        $reviewAuthor->getFullName(),
                        $reviewAuthor->getEmail(),
                        $ownerSlackId
                    ));

                    $message = $this->buildCommentMessage(
                        $ownerSlackId,
                        $review,
                        $commentAuthorId,
                        $commentText
                    );
                    $this->postToSlack($message);
                } else {
                    $this->logger->debug(sprintf(
                        "%s: No Slack user ID found for review owner %s",
                        self::LOG_PREFIX,
                        $reviewAuthor->getFullName()
                    ));
                    $this->notifyUserLookupFailure($channel, $reviewAuthor->getId());
                }
            } else {
                $this->logger->debug(sprintf(
                    "%s: Skipping notification to review owner %s (comment author)",
                    self::LOG_PREFIX,
                    $reviewAuthor->getFullName()
                ));
            }

        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send comment notifications: %s\nStack trace: %s",
                self::LOG_PREFIX,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        } finally {
            $lock->unlock();
        }
    }

    public function notifyNewReply(Review $review, Activity $activity)
    {
        $this->logger->debug(sprintf(
            "%s: Starting notifyNewReply for review #%s",
            self::LOG_PREFIX,
            $review->getId()
        ));

        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-reply-' . $activity->getId(), $p4Admin);

        try {
            $lock->lock();

            // Get the comment author ID from the activity
            $replyAuthorId = $activity->getRawValue(IActivity::USER);
            $commentText = $activity->get('description') ?? 'No reply text available';

            // Get the original comment's author ID
            $originalCommentId = $activity->get('comment');
            if (!$originalCommentId) {
                $this->logger->debug(sprintf(
                    "%s: No original comment ID found in activity",
                    self::LOG_PREFIX
                ));
                return;
            }

            // Get the comment DAO to fetch the original comment
            $commentDao = $this->services->get(IDao::COMMENT_DAO);
            $originalComment = $commentDao->fetchById($originalCommentId, $p4Admin);
            if (!$originalComment) {
                $this->logger->debug(sprintf(
                    "%s: Original comment not found for ID: %s",
                    self::LOG_PREFIX,
                    $originalCommentId
                ));
                return;
            }

            $originalAuthorId = $originalComment->get('user');
            if ($originalAuthorId === $replyAuthorId) {
                $this->logger->debug(sprintf(
                    "%s: Skipping notification as reply author is the same as original comment author: %s",
                    self::LOG_PREFIX,
                    $replyAuthorId
                ));
                return;
            }

            // Get the user DAO to fetch the original comment author
            $userDao = $this->services->get(IDao::USER_DAO);
            if (!$userDao->exists($originalAuthorId, $p4Admin)) {
                $this->logger->debug(sprintf(
                    "%s: Original comment author does not exist: %s",
                    self::LOG_PREFIX,
                    $originalAuthorId
                ));
                return;
            }

            /** @var User $originalAuthor */
            $originalAuthor = $userDao->fetchById($originalAuthorId, $p4Admin);
            $slackUserId = $this->userMapping->getSlackUserId($originalAuthor);

            if ($slackUserId) {
                // Get the reply author's name for the notification
                $replyAuthor = $userDao->fetchById($replyAuthorId, $p4Admin);
                $replyAuthorName = $replyAuthor ? $replyAuthor->getFullName() : $replyAuthorId;

                // Build and send the message
                $message = [
                    'channel' => $slackUserId,
                    'text' => "New reply to your comment on Review #{$review->getId()}",
                    'blocks' => [
                        [
                            'type' => 'header',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => "💬 New Reply to Your Comment"
                            ]
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "*Review:* #{$review->getId()}\n*From:* {$replyAuthorName}\n*Reply:* {$commentText}"
                            ]
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "*Original Comment:* " . ($originalComment->get('description') ?? 'No comment text available')
                            ]
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "<" . $this->getReviewUrl($review) . "|View Discussion in Swarm>"
                            ]
                        ]
                    ]
                ];

                $this->postToSlack($message);
            } else {
                $config = $this->services->get(IDef::CONFIG);
                $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
                $this->notifyUserLookupFailure($channel, $originalAuthorId);
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send reply notification: %s",
                self::LOG_PREFIX,
                $e->getMessage()
            ));
        } finally {
            $lock->unlock();
        }
    }

    public function notifyTestStatus(Review $review, Activity $activity, array $testData = [])
    {
        $this->logger->debug(sprintf(
            "%s: Starting notifyTestStatus for review #%s with status %s",
            self::LOG_PREFIX,
            $review->getId(),
            $testData['status'] ?? 'unknown'
        ));

        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        // Create lock to prevent duplicate notifications
        $lock = new Lock('slacknotify-test-status-' . $review->getId(), $p4Admin);

        try {
            $lock->lock();
            $author = $review->getAuthorObject();
            $slackUserId = $this->userMapping->getSlackUserId($author);

            if ($slackUserId) {
                // Determine status icon and message based on test status
                $status = $testData['status'] ?? 'unknown';
                switch ($status) {
                    case 'pass':
                        $icon = '✅';
                        $statusText = 'Passed';
                        $contextMessage = "All tests have passed successfully.";
                        break;
                    case 'fail':
                        $icon = '❌';
                        $statusText = 'Failed';
                        $contextMessage = "Please check the test results and fix any failing tests.";
                        break;
                    case 'running':
                        $icon = '🔄';
                        $statusText = 'Running';
                        $contextMessage = "Tests are currently running.";
                        break;
                    default:
                        $icon = 'ℹ️';
                        $statusText = 'Unknown';
                        $contextMessage = "Test status is unknown.";
                }

                $message = [
                    'channel' => $slackUserId,
                    'text' => "Test status update for Review #{$review->getId()}",
                    'blocks' => [
                        [
                            'type' => 'header',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => "$icon Test Status: Review #{$review->getId()}"
                            ]
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "*Status:* Tests {$statusText}\n*Review:* {$review->getDescription()}"
                            ]
                        ]
                    ]
                ];

                // Add test URL if available
                if (!empty($testData['url'])) {
                    $message['blocks'][] = [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "<{$testData['url']}|View Test Results>"
                        ]
                    ];
                }

                // Add review URL
                $message['blocks'][] = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "<" . $this->getReviewUrl($review) . "|View Review in Swarm>"
                    ]
                ];

                // Add context message
                $message['blocks'][] = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => $contextMessage
                        ]
                    ]
                ];

                $this->postToSlack($message);
            } else {
                $config = $this->services->get(IDef::CONFIG);
                $channel = ConfigManager::getValue($config, 'slack-notify.notify_channel');
                $this->notifyUserLookupFailure($channel, $author->getId());
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to send test status notification: %s",
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
        string $commentAuthorId,
        string $commentText
    ): array {
        $message = [
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
                        'text' => "*From:* {$commentAuthorId}\n*Comment:* {$commentText}"
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

        $this->logger->debug(sprintf(
            "%s: Built comment message: %s",
            self::LOG_PREFIX,
            json_encode($message)
        ));

        return $message;
    }

    private function notifyUserLookupFailure(string $channel, string $userId)
    {
        $message = [
            'channel' => $channel,
            'text' => "Failed to find Slack user for Swarm user: {$userId}",
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "⚠️ Failed to find Slack user for Swarm user: {$userId}"
                    ]
                ]
            ]
        ];

        $this->logger->debug(sprintf(
            "%s: Sending user lookup failure notification for user %s",
            self::LOG_PREFIX,
            $userId
        ));

        $this->postToSlack($message);
    }

    private function getReviewUrl(Review $review): string
    {
        $config = $this->services->get(IDef::CONFIG);
        $hostname = ConfigManager::getValue($config, IDef::ENVIRONMENT_HOSTNAME);
        $externalURL = ConfigManager::getValue($config, IDef::ENVIRONMENT_EXTERNAL_URL);

        $baseUrl = $externalURL ?? "http://" . $hostname;
        $serverPath = P4_SERVER_ID ? P4_SERVER_ID . "/" : '';
        $url = $baseUrl . "/" . $serverPath . "reviews/" . $review->getId();

        $this->logger->debug(sprintf(
            "%s: Generated review URL: %s",
            self::LOG_PREFIX,
            $url
        ));

        return $url;
    }

    private function postToSlack(array $message)
    {
        $this->logger->debug(sprintf(
            "%s: Attempting to post message to Slack: %s",
            self::LOG_PREFIX,
            json_encode($message)
        ));

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
            $this->logger->err(sprintf(
                "%s: HTTP error posting to Slack: %s %s",
                self::LOG_PREFIX,
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));
            throw new Exception(
                "Failed to post to Slack: " . $response->getStatusCode() . " " . $response->getReasonPhrase()
            );
        }

        $result = json_decode($response->getBody(), true);
        if (!$result['ok']) {
            $this->logger->err(sprintf(
                "%s: Slack API error: %s",
                self::LOG_PREFIX,
                $result['error'] ?? 'Unknown error'
            ));
            throw new Exception("Slack API error: " . ($result['error'] ?? 'Unknown error'));
        }

        $this->logger->debug(sprintf(
            "%s: Successfully posted message to Slack",
            self::LOG_PREFIX
        ));
    }
}
