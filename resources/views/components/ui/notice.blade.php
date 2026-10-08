@props(['tone' => 'info'])
<div class="mb-6 rounded-xl p-4 {{ ['success' => 'bg-ios-green-bg text-ios-green', 'warning' => 'bg-ios-amber-bg text-ios-amber', 'danger' => 'bg-red-50 text-ios-red', 'info' => 'border border-ios-separator bg-ios-card text-ios-label'][$tone] }}"
     role="{{ $tone === 'success' ? 'status' : 'alert' }}">
    {{ $slot }}
</div>
