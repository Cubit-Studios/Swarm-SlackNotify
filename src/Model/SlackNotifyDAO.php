<?php

namespace SlackNotify\Model;

use Application\Model\AbstractDAO;

/**
 * DAO for notification tracking to prevent duplicates
 */
class SlackNotifyDAO extends AbstractDAO implements ISlackNotifyDAO
{
    const MODEL = SlackNotify::class;
}
