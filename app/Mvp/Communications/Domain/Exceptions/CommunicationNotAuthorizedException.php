<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationNotAuthorizedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Communication is outside the authenticated tenant scope.');
    }
}
