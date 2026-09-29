<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Extension;

use FKT\Component\Intercom\Administrator\Service\Runtime;
use Joomla\CMS\Extension\MVCComponent;

final class IntercomComponent extends MVCComponent
{
    public Runtime $runtime;
}
