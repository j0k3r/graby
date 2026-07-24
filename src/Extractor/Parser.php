<?php

declare(strict_types=1);

namespace Graby\Extractor;

enum Parser: string
{
    case Libxml = 'libxml';
    case Html5lib = 'html5lib';
}
