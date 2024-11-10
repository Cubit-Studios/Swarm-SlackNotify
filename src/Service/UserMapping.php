<?php
namespace SlackNotify\Service;

use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Exception;
use Interop\Container\ContainerInterface;

class UserMapping implements IUserMapping, InvokableService
{
    private $services;
    private $logger;
    private $cache = [];

    const LOG_PREFIX = UserMapping::class;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->logger = $services->get(SwarmLogger::SERVICE);
    }

    public function getSlackUserId(string $email): ?string
    {
        // Check cache first
        if (isset($this->cache[$email])) {
            list($userId, $timestamp) = $this->cache[$email];
            $config = $this->services->get(IDef::CONFIG);
            $ttl = ConfigManager::getValue($config, 'slack-notify.user_cache_ttl', 3600);

            if (time() - $timestamp < $ttl) {
                return $userId;
            }
        }

        try {
            $userId = $this->lookupUserByEmail($email);
            if ($userId) {
                $this->cache[$email] = [$userId, time()];
            }
            return $userId;
        } catch (Exception $e) {
            $this->logger->err(sprintf(
                "%s: Failed to lookup Slack user for email %s: %s",
                self::LOG_PREFIX,
                $email,
                $e->getMessage()
            ));
            return null;
        }
    }

    private function lookupUserByEmail(string $email): ?string
    {
        $config = $this->services->get(IDef::CONFIG);
        $token = ConfigManager::getValue($config, 'slack-notify.token');

        $client = new Client();
        $client->setUri('https://slack.com/api/users.lookupByEmail');
        $client->setMethod(Request::METHOD_GET);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $token
        ]);
        $client->setParameterGet(['email' => $email]);

        $response = $client->send();

        if (!$response->isSuccess()) {
            throw new Exception(
                "Failed to lookup user: " . $response->getStatusCode() . " " . $response->getReasonPhrase()
            );
        }

        $result = json_decode($response->getBody(), true);
        if (!$result['ok']) {
            $this->logger->debug(sprintf(
                "%s: Slack API error for email %s: %s",
                self::LOG_PREFIX,
                $email,
                $result['error'] ?? 'Unknown error'
            ));
            return null;
        }

        return $result['user']['id'] ?? null;
    }
}