<?php

namespace App\Poc\Controllers;

use Illuminate\View\View;

/**
 * Controller for the main PoC application dashboard.
 */
class PocController
{
    /**
     * Show the PoC application dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('poc.app');
    }
}
