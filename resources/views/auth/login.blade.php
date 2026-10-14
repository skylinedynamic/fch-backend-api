<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Core Authentication - FCH Internal Systems</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .mesh-gradient {
            background-color: #0f172a;
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,15%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%),
                radial-gradient(at 0% 100%, hsla(253,16%,15%,1) 0, transparent 50%),
                radial-gradient(at 50% 100%, hsla(225,39%,30%,1) 0, transparent 50%),
                radial-gradient(at 100% 100%, hsla(339,49%,30%,1) 0, transparent 50%);
        }
        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .animate-float { animation: float 6s ease-in-out infinite; }
    </style>
</head>
<body class="mesh-gradient min-h-screen flex items-center justify-center p-6 overflow-hidden relative">
    <!-- Decorative Elements -->
    <div class="absolute top-0 left-0 w-full h-full pointer-events-none overflow-hidden">
        <div class="absolute -top-[10%] -left-[10%] w-[40%] h-[40%] bg-indigo-500/10 rounded-full blur-[120px] animate-pulse"></div>
        <div class="absolute -bottom-[10%] -right-[10%] w-[40%] h-[40%] bg-purple-500/10 rounded-full blur-[120px] animate-pulse" style="animation-delay: 2s;"></div>
    </div>

    <div class="max-w-md w-full relative z-10 animate-fade-in">
        <!-- Branding -->
        <div class="text-center mb-10 group">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-slate-900 rounded-2xl border border-white/5 mb-6 shadow-2xl glass-panel group-hover:border-indigo-500/50 transition-colors duration-500">
                <svg class="w-8 h-8 text-indigo-400 group-hover:scale-110 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <h1 class="text-4xl font-black text-white tracking-tighter mb-2 uppercase"><span class="mr-2">FCH</span> System <span class="text-indigo-500 italic">Pulse</span></h1>
            <p class="text-slate-400 font-bold text-xs tracking-widest uppercase opacity-60">Unified Infrastructure Gateway</p>
        </div>

        <div class="glass-panel rounded-3xl p-8 shadow-2xl border border-white/10 relative overflow-hidden">
            <!-- Inner Glow -->
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent"></div>
            
            @if ($errors->any())
                <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-black uppercase tracking-widest rounded-xl p-4 mb-8">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li class="flex items-center">
                                <span class="w-1.5 h-1.5 bg-rose-500 rounded-full mr-2"></span>
                                {{ $error }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-6">
                @csrf
                <div class="space-y-2">
                    <label for="email" class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] ml-1">Workstation Identity</label>
                    <div class="relative group">
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                            class="w-full px-5 py-4 bg-slate-950/50 border border-white/5 rounded-2xl focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 text-white placeholder-slate-600 transition-all outline-none font-medium"
                            placeholder="operator@fch.internal">
                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none opacity-20 group-focus-within:opacity-100 transition-opacity">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="password" class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] ml-1">Access Cipher</label>
                    <div class="relative group">
                        <input type="password" id="password" name="password" required
                            class="w-full pl-5 pr-14 py-4 bg-slate-950/50 border border-white/5 rounded-2xl focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 text-indigo-200 placeholder-slate-600 transition-all outline-none font-medium text-lg tracking-widest"
                            placeholder="••••••••">
                        <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false"
                            class="absolute inset-y-0 right-2 flex items-center px-2 rounded-xl text-indigo-400 opacity-40 hover:opacity-100 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/50 transition-opacity">
                            <svg id="icon-eye" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg id="icon-eye-off" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between px-1">
                    <div class="flex items-center">
                        <input type="checkbox" id="remember" name="remember"
                            class="w-5 h-5 rounded-lg border-white/10 bg-slate-950/50 text-indigo-600 focus:ring-indigo-500/50 focus:ring-offset-slate-900 transition-all cursor-pointer">
                        <label for="remember" class="ml-3 text-[11px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer select-none">Persist Session</label>
                    </div>
                </div>

                <button type="submit"
                    class="group relative w-full flex justify-center py-4 px-6 rounded-2xl text-xs font-black text-white bg-indigo-600 hover:bg-indigo-500 transition-all duration-300 transform active:scale-[0.98] shadow-lg shadow-indigo-600/20 overflow-hidden">
                    <span class="relative z-10 uppercase tracking-[0.3em] font-black">Initialize Interface</span>
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-1000"></div>
                </button>
            </form>
        </div>

        <!-- Footer Info -->
        <div class="mt-12 text-center">
            <div class="inline-flex items-center space-x-2 px-4 py-2 bg-white/5 rounded-full border border-white/5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]"></span>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em]">All Systems Nominal</span>
            </div>
            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-6 opacity-40">
                &copy; {{ date('Y') }} FCH INTERNAL • ENCRYPTED GATEWAY
            </p>
        </div>
    </div>

    <style>
        .animate-fade-in {
            animation: fadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <script>
        (function () {
            const input = document.getElementById('password');
            const toggle = document.getElementById('toggle-password');
            const eye = document.getElementById('icon-eye');
            const eyeOff = document.getElementById('icon-eye-off');

            toggle.addEventListener('click', function () {
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                eye.classList.toggle('hidden', !showing);
                eyeOff.classList.toggle('hidden', showing);
                toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
                input.focus();
            });

            // Never submit with the password left visible on screen.
            toggle.form.addEventListener('submit', function () {
                input.type = 'password';
            });
        })();
    </script>
</body>
</html>
