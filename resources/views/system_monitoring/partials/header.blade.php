@php
    $currentRoute = Route::currentRouteName();
    $pageTitle = match($currentRoute) {
        'dashboard' => 'System Overview',
        'dashboard.logs' => 'System Monitoring',
        'dashboard.docs.admin' => 'API Reference (Admin)',
        'dashboard.docs.borrower' => 'API Reference (Borrower)',
        default => 'Management Console'
    };
@endphp

<header class="h-20 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between px-10 shadow-sm z-10 transition-colors duration-200">
    <div>
        <h2 class="text-sm font-bold text-slate-400 dark:text-slate-500 uppercase tracking-[0.2em]">
            {{ $pageTitle }}
        </h2>
        <div class="flex items-center mt-0.5">
            <div class="w-2 h-2 rounded-full {{ app()->isLocal() ? 'bg-amber-500' : 'bg-emerald-500' }} animate-pulse mr-2 shadow-[0_0_8px_rgba(16,185,129,0.5)]"></div>
            <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">{{ ucfirst(app()->environment()) }} Environment</span>
        </div>
    </div>
    
    <div class="flex items-center space-x-6">
        <!-- Theme Toggle -->
        <button id="theme-toggle" type="button" class="text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 focus:outline-none transition-colors p-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800">
            <!-- Dark icon -->
            <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
            <!-- Light icon -->
            <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
        </button>

        <div class="flex items-center space-x-4 px-6 border-x border-slate-200 dark:border-slate-800">
            <div class="text-right">
                <p class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">PHP Server</p>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">v{{ phpversion() }}</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">{{ now()->format('l') }}</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">{{ now()->format('M d, Y') }}</p>
        </div>
    </div>
</header>

<script>
    var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
    var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

    // Change the icons inside the button based on previous settings
    if (!('theme' in localStorage) || localStorage.getItem('theme') === 'dark') {
        themeToggleLightIcon.classList.remove('hidden');
    } else {
        themeToggleDarkIcon.classList.remove('hidden');
    }

    var themeToggleBtn = document.getElementById('theme-toggle');

    themeToggleBtn.addEventListener('click', function() {
        // toggle icons inside button
        themeToggleDarkIcon.classList.toggle('hidden');
        themeToggleLightIcon.classList.toggle('hidden');

        // if set via local storage previously
        if (localStorage.getItem('theme')) {
            if (localStorage.getItem('theme') === 'light') {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            }
        // if NOT set via local storage previously
        } else {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }
    });
</script>
