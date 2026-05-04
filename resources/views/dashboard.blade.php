@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <div class="mb-6 text-center">
                <div class="flex items-center justify-center mb-6">
                    <div class="dashboard-logo bg-white rounded-full p-2 shadow-lg cursor-pointer"
                         onclick="const footerOrca = document.querySelector('.footer-logo-container svg'); if(footerOrca) { footerOrca.classList.add('orca-jump'); setTimeout(() => footerOrca.classList.remove('orca-jump'), 1100); }">

                        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="h-32 w-32 fill-current text-gray-800 orca-floating">
                            <!-- Body -->
                            <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                            <!-- Tail -->
                            <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                            <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                            <!-- Dorsal fin -->
                            <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                            <!-- White belly patch -->
                            <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                            <!-- White eye patch -->
                            <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                            <!-- Eye (with blink) -->
                            <g class="orca-eye-blink">
                                <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                                <circle cx="69" cy="47" r="1" fill="white"/>
                            </g>
                            <!-- Smile -->
                            <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                            <!-- Pectoral fin -->
                            <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
                        </svg>

                    </div>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 mb-3">ORCA DAM</h1>
                <p class="text-xl text-gray-600 pb-8"><span>ORCA</span> <span>{{ __('Retrieves') }}</span> <span>{{ __('Cloud') }}</span> <span>{{ __('Assets') }}</span></p>
            </div>

            <!-- Two Column Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                <!-- Left Column: Statistics -->
                <div>
                    <div class="grid grid-cols-1 gap-4 mb-6">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                {{ __('Hi') }} <strong>{{ Auth::user()->name }}</strong>!<br />
                                {{ __('You\'re logged in with') }} <strong>{{ Auth::user()->email }}</strong>
                                {{ __('as an') }} <strong>{{ Auth::user()->role }}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Stats in 2-column grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <x-stat-card icon="fas fa-images" bgClass="bg-blue-500" :label="__('Total Assets')" :value="number_format($stats['total_assets'])" :link="route('assets.index')" linkClass="text-blue-600 hover:text-blue-800" />

                        <x-stat-card icon="fas fa-user" bgClass="bg-green-500" :label="__('My Assets')" :value="number_format($stats['my_assets'])" :link="route('assets.index', ['user' => Auth::id()])" linkClass="text-green-600 hover:text-green-800" />

                        <x-stat-card icon="fas fa-tags" bgClass="bg-purple-500" :label="__('Total Tags')" :value="number_format($stats['total_tags'])" :link="route('tags.index')" linkClass="text-purple-600 hover:text-purple-800">
                            <dd class="text-xs text-gray-500 mt-1">
                                {{ __(':count user', ['count' => number_format($stats['user_tags'])]) }} • {{ __(':count AI', ['count' => number_format($stats['ai_tags'])]) }}
                            </dd>
                        </x-stat-card>

                        <x-stat-card icon="fas fa-database" bgClass="bg-yellow-500" :label="__('Total Storage')" :value="$stats['total_storage']" />

                        @if(!$isAdmin)
                            <x-stat-card icon="fas fa-tag" bgClass="bg-teal-500" :label="__('My Tags')" :value="number_format($stats['my_tags'])" :link="route('tags.index')" linkClass="text-teal-600 hover:text-teal-800">
                                <dd class="text-xs text-gray-500 mt-1">
                                    {{ __(':count user', ['count' => number_format($stats['my_user_tags'])]) }} • {{ __(':count AI', ['count' => number_format($stats['my_ai_tags'])]) }}
                                </dd>
                            </x-stat-card>

                            <x-stat-card icon="fas fa-table-list" bgClass="bg-indigo-500" :label="__('Results per page')" :value="$stats['items_per_page']" :link="route('profile.edit')" linkClass="text-indigo-600 hover:text-indigo-800">
                                <dd class="text-xs text-gray-500 mt-1">
                                    {{ $stats['items_per_page_is_default'] ? __('Default') : __('Custom') }}
                                </dd>
                            </x-stat-card>
                        @endif

                        @if($isAdmin)
                            <x-stat-card icon="fas fa-users" bgClass="bg-red-500" :label="__('Total Users')" :value="number_format($stats['total_users'])" :link="route('users.index')" linkClass="text-red-600 hover:text-red-800" />

                            <x-stat-card icon="fas fa-trash" bgClass="bg-gray-500" :label="__('Trashed Assets')" :value="number_format($stats['trashed_assets'])" :link="route('assets.trash')" linkClass="text-gray-600 hover:text-gray-800" />
                        @endif
                    </div>
                </div>

                <!-- Right Column: Feature Tour -->
                <div class="flex flex-col">
                    <!-- <h2 class="text-2xl font-bold text-gray-900 mb-4">Feature Tour</h2>-->

                    <div x-data="featureTour(@js($isAdmin), @js($showPasskeyPromo))" class="bg-white overflow-hidden shadow-sm sm:rounded-lg flex-1 flex flex-col">
                        <div class="p-6 flex-1 flex flex-col justify-center">
                            <!-- Slideshow -->
                            <div class="relative min-h-[320px] flex items-center bg-white">
                                <template x-for="(feature, index) in features" :key="index">
                                    <div class="absolute inset-0 bg-white space-y-4 flex flex-col justify-center px-4"
                                         :style="{
                                             opacity: currentSlide === index || (isTransitioning && prevSlide === index) ? 1 : 0,
                                             filter: currentSlide === index ? 'blur(0px)' : 'blur(8px)',
                                             zIndex: currentSlide === index ? 20 : (isTransitioning && prevSlide === index ? 10 : 1),
                                             transition: 'opacity 500ms ease-in-out, filter 500ms ease-in-out',
                                             pointerEvents: currentSlide === index ? 'auto' : 'none'
                                         }">

                                        <!-- Icon -->
                                        <div class="flex justify-center">
                                            <div class="rounded-full p-4 w-20 h-20 text-center" :class="feature.bgColor">
                                                <i :class="feature.icon + ' text-white text-4xl leading-[1.4]'"></i>
                                            </div>
                                        </div>

                                        <!-- Title -->
                                        <h3 class="text-xl font-semibold text-gray-900 text-center" x-text="feature.title"></h3>

                                        <!-- Description -->
                                        <p class="text-gray-600 text-center" x-text="feature.description"></p>

                                        <!-- Action Button -->
                                        <div class="flex justify-center pt-4">
                                            <a :href="feature.link"
                                               class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white transition-colors shadow-sm hover:shadow-md"
                                               :class="feature.btnColor">
                                                <span x-text="feature.btnText"></span>
                                                <i class="fas fa-arrow-right ml-2"></i>
                                            </a>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Navigation -->
                            <div class="mt-8 flex items-center justify-between">
                                <button @click="previousSlide(); pauseAutoPlay()"
                                        class="p-3 w-12 h-12  rounded-full bg-white hover:bg-gray-100 transition-all shadow-sm hover:shadow-md">
                                    <i class="fas fa-chevron-left text-gray-700 w-4"></i>
                                </button>

                                <!-- Dots -->
                                <div class="flex space-x-2">
                                    <template x-for="(feature, index) in features" :key="index">
                                        <button @click="goToSlide(index); pauseAutoPlay()"
                                                class="w-2.5 h-2.5 rounded-full transition-all hover:scale-125"
                                                :class="currentSlide === index ? 'bg-blue-600 scale-110' : 'bg-gray-300 hover:bg-gray-400'">
                                        </button>
                                    </template>
                                </div>

                                <button @click="nextSlide(); pauseAutoPlay()"
                                        class="p-3 w-12 h-12 rounded-full bg-white hover:bg-gray-100 transition-all shadow-sm hover:shadow-md">
                                    <i class="fas fa-chevron-right text-gray-700 w-4"></i>
                                </button>
                            </div>

                            <!-- Slide Counter & Auto-play indicator -->
                            <div class="mt-4 flex items-center justify-center gap-4 text-sm text-gray-500">
                                <span>
                                    <span x-text="currentSlide + 1"></span> / <span x-text="features.length"></span>
                                </span>
                                <button @click="toggleAutoPlay()"
                                        class="text-gray-400 hover:text-gray-600 transition-colors"
                                        :title="isPlaying ? '{{ __('Pause auto-play') }}' : '{{ __('Resume auto-play') }}'">
                                    <i :class="isPlaying ? 'fas fa-pause' : 'fas fa-play'"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
    window.__pageData = {
        routes: {
            assetsCreate: '{{ route('assets.create') }}',
            assetsIndex: '{{ route('assets.index') }}',
            tagsIndex: '{{ route('tags.index') }}',
            discoverIndex: '{{ route('discover.index') }}',
            assetsTrash: '{{ route('assets.trash') }}',
            exportIndex: '{{ route('export.index') }}',
            usersIndex: '{{ route('users.index') }}',
            profileEdit: '{{ route('profile.edit') }}',
            systemIndex: '{{ route('system.index') }}',
            apiIndex: '{{ route('api.index') }}',
            importIndex: '{{ route('import.index') }}'
        },
        translations: {
            addPasskey: @js(__('Sign in with a Passkey')),
            addPasskeyDesc: @js(__('Skip passwords and two-factor codes. Use your fingerprint, face, or device PIN to sign in — phishing-resistant and faster.')),
            addPasskeyBtn: @js(__('Add Passkey')),
            uploadAssets: @js(__('Upload Assets')),
            uploadAssetsDesc: @js(__('Upload files up to 500MB with drag & drop. Large files are automatically chunked for reliable uploads.')),
            uploadNow: @js(__('Upload Now')),
            searchFilter: @js(__('Search & Filter')),
            searchFilterDesc: @js(__('Find assets quickly using search, tags, and file type filters. Save time with powerful filtering options.')),
            browseAssets: @js(__('Browse Assets')),
            smartTagging: @js(__('Smart Tagging')),
            smartTaggingDesc: @js(__('Organize with manual tags or let AI automatically tag your images using AWS Rekognition.')),
            manageTags: @js(__('Manage Tags')),
            shareAssets: @js(__('Share Assets')),
            shareAssetsDesc: @js(__('Copy public URLs instantly. All assets are accessible via permanent S3 URLs for easy integration.')),
            viewAssets: @js(__('View Assets')),
            discoverAssets: @js(__('Discover Assets')),
            discoverAssetsDesc: @js(__('Scan your S3 bucket for unmapped objects and import them with automatic metadata extraction.')),
            scanBucket: @js(__('Scan Bucket')),
            trashRestore: @js(__('Trash & Restore')),
            trashRestoreDesc: @js(__('Deleted assets are moved to trash. Restore them anytime or permanently delete to free up space.')),
            viewTrash: @js(__('View Trash')),
            exportData: @js(__('Export Data')),
            exportDataDesc: @js(__('Export asset metadata to CSV with separate columns for user and AI tags. Perfect for reporting.')),
            exportCsv: @js(__('Export CSV')),
            manageUsers: @js(__('Manage Users')),
            manageUsersDesc: @js(__('Add editors and admins. Control who can upload, edit, and manage assets in your organization.')),
            manageUsersBtn: @js(__('Manage Users')),
            profileSettings: @js(__('Profile Settings')),
            profileSettingsDesc: @js(__('Manage your preferences, password, two-factor authentication, and dark mode settings.')),
            editProfile: @js(__('Edit Profile')),
            systemAdministration: @js(__('System Administration')),
            systemAdminDesc: @js(__('Run diagnostics, configure system settings, monitor queues, and view application logs.')),
            systemAdminBtn: @js(__('System Admin')),
            apiSettingsTitle: @js(__('API Settings')),
            apiSettingsDesc: @js(__('Browse API documentation, manage authentication tokens, and configure JWT settings.')),
            apiSettingsBtn: @js(__('API Settings')),
            importMetadata: @js(__('Import Metadata')),
            importMetadataDesc: @js(__('Bulk import asset metadata from CSV files with preview matching and change diffs before applying.')),
            importData: @js(__('Import Data'))
        }
    };
    </script>
    @endpush
