@extends('poc.layout')

@section('nav-label', 'Navigazione PoC')

@section('sidebar-nav')
    @include('poc.partials.app-nav')
@endsection

@section('header-title')
    <span id="view-title">Overview operativa</span>
@endsection

@section('header-actions')
    <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Attiva tema scuro" aria-pressed="false">
        <span class="theme-icon theme-icon-sun" aria-hidden="true">☀</span>
        <span class="theme-icon theme-icon-moon" aria-hidden="true">☾</span>
    </button>
@endsection

@section('content')
    @include('poc.partials.views.overview')
    @include('poc.partials.views.assistant')
    @include('poc.partials.views.copilot')
@endsection

@section('footer')
    <button class="back-to-top" id="back-to-top" type="button" aria-label="Torna su">↑</button>
@endsection

@push('scripts')
    <script src="{{ asset('poc/app.js') }}?v={{ filemtime(public_path('poc/app.js')) }}"></script>
@endpush
