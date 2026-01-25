<x-app-layout title="Dashboard">
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <div class="mb-8 text-center">
                <div class="flex items-center justify-center mb-6">
                    <div class="bg-white rounded-full p-2 shadow-lg cursor-pointer"
                         onclick="const footerOrca = document.querySelector('.footer-logo-container svg'); if(footerOrca) { footerOrca.classList.add('orca-jump'); setTimeout(() => footerOrca.classList.remove('orca-jump'), 1100); }">
                        <x-application-logo class="h-48 w-48 fill-current text-gray-800" style="width: 8rem; height: 8rem;" />
                    </div>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 mb-3">ORCA DAM</h1>
                <p class="text-xl text-gray-600 pb-8"><span>ORCA</span> <span>Retrieves</span> <span>Cloud</span> <span>Assets</span></p>
            </div>

            <!-- Two Column Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                <!-- Left Column: Statistics -->
                <div>
                    <!--<h2 class="text-2xl font-bold text-gray-900 mb-4">Statistics</h2>-->
                    <div class="grid grid-cols-1 gap-4 mb-6">
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 h-full">
                                Hi <strong>{{ Auth::user()->name }}</strong>!<br />
                                You're logged in with <strong>{{ Auth::user()->email }}</strong>
                                as an <strong>{{ Auth::user()->role }}</strong>
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
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Assets</dt>
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
                                            <dt class="text-sm font-medium text-gray-500 truncate">My Assets</dt>
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
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Tags</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ number_format($stats['total_tags']) }}</dd>
                                            <dd class="text-xs text-gray-500 mt-1">
                                                {{ number_format($stats['user_tags']) }} user • {{ number_format($stats['ai_tags']) }} AI
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
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Storage</dt>
                                            <dd class="text-3xl font-semibold text-gray-900">{{ $stats['total_storage'] }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

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
                                                <dt class="text-sm font-medium text-gray-500 truncate">Total Users</dt>
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
                                                <dt class="text-sm font-medium text-gray-500 truncate">Trashed Assets</dt>
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
                <div>
                    <!-- <h2 class="text-2xl font-bold text-gray-900 mb-4">Feature Tour</h2>-->

                    <div x-data="featureTour(@js($isAdmin))" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <!-- Slideshow -->
                            <div class="relative min-h-[320px] flex items-center bg-white">
                                <template x-for="(feature, index) in features" :key="index">
                                    <div x-show="currentSlide === index"
                                         x-cloak
                                         x-transition:enter="transition-opacity ease-in-out duration-600"
                                         x-transition:enter-start="opacity-0"
                                         x-transition:enter-end="opacity-100"
                                         x-transition:leave="transition-opacity ease-in-out duration-600"
                                         x-transition:leave-start="opacity-100"
                                         x-transition:leave-end="opacity-0"
                                         class="absolute inset-0 bg-white space-y-4 flex flex-col justify-center px-4"
                                         :style="'z-index: ' + (currentSlide === index ? 2 : 1)">

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
                                        class="p-3 rounded-full bg-white hover:bg-gray-100 transition-all shadow-sm hover:shadow-md">
                                    <i class="fas fa-chevron-left text-gray-700 w-4"></i>
                                </button>

                                <!-- Dots -->
                                <div class="flex space-x-2">
                                    <template x-for="(feature, index) in features" :key="index">
                                        <button @click="currentSlide = index; pauseAutoPlay()"
                                                class="w-2.5 h-2.5 rounded-full transition-all hover:scale-125"
                                                :class="currentSlide === index ? 'bg-blue-600 scale-110' : 'bg-gray-300 hover:bg-gray-400'">
                                        </button>
                                    </template>
                                </div>

                                <button @click="nextSlide(); pauseAutoPlay()"
                                        class="p-3 rounded-full bg-white hover:bg-gray-100 transition-all shadow-sm hover:shadow-md">
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
                                        :title="isPlaying ? 'Pause auto-play' : 'Resume auto-play'">
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
    function featureTour(isAdmin) {
        return {
            currentSlide: 0,
            isPlaying: true,
            autoPlayInterval: null,
            features: [
                {
                    icon: 'fas fa-cloud-upload-alt',
                    bgColor: 'bg-blue-500',
                    btnColor: 'bg-blue-600 hover:bg-blue-700',
                    title: 'Upload Assets',
                    description: 'Upload files up to 500MB with drag & drop. Large files are automatically chunked for reliable uploads.',
                    link: '{{ route('assets.create') }}',
                    btnText: 'Upload Now'
                },
                {
                    icon: 'fas fa-search',
                    bgColor: 'bg-green-500',
                    btnColor: 'bg-green-600 hover:bg-green-700',
                    title: 'Search & Filter',
                    description: 'Find assets quickly using search, tags, and file type filters. Save time with powerful filtering options.',
                    link: '{{ route('assets.index') }}',
                    btnText: 'Browse Assets'
                },
                {
                    icon: 'fas fa-tags',
                    bgColor: 'bg-purple-500',
                    btnColor: 'bg-purple-600 hover:bg-purple-700',
                    title: 'Smart Tagging',
                    description: 'Organize with manual tags or let AI automatically tag your images using AWS Rekognition.',
                    link: '{{ route('tags.index') }}',
                    btnText: 'Manage Tags'
                },
                {
                    icon: 'fas fa-copy',
                    bgColor: 'bg-yellow-500',
                    btnColor: 'bg-yellow-600 hover:bg-yellow-700',
                    title: 'Share Assets',
                    description: 'Copy public URLs instantly. All assets are accessible via permanent S3 URLs for easy integration.',
                    link: '{{ route('assets.index') }}',
                    btnText: 'View Assets'
                },
                ...(isAdmin ? [
                    {
                        icon: 'fas fa-search-plus',
                        bgColor: 'bg-indigo-500',
                        btnColor: 'bg-indigo-600 hover:bg-indigo-700',
                        title: 'Discover Assets',
                        description: 'Scan your S3 bucket for unmapped objects and import them with automatic metadata extraction.',
                        link: '{{ route('discover.index') }}',
                        btnText: 'Scan Bucket'
                    },
                    {
                        icon: 'fas fa-trash-restore',
                        bgColor: 'bg-red-500',
                        btnColor: 'bg-red-600 hover:bg-red-700',
                        title: 'Trash & Restore',
                        description: 'Deleted assets are moved to trash. Restore them anytime or permanently delete to free up space.',
                        link: '{{ route('assets.trash') }}',
                        btnText: 'View Trash'
                    },
                    {
                        icon: 'fas fa-download',
                        bgColor: 'bg-teal-500',
                        btnColor: 'bg-teal-600 hover:bg-teal-700',
                        title: 'Export Data',
                        description: 'Export asset metadata to CSV with separate columns for user and AI tags. Perfect for reporting.',
                        link: '{{ route('export.index') }}',
                        btnText: 'Export CSV'
                    },
                    {
                        icon: 'fas fa-users',
                        bgColor: 'bg-pink-500',
                        btnColor: 'bg-pink-600 hover:bg-pink-700',
                        title: 'Manage Users',
                        description: 'Add editors and admins. Control who can upload, edit, and manage assets in your organization.',
                        link: '{{ route('users.index') }}',
                        btnText: 'Manage Users'
                    }
                ] : [])
            ],

            nextSlide() {
                if (this.currentSlide < this.features.length - 1) {
                    this.currentSlide++;
                } else {
                    this.currentSlide = 0; // Loop back to start
                }
            },

            previousSlide() {
                if (this.currentSlide > 0) {
                    this.currentSlide--;
                } else {
                    this.currentSlide = this.features.length - 1; // Loop to end
                }
            },

            pauseAutoPlay() {
                this.isPlaying = false;
                if (this.autoPlayInterval) {
                    clearInterval(this.autoPlayInterval);
                    this.autoPlayInterval = null;
                }
            },

            startAutoPlay() {
                this.isPlaying = true;
                if (!this.autoPlayInterval) {
                    this.autoPlayInterval = setInterval(() => {
                        this.nextSlide();
                    }, 7000);
                }
            },

            toggleAutoPlay() {
                if (this.isPlaying) {
                    this.pauseAutoPlay();
                } else {
                    this.startAutoPlay();
                }
            },

            init() {
                // Start auto-play
                this.startAutoPlay();
            }
        }
    }
    </script>
    @endpush
</x-app-layout>
