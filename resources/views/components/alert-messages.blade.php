@if(session('success'))
<div class="attention mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>{{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="attention mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}
</div>
@endif

@if(session('warning'))
<div class="attention mb-6 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg">
    <i class="fas fa-exclamation-triangle mr-2"></i>{{ session('warning') }}
</div>
@endif
