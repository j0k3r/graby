<?php

declare(strict_types=1);

namespace Graby\Config;

enum ContentLinks
{
    case Preserve;
    case Footnotes;
    case Remove;
}
