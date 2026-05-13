<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'NEXUM | PoC')</title>
  <link rel="stylesheet" href="{{ asset('poc/styles.css') }}?v={{ filemtime(public_path('poc/styles.css')) }}">
  @stack('styles')
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand-block">
        <img class="brand-logo" src="{{ asset('poc/eggon_logo_43542.png') }}" alt="Eggon logo">
      </div>

      <nav class="side-nav" aria-label="@yield('nav-label', 'Navigazione')">
        @yield('sidebar-nav')
      </nav>
    </aside>

    <div class="workspace">
      <header class="topbar" id="workspace-top">
        <div>
          <p class="eyebrow">NEXUM PoC</p>
          <h2>@yield('header-title')</h2>
        </div>
        <div class="session-actions">
          @yield('header-actions')
        </div>
      </header>

      <main class="view-stack">
        @yield('content')
      </main>
    </div>
  </div>

  @yield('footer')

  @stack('scripts')
</body>
</html>
