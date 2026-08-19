<div class="w-6 h-6 rounded flex items-center justify-center text-center"
     style="background-color: {{ \App\Support\Colors::safe($type->color) }};"
     title="{{ $type->name }}">
    <x-icon class="h-3 text-white" name="{{ $type->icon }}" />
</div>
