<x-app-layout title="{{ __('Dashboard') }}">
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <div class="mb-6 text-center">
                <div class="flex items-center justify-center mb-6">
                    <div class="dashboard-logo bg-white rounded-full p-2 shadow-lg cursor-pointer"
                         onclick="const footerOrca = document.querySelector('.footer-logo-container svg'); if(footerOrca) { footerOrca.classList.add('orca-jump'); setTimeout(() => footerOrca.classList.remove('orca-jump'), 1100); }">
                        <x-application-logo class="h-48 w-48 fill-current text-gray-800" style="width: 6rem; height: 6rem;" />
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

                        <!-- Assets Stats -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                <div class="flex items-center h-full">
                                    <div class="flex-shrink-0 bg-blue-500 rounded-md p-3 w-14 text-center">
                                        <i class="fas fa-images text-white text-2xl"></i>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Total Assets') }}</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['total_assets']) }}</dd>
                                        </dl>
                                    </div>
                                    <a href="{{ route('assets.index') }}" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- My Assets -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                <div class="flex items-center h-full">
                                    <div class="flex-shrink-0 bg-green-500 rounded-md p-3 w-14 text-center">
                                        <i class="fas fa-user text-white text-2xl"></i>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('My Assets') }}</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['my_assets']) }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tags Stats -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                <div class="flex items-center h-full">
                                    <div class="flex-shrink-0 bg-purple-500 rounded-md p-3 w-14 text-center">
                                        <i class="fas fa-tags text-white text-2xl"></i>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Total Tags') }}</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['total_tags']) }}</dd>
                                            <dd class="text-xs text-gray-500 mt-1">
                                                {{ __(':count user', ['count' => number_format($stats['user_tags'])]) }} • {{ __(':count AI', ['count' => number_format($stats['ai_tags'])]) }}
                                            </dd>
                                        </dl>
                                    </div>
                                    <a href="{{ route('tags.index') }}" class="text-purple-600 hover:text-purple-800">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Storage Stats -->
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                <div class="flex items-center h-full">
                                    <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3 w-14 text-center">
                                        <i class="fas fa-database text-white text-2xl"></i>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Total Storage') }}</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ $stats['total_storage'] }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if(!$isAdmin)
                            <!-- My Tags -->
                            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div class="p-6 h-full">
                                    <div class="flex items-center h-full">
                                        <div class="flex-shrink-0 bg-teal-500 rounded-md p-3 w-14 text-center">
                                            <i class="fas fa-tag text-white text-2xl"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('My Tags') }}</dt>
                                                <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['my_tags']) }}</dd>
                                                <dd class="text-xs text-gray-500 mt-1">
                                                    {{ __(':count user', ['count' => number_format($stats['my_user_tags'])]) }} • {{ __(':count AI', ['count' => number_format($stats['my_ai_tags'])]) }}
                                                </dd>
                                            </dl>
                                        </div>
                                        <a href="{{ route('tags.index') }}" class="text-teal-600 hover:text-teal-800">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Results per page -->
                            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div class="p-6 h-full">
                                    <div class="flex items-center h-full">
                                        <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3 w-14 text-center">
                                            <i class="fas fa-table-list text-white text-2xl"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Results per page') }}</dt>
                                                <dd class="text-3xl font-semibold text-gray-900">{{ $stats['items_per_page'] }}</dd>
                                                <dd class="text-xs text-gray-500 mt-1">
                                                    {{ $stats['items_per_page_is_default'] ? __('Default') : __('Custom') }}
                                                </dd>
                                            </dl>
                                        </div>
                                        <a href="{{ route('profile.edit') }}" class="text-indigo-600 hover:text-indigo-800">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($isAdmin)
                            <!-- Admin Stats -->
                            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div class="p-6 h-full">
                                    <div class="flex items-center h-full">
                                        <div class="flex-shrink-0 bg-red-500 rounded-md p-3 w-14 text-center">
                                            <i class="fas fa-users text-white text-2xl"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Total Users') }}</dt>
                                                <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['total_users']) }}</dd>
                                            </dl>
                                        </div>
                                        <a href="{{ route('users.index') }}" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div class="p-6 h-full">
                                    <div class="flex items-center h-full">
                                        <div class="flex-shrink-0 bg-gray-500 rounded-md p-3 w-14 text-center">
                                            <i class="fas fa-trash text-white text-2xl"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('Trashed Assets') }}</dt>
                                                <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['trashed_assets']) }}</dd>
                                            </dl>
                                        </div>
                                        <a href="{{ route('assets.trash') }}" class="text-gray-600 hover:text-gray-800">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Right Column: Feature Tour -->
                <div class="flex flex-col">
                    <!-- <h2 class="text-2xl font-bold text-gray-900 mb-4">Feature Tour</h2>-->

                    <div x-data="featureTour(@js($isAdmin))" class="bg-white overflow-hidden shadow-sm sm:rounded-lg flex-1 flex flex-col">
                        <div class="p-6 flex-1 flex flex-col justify-center">
                            <!-- Slideshow -->
                            <div class="relative min-h-[320px] flex items-center bg-white">
                                <template x-for="(feature, index) in features" :key="index">
                                    <div class="absolute inset-0 bg-white space-y-4 flex flex-col justify-center px-4"
                                         :style="{
                                             opacity: currentSlide === index || (isTransitioning && prevSlide === index) ? 1 : 0,
                                             zIndex: currentSlide === index ? 20 : (isTransitioning && prevSlide === index ? 10 : 1),
                                             transition: currentSlide === index ? 'opacity 500ms ease-in-out' : 'none',
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
            usersIndex: '{{ route('users.index') }}'
        },
        translations: {
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
            manageUsersBtn: @js(__('Manage Users'))
        }
    };
    </script>
    @endpush
</x-app-layout>
