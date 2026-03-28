@php $maintenanceMode = \App\Models\Setting::get('maintenance_mode', false); @endphp
<nav x-data="{
        open: false,
        compact: false,
        hidden: false,
        lastY: 0,
        init() { this.lastY = window.scrollY; },
        onScroll() {
            const y = window.scrollY;
            if (y > this.lastY) {
                this.compact = true;
                if (y > 100) this.hidden = true;
            } else if (y < this.lastY) {
                this.hidden = false;
            }
            this.compact = y >= 10;
            if (y < 10) this.hidden = false;
            this.lastY = y;
        }
    }"
    @scroll.window.throttle.16ms="onScroll()"
    @scrollend.window="onScroll()"
    :class="{ '-translate-y-full': hidden }"
    class="fixed top-0 left-0 right-0 z-40 bg-white border-b border-gray-100 shadow-sm transition-transform duration-300 ease-in-out {{ $maintenanceMode ? 'maintenance-mode' : '' }}">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 transition-[height] duration-300 ease-in-out"
             :class="compact ? '!h-10' : ''">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center nav-logo">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 md:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <div class="relative inline-flex items-stretch" x-data="{ submenu: false }" @mouseenter="submenu = true" @mouseleave="submenu = false">
                        <x-nav-link :href="route('assets.index')" :active="request()->routeIs('assets.*') || request()->routeIs('discover.*') || request()->routeIs('export.*')" @click="if ('ontouchstart' in window && !submenu) { $event.preventDefault(); submenu = true }">
                            {{ __('Assets') }}
                            <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </x-nav-link>

                        <div x-show="submenu"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute top-full left-0 mt-0 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 py-1 z-50"
                             style="display: none;">
                            <a href="{{ route('assets.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('assets.index') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                <i class="fas fa-images fa-fw mr-2 {{ request()->routeIs('assets.index') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Browse') }}
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="{{ route('assets.create') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('assets.create') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                <i class="fas fa-cloud-arrow-up fa-fw mr-2 {{ request()->routeIs('assets.create') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Upload') }}
                            </a>
                            @can('restore', App\Models\Asset::class)
                                <a href="{{ route('assets.trash') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('assets.trash') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <i class="fas fa-trash fa-fw mr-2 {{ request()->routeIs('assets.trash') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Trash') }}
                                </a>
                            @endcan
                            @can('discover', App\Models\Asset::class)
                                <a href="{{ route('discover.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('discover.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <i class="fas fa-satellite-dish fa-fw mr-2 {{ request()->routeIs('discover.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Discover') }}
                                </a>
                            @endcan
                            @can('export', App\Models\Asset::class)
                                <a href="{{ route('export.index') }}" class="block px-4 py-2 text-sm {{ request()->routeIs('export.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <i class="fas fa-file-export fa-fw mr-2 {{ request()->routeIs('export.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Export') }}
                                </a>
                            @endcan
                        </div>
                    </div>
                    <x-nav-link :href="route('tags.index')" :active="request()->routeIs('tags.*')">
                        {{ __('Tags') }}
                    </x-nav-link>
                    @can('viewAny', App\Models\User::class)
                        <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                            {{ __('Users') }}
                        </x-nav-link>
                    @endcan
                    <x-nav-link :href="route('about.index')" :active="request()->routeIs('about.*')">
                        {{ __('About ORCA') }}
                    </x-nav-link>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden md:flex md:items-center md:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <a href="{{ route('profile.edit') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 {{ request()->routeIs('profile.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }} focus:outline-none transition duration-150 ease-in-out">
                            <i class="fas fa-user fa-fw mr-2 {{ request()->routeIs('profile.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Profile') }}
                        </a>

                        @can('access', App\Http\Controllers\SystemController::class)
                            <a href="{{ route('system.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 {{ request()->routeIs('system.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }} focus:outline-none transition duration-150 ease-in-out">
                                <i class="fas fa-cog fa-fw mr-2 {{ request()->routeIs('system.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('System') }}
                            </a>
                            <a href="{{ route('api.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 {{ request()->routeIs('api.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }} focus:outline-none transition duration-150 ease-in-out">
                                <i class="fas fa-code fa-fw mr-2 {{ request()->routeIs('api.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('API') }}
                            </a>
                            <a href="{{ route('import.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 {{ request()->routeIs('import.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }} focus:outline-none transition duration-150 ease-in-out">
                                <i class="fas fa-file-import fa-fw mr-2 {{ request()->routeIs('import.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Import') }}
                            </a>
                        @endcan

                        <a href="{{ route('tools.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 {{ request()->routeIs('tools.*') ? 'bg-gray-100 text-orca-teal-hover font-medium' : 'text-gray-700 hover:bg-gray-100' }} focus:outline-none transition duration-150 ease-in-out">
                            <i class="fas fa-wrench fa-fw mr-2 {{ request()->routeIs('tools.*') ? 'text-orca-teal' : 'text-gray-400' }}"></i>{{ __('Tools') }}
                        </a>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <a href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none transition duration-150 ease-in-out">
                                <i class="fas fa-arrow-right-from-bracket fa-fw mr-2 text-gray-400"></i>{{ __('Log Out') }}
                            </a>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center md:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <div x-data="{ open: {{ request()->routeIs('assets.*') || request()->routeIs('discover.*') || request()->routeIs('export.*') ? 'true' : 'false' }} }">
                <button @click="open = !open"
                        class="w-full flex justify-between items-center ps-3 pe-4 py-2 border-l-4 text-start text-base font-medium transition duration-150 ease-in-out focus:outline-none
                               {{ request()->routeIs('assets.*') || request()->routeIs('discover.*') || request()->routeIs('export.*') ? 'border-orca-teal text-orca-teal-hover bg-teal-50 focus:text-orca-teal-hover focus:bg-teal-100 focus:border-orca-teal' : 'border-transparent text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300' }}">
                    <span>{{ __('Assets') }}</span>
                    <svg :class="{ 'rotate-180': open }" class="h-4 w-4 transition-transform duration-200 {{ request()->routeIs('assets.*') || request()->routeIs('discover.*') || request()->routeIs('export.*') ? 'text-orca-teal' : 'text-gray-400' }}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div x-show="open">
                    <x-responsive-nav-link :href="route('assets.index')" :active="request()->routeIs('assets.index')" class="ps-8">
                        <i class="fas fa-images fa-fw mr-2 text-gray-400"></i>{{ __('Browse') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('assets.create')" :active="request()->routeIs('assets.create')" class="ps-8">
                        <i class="fas fa-cloud-arrow-up fa-fw mr-2 text-gray-400"></i>{{ __('Upload') }}
                    </x-responsive-nav-link>
                    @can('restore', App\Models\Asset::class)
                        <x-responsive-nav-link :href="route('assets.trash')" :active="request()->routeIs('assets.trash')" class="ps-8">
                            <i class="fas fa-trash fa-fw mr-2 text-gray-400"></i>{{ __('Trash') }}
                        </x-responsive-nav-link>
                    @endcan
                    @can('discover', App\Models\Asset::class)
                        <x-responsive-nav-link :href="route('discover.index')" :active="request()->routeIs('discover.*')" class="ps-8">
                            <i class="fas fa-satellite-dish fa-fw mr-2 text-gray-400"></i>{{ __('Discover') }}
                        </x-responsive-nav-link>
                    @endcan
                    @can('export', App\Models\Asset::class)
                        <x-responsive-nav-link :href="route('export.index')" :active="request()->routeIs('export.*')" class="ps-8">
                            <i class="fas fa-file-export fa-fw mr-2 text-gray-400"></i>{{ __('Export') }}
                        </x-responsive-nav-link>
                    @endcan
                </div>
            </div>
            <x-responsive-nav-link :href="route('tags.index')" :active="request()->routeIs('tags.*')">
                {{ __('Tags') }}
            </x-responsive-nav-link>
            @can('viewAny', App\Models\User::class)
                <x-responsive-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                    {{ __('Users') }}
                </x-responsive-nav-link>
            @endcan
            <x-responsive-nav-link :href="route('about.index')" :active="request()->routeIs('about.*')">
                {{ __('About ORCA') }}
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200" x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex justify-between items-center px-4 py-2 text-start focus:outline-none hover:bg-gray-50">
                <div>
                    <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
                </div>
                <svg :class="{ 'rotate-180': open }" class="h-4 w-4 text-gray-400 transition-transform duration-200 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>

            <div x-show="open" class="mt-1 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">
                    <i class="fas fa-user fa-fw mr-2"></i>{{ __('Profile') }}
                </x-responsive-nav-link>

                @can('access', App\Http\Controllers\SystemController::class)
                    <x-responsive-nav-link :href="route('system.index')" :active="request()->routeIs('system.*')">
                        <i class="fas fa-cog fa-fw mr-2"></i>{{ __('System') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('api.index')" :active="request()->routeIs('api.*')">
                        <i class="fas fa-code fa-fw mr-2"></i>{{ __('API') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('import.index')" :active="request()->routeIs('import.*')">
                        <i class="fas fa-file-import fa-fw mr-2"></i>{{ __('Import') }}
                    </x-responsive-nav-link>
                @endcan

                <x-responsive-nav-link :href="route('tools.index')" :active="request()->routeIs('tools.*')">
                    <i class="fas fa-wrench fa-fw mr-2"></i>{{ __('Tools') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        <i class="fas fa-arrow-right-from-bracket fa-fw mr-2"></i>{{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
