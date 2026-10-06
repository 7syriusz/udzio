@php($unit = $node['unit'])
@php($can = $abilities[$unit->id])
<li class="py-2">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('organizations.show', $unit->public_id) }}" class="font-medium text-blue-700 underline">{{ $unit->name }}</a>
        @if ($can['archive'])
            <a href="{{ route('organizations.archive.confirm', $unit->public_id) }}" class="text-sm text-red-700 underline">{{ __('organization.screens.archive') }}</a>
        @endif
    </div>

    @if ($can['create_unit'] || $can['rename'] || $can['move_to'] !== [])
        <details class="mt-1 text-sm">
            <summary class="cursor-pointer text-gray-700">{{ __('organization.screens.structure') }}: {{ $unit->name }}</summary>
            @if ($can['create_unit'])
                <form method="POST" action="{{ route('organizations.units.store', $unit->public_id) }}" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="flex flex-col">{{ __('organization.screens.unit_name') }}<input name="name" required class="rounded border px-2 py-1"></label>
                    <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="rounded border px-2 py-1"></label>
                    <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.add_unit') }}</button>
                </form>
            @endif
            @if ($can['rename'])
                <form method="POST" action="{{ route('organizations.rename', $unit->public_id) }}" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    @method('PUT')
                    <label class="flex flex-col">{{ __('organization.screens.new_name') }}<input name="name" required value="{{ $unit->name }}" class="rounded border px-2 py-1"></label>
                    <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="rounded border px-2 py-1"></label>
                    <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.rename') }}</button>
                </form>
            @endif
            @if ($can['move_to'] !== [])
                <form method="POST" action="{{ route('organizations.move', $unit->public_id) }}" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    @method('PUT')
                    <label class="flex flex-col">{{ __('organization.screens.move') }}
                        <select name="parent" class="rounded border px-2 py-1">
                            @foreach ($can['move_to'] as $target)
                                <option value="{{ $target->public_id }}">{{ $target->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="rounded border px-2 py-1"></label>
                    <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.move') }}</button>
                </form>
            @endif
        </details>
    @endif

    @if ($node['children'] !== [])
        <ul class="ml-6 border-l pl-4">
            @foreach ($node['children'] as $child)
                @include('organizations._node', ['node' => $child, 'abilities' => $abilities])
            @endforeach
        </ul>
    @endif
</li>
