<?php

namespace Westel\License\Exceptions;

class LicenseExpiredException extends LicenseException
{
    protected $message = 'License has expired';
}
