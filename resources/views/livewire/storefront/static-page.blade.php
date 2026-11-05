{{-- Policy pages are a first draft based on how the platform works today; have them reviewed by a lawyer before launch. --}}
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <nav class="text-xs text-slate-500 mb-4">
        <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600">Home</a>
        <span class="mx-1">/</span>
        <span class="text-slate-800 font-semibold">{{ $title }}</span>
    </nav>

    <h1 class="text-2xl sm:text-3xl font-black text-slate-900 mb-6">{{ $title }}</h1>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 text-sm leading-relaxed text-slate-700 space-y-5 [&_h2]:text-base [&_h2]:font-extrabold [&_h2]:text-slate-900 [&_h2]:pt-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1 [&_a]:text-indigo-600 [&_a]:font-semibold hover:[&_a]:underline">
        @include('livewire.storefront.pages.'.$page)
    </div>

    <div class="mt-6 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
        @foreach (\App\Livewire\Storefront\StaticPage::PAGES as $pageKey => $pageTitle)
            @if ($pageKey !== $page)
                <a href="{{ route('pages.'.$pageKey) }}" class="hover:text-indigo-600">{{ $pageTitle }}</a>
            @endif
        @endforeach
    </div>
</div>
