<?php

namespace App\Poc\Controllers;

use Illuminate\View\View;

class PocController
{
    /**
     * Show the PoC application dashboard.
     */
    public function index(): View
    {
        return view('poc.app');
    }
}
