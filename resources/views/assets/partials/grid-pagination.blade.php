    <!-- Pagination -->
    <div class="mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-2">
            <label for="perPageSelect" class="hidden lg:block text-sm text-gray-600">{{ __('Results per page:') }}</label>
            <select id="perPageSelect"
                    x-model="perPage"
                    @change="applyFilters()"
                    class="text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                <option value="12">12</option>
                <option value="24">24</option>
                <option value="36">36</option>
                <option value="48">48</option>
                <option value="60">60</option>
                <option value="72">72</option>
                <option value="96">96</option>
            </select>
        </div>
        <div>
            {{ $assets->links() }}
        </div>
    </div>
