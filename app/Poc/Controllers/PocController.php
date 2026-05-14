<?php

namespace App\Poc\Controllers;

use Illuminate\View\View;

class PocController
{
    public function index(): View
    {
        return view('poc.app');
    }
}
