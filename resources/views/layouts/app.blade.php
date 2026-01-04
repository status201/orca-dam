<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'ORCA DAM') }} - @yield('title')</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    
    <style>
        [x-cloak] { display: none !important; }
        
        .toast {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="bg-gray-50" x-data="{ 
    mobileMenuOpen: false,
    showToast: false,
    toastMessage: '',
    toastType: 'success'
}">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo and primary nav -->
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <a href="{{ route('assets.index') }}" class="flex items-center space-x-2">
                            <i class="fas fa-water text-blue-600 text-2xl"></i>
                            <span class="text-xl font-bold text-gray-900">ORCA</span>
                        </a>
                    </div>
                    
                    <!-- Desktop menu -->
                    <div class="hidden md:ml-6 md:flex md:space-x-8">
                        <a href="{{ route('assets.index') }}" class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('assets.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} text-sm font-medium">
                            <i class="fas fa-images mr-2"></i> Assets
                        </a>
                        
                        <a href="{{ route('tags.index') }}" class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('tags.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} text-sm font-medium">
                            <i class="fas fa-tags mr-2"></i> Tags
                        </a>
                        
                        @if(auth()->user()->isAdmin())
                        <a href="{{ route('discover.index') }}" class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('discover.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} text-sm font-medium">
                            <i class="fas fa-search mr-2"></i> Discover
                        </a>
                        @endif
                    </div>
                </div>
                
                <!-- Right side menu -->
                <div class="hidden md:ml-6 md:flex md:items-center md:space-x-4">
                    <a href="{{ route('assets.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-upload mr-2"></i> Upload
                    </a>
                    
                    <div class="flex items-center space-x-2">
                        <span class="text-sm text-gray-700">{{ auth()->user()->name }}</span>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full {{ auth()->user()->isAdmin() ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800' }}">
                            {{ auth()->user()->role }}
                        </span>
                    </div>
                    
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-gray-700">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
                
                <!-- Mobile menu button -->
                <div class="flex items-center md:hidden">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                        <i class="fas" :class="mobileMenuOpen ? 'fa-times' : 'fa-bars'"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile menu -->
        <div x-show="mobileMenuOpen" x-cloak class="md:hidden border-t border-gray-200">
            <div class="pt-2 pb-3 space-y-1">
                <a href="{{ route('assets.index') }}" class="block pl-3 pr-4 py-2 border-l-4 {{ request()->routeIs('assets.*') ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300' }} text-base font-medium">
                    <i class="fas fa-images mr-2"></i> Assets
                </a>
                
                <a href="{{ route('tags.index') }}" class="block pl-3 pr-4 py-2 border-l-4 {{ request()->routeIs('tags.*') ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300' }} text-base font-medium">
                    <i class="fas fa-tags mr-2"></i> Tags
                </a>
                
                @if(auth()->user()->isAdmin())
                <a href="{{ route('discover.index') }}" class="block pl-3 pr-4 py-2 border-l-4 {{ request()->routeIs('discover.*') ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300' }} text-base font-medium">
                    <i class="fas fa-search mr-2"></i> Discover
                </a>
                @endif
                
                <a href="{{ route('assets.create') }}" class="block pl-3 pr-4 py-2 border-l-4 border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300 text-base font-medium">
                    <i class="fas fa-upload mr-2"></i> Upload
                </a>
            </div>
            
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-4">
                    <div class="flex-1">
                        <div class="text-base font-medium text-gray-800">{{ auth()->user()->name }}</div>
                        <div class="text-sm font-medium text-gray-500">{{ auth()->user()->email }}</div>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full {{ auth()->user()->isAdmin() ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800' }}">
                        {{ auth()->user()->role }}
                    </span>
                </div>
                
                <div class="mt-3 space-y-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full text-left px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @if(session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>{{ session('success') }}</span>
                </div>
                <button @click="show = false" class="text-green-600 hover:text-green-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        @endif
        
        @if(session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span>{{ session('error') }}</span>
                </div>
                <button @click="show = false" class="text-red-600 hover:text-red-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        @endif
        
        @yield('content')
    </main>
    
    <!-- Toast notification (for JS events) -->
    <div x-show="showToast" x-cloak 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform translate-x-full"
         x-transition:enter-end="opacity-100 transform translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 transform translate-x-0"
         x-transition:leave-end="opacity-0 transform translate-x-full"
         class="fixed bottom-4 right-4 z-50">
        <div :class="toastType === 'success' ? 'bg-green-500' : 'bg-red-500'" 
             class="text-white px-6 py-4 rounded-lg shadow-lg toast max-w-sm">
            <div class="flex items-center justify-between">
                <span x-text="toastMessage"></span>
                <button @click="showToast = false" class="ml-4 text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Global toast function
        window.showToast = function(message, type = 'success') {
            const event = new CustomEvent('show-toast', { 
                detail: { message, type } 
            });
            window.dispatchEvent(event);
        };
        
        window.addEventListener('show-toast', (e) => {
            const app = document.querySelector('[x-data]').__x.$data;
            app.toastMessage = e.detail.message;
            app.toastType = e.detail.type;
            app.showToast = true;
            
            setTimeout(() => {
                app.showToast = false;
            }, 3000);
        });
        
        // Copy to clipboard helper
        window.copyToClipboard = function(text) {
            navigator.clipboard.writeText(text).then(() => {
                window.showToast('URL copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
                window.showToast('Failed to copy URL', 'error');
            });
        };
    </script>
    
    @stack('scripts')
</body>
</html>
