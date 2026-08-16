<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class PromptConfigurationNameTakenException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Esiste già una configurazione con questo nome.');
    }
}
