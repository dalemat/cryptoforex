<?php

use CryptoForex\GroupManager\Console\GroupManagerCommand;
use Flarum\Extend;

return [
    (new Extend\Console())
        ->command(GroupManagerCommand::class),
];
