@php($unit = $node['unit'])
@php($can = $abilities[$unit->id])
<li class="py-2">
    <a href="{{ route('organizations.show', $unit->public_id) }}" class="font-medium break-words text-blue-700 underline">{{ $unit->name }}</a>

    @if ($can['create_unit'] || $can['rename'] || $can['move_to'] !== [] || $can['archive'])
        <details class="mt-1 text-sm">
            <summary class="cursor-pointer text-gray-700" aria-label="{{ __('organization.screens.manage_unit', ['name' => $unit->name]) }}">{{ __('organization.screens.manage') }}</summary>
            <div class="mt-2 space-y-3 rounded border border-gray-200 bg-gray-50 p-3">
                @if ($can['create_unit'])
                    <form method="POST" action="{{ route('organizations.units.store', $unit->public_id) }}" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                        @csrf
                        <label class="flex flex-col">{{ __('organization.screens.unit_name') }}<input name="name" required class="w-full rounded border px-2 py-1 sm:w-56"></label>
                        <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="w-full rounded border px-2 py-1 sm:w-56"></label>
                        <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.add_unit') }}</button>
                    </form>
                @endif
                @if ($can['rename'])
                    <form method="POST" action="{{ route('organizations.rename', $unit->public_id) }}" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                        @csrf
                        @method('PUT')
                        <label class="flex flex-col">{{ __('organization.screens.new_name') }}<input name="name" required value="{{ $unit->name }}" class="w-full rounded border px-2 py-1 sm:w-56"></label>
                        <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="w-full rounded border px-2 py-1 sm:w-56"></label>
                        <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.rename') }}</button>
                    </form>
                @endif
                @if ($can['move_to'] !== [])
                    <form method="POST" action="{{ route('organizations.move', $unit->public_id) }}" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                        @csrf
                        @method('PUT')
                        <label class="flex min-w-0 flex-col">{{ __('organization.screens.move') }}
                            <select name="parent" class="w-full max-w-full truncate rounded border px-2 py-1 sm:w-56">
                                @foreach ($can['move_to'] as $target)
                                    <option value="{{ $target->public_id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col">{{ __('organization.screens.reason') }}<input name="reason" required class="w-full rounded border px-2 py-1 sm:w-56"></label>
                        <button class="rounded bg-blue-700 px-3 py-1 text-white">{{ __('organization.screens.move') }}</button>
                    </form>
                @endif
                @if ($can['archive'])
                    <a href="{{ route('organizations.archive.confirm', $unit->public_id) }}" class="inline-block text-red-700 underline">{{ __('organization.screens.archive') }}</a>
                @endif
            </div>
        </details>
    @endif

    @if ($node['children'] !== [])
        <ul class="ml-1 border-l pl-3 sm:ml-6 sm:pl-4">
            @foreach ($node['children'] as $child)
                @include('organizations._node', ['node' => $child, 'abilities' => $abilities])
            @endforeach
        </ul>
    @endif
</li>
