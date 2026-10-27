<?php

namespace App\Services\Telegram;

enum LinkResult
{
    case Linked;
    case Expired;
    case Taken;
}
