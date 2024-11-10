<?php
use Application\Config\IConfigDefinition as IDef;
use Application\Factory\InvokableServiceFactory;
use Events\Listener\ListenerFactory as EventListenerFactory;
use SlackNotify\Listener\ReviewActivity;
use SlackNotify\Service\ISlackNotify;
use SlackNotify\Service\IUserMapping;
use SlackNotify\Service\SlackNotify;
use SlackNotify\Service\UserMapping;
use SlackNotify\Model\SlackNotifyDAO;

$listeners = [ReviewActivity::class];

return [
    // Configuration namespace
    "slack-notify" => [
        // Channel for new review notifications
        'notify_channel' => 'swarm-reviews',
        // Cache time for user mappings (in seconds)
        'user_cache_ttl' => 3600,
    ],
    'listeners' => $listeners,
    'service_manager' => [
        'factories' => [
            ReviewActivity::class => EventListenerFactory::class,
            SlackNotify::class => InvokableServiceFactory::class,
            UserMapping::class => InvokableServiceFactory::class,
            SlackNotifyDAO::class => InvokableServiceFactory::class,
        ],
        'aliases' => [
            ISlackNotify::SERVICE_NAME => SlackNotify::class,
            IUserMapping::SERVICE_NAME => UserMapping::class,
            'slack-notify-dao' => SlackNotifyDAO::class
        ]
    ],
    EventListenerFactory::EVENT_LISTENER_CONFIG => [
        EventListenerFactory::TASK_COMMENT => [
            ReviewActivity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReviewActivity',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_REVIEW => [
            ReviewActivity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReviewActivity',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ]
    ]
];