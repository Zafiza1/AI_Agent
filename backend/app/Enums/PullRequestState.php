<?php

namespace App\Enums;

enum PullRequestState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
}
