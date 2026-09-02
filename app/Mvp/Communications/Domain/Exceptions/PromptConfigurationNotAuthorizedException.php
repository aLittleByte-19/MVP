<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class PromptConfigurationNotAuthorizedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Prompt configuration is outside the authenticated tenant scope.');
    }
}
